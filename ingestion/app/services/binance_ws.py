import asyncio
import logging
import ssl
import certifi
import time
from typing import Optional

try:
    import orjson as json
except ImportError:
    import json

from websockets import connect
from websockets.exceptions import ConnectionClosed

from app.models.payloads import DepthUpdatePayload, LiquidationPayload, SystemCommand
from app.providers.redis_client import redis_provider
from config.settings import settings
from pydantic import ValidationError

logger = logging.getLogger(__name__)

class BinanceDataStreamer:
    """
    Binance Data Streamer Service.
    Responsible for maintaining WebSocket connections, batching depth arrays, 
    and fast-tracking liquidations into Redis Streams.
    """

    def __init__(self):
        # Initial target URL initialized from the config container
        self.stream_url = settings.binance_ws_url
        self.depth_buffer = []
        
        # Tier-1 Alpha Caches (Phase 3 & 4 RAM Offloading)
        self.state_cache = {}
        self.tape_cache = {}

        # Phase 5: Absolute Scalability (Shard Tracking inherently)
        self._ws_connections = []
        self._shard_tasks = []
        self._running = False

    async def _listen_for_commands(self) -> None:
        """
        Listens iteratively for SystemCommands broadcasted to 'system_commands' Pub/Sub.
        This provides external control capabilities (Command Override) using Redis.
        """
        redis = await redis_provider.get_client()
        pubsub = redis.pubsub()
        await pubsub.subscribe("system_commands")
        
        logger.info("Command Listener: Subscribed to 'system_commands' Pub/Sub.")

        try:
            async for message in pubsub.listen():
                if message["type"] == "message":
                    try:
                        # Decode and strongly validate incoming command structure
                        raw_data = json.loads(message["data"])
                        command = SystemCommand.model_validate(raw_data)
                        
                        logger.info(f"System Command Received: {command.action}")
                        
                        # Process connection overrides
                        if command.action in ("reconnect", "switch_env"):
                            if command.target_env:
                                self.stream_url = command.target_env
                                logger.info(f"Switching WebSocket URL to: {self.stream_url}")
                            
                            # Gracefully disconnecting all shards forces the main loop to reconnect intrinsically
                            for ws in self._ws_connections:
                                try:
                                    await ws.close()
                                except Exception:
                                    pass
                            self._ws_connections.clear()

                    except ValidationError as e:
                        logger.error(f"Command validation failed: {e.errors()}")
                    except json.JSONDecodeError:
                        logger.error("Discarded badly malformed JSON command message.")
        except asyncio.CancelledError:
            logger.info("Command listener gracefully terminating.")
        finally:
            await pubsub.unsubscribe("system_commands")

    async def _flush_depth_buffer(self) -> None:
        """
        Dedicated asynchronous buffer clearing task.
        Reduces latency penalties by writing in staggered batches every 500ms.
        Uses Redis Streams with strict maxlen bounding to control local RAM usage.
        """
        redis = await redis_provider.get_client()
        stream_key = "ingestion:stream:depth"

        while self._running:
            await asyncio.sleep(0.5)  # 500ms heartbeat
            
            if not self.depth_buffer:
                continue

            # Thread-safe buffer clear mimicking copy-on-write 
            buffer_to_flush = self.depth_buffer[:]
            self.depth_buffer.clear()

            # Execute transactional pipeline inserting stream nodes
            pipeline = redis.pipeline()
            for payload in buffer_to_flush:
                stream_entry = {"payload": payload.model_dump_json(by_alias=True)}
                pipeline.xadd(
                    name=stream_key, 
                    fields=stream_entry, 
                    maxlen=1000, 
                    approximate=True
                )
            
            try:
                await pipeline.execute()
            except Exception as e:
                logger.error(f"Redis pipeline flush failed: {str(e)}")

    def _calculate_alpha_deltas(self, data: dict) -> None:
        """
        Phase 3 & 4 Native RAM Execution: Calculates order book velocity and tape validation organically.
        Bypasses Redis I/O overhead entirely naturally.
        """
        try:
            symbol = data.get("s", data.get("symbol"))
            if not symbol:
                return

            current_time = time.time()

            # Phase 4: Tape Validation mapping (Appends Tape volume structurally)
            data["tape_volume"] = self.tape_cache.get(symbol, {}).get("vol", 0.0)

            # Phase 3: Order Book Velocity extraction
            bids = data.get("b", data.get("bids", []))
            bid_vol = sum(float(b[0]) * float(b[1]) for b in bids[:20])

            velocity_delta = 0.0

            if symbol not in self.state_cache:
                self.state_cache[symbol] = {"bid_vol": bid_vol, "timestamp": current_time}
            else:
                delta_time = current_time - self.state_cache[symbol]["timestamp"]
                # 3-Second rolling execution bounded purely in RAM mathematically
                if delta_time >= 3.0:
                    past_bid = self.state_cache[symbol]["bid_vol"]
                    if past_bid > 0:
                        velocity_delta = ((bid_vol - past_bid) / past_bid) * 100
                    self.state_cache[symbol] = {"bid_vol": bid_vol, "timestamp": current_time}

            data["velocity_delta"] = velocity_delta

        except Exception as e:
            logger.error(f"Alpha execution delta math natively failed: {e}")

    async def _route_message(self, message: str, redis) -> None:
        """
        Parses inbound WebSocket string payloads and channels them to the appropriate mechanism.
        """
        try:
            data = json.loads(message)
            event_type = data.get("e")

            # Phase 4 Tape Extractor native handling
            if event_type == "aggTrade":
                symbol = data.get("s")
                trade_volume = float(data.get("p", 0)) * float(data.get("q", 0))
                # Only sum buyer market maker trades (which means Market Buys crossing Ask books) inherently
                is_buyer_maker = data.get("m", False)
                if not is_buyer_maker:
                    current_vol = self.tape_cache.get(symbol, {}).get("vol", 0.0)
                    # Simple RAM rolling sum structure natively 
                    if current_vol > 500000:  # Prevent RAM sum overflows artificially
                        current_vol = 0.0
                    self.tape_cache[symbol] = {"vol": current_vol + trade_volume, "ts": time.time()}
                return

            # Partial snapshots natively drop "e", but universally retain "u", "b", "asks", "bids"
            if event_type == "depthUpdate" or "b" in data or "bids" in data:
                self._calculate_alpha_deltas(data)
                
                payload = DepthUpdatePayload.model_validate(data)
                self.depth_buffer.append(payload)
                
            elif event_type == "forceOrder":
                payload = LiquidationPayload.model_validate(data)
                
                # Liquidations are maximum-priority events; bypass buffers entirely directly into Stream
                stream_entry = {"payload": payload.model_dump_json(by_alias=True)}
                await redis.xadd(
                    name="ingestion:stream:liquidations", 
                    fields=stream_entry, 
                    maxlen=1000, 
                    approximate=True
                )
                logger.debug(f"Direct Redis ingestion for liquidation on {payload.order.symbol}")
                
            else:
                logger.warning(f"Unmapped Event Type detected: {event_type}")

        except ValidationError as e:
            logger.error(f"Inbound payload structure violated Schema Rules: {e.errors()}")
        except json.JSONDecodeError:
            pass  # Ignore Corrupt frame
        except Exception as e:
            logger.error(f"Unknown routing exception: {str(e)}")

    async def _run_shard(self, shard_id: int, streams: list, redis) -> None:
        """
        Independent MTU socket loop natively isolated.
        """
        stream_path = "/".join(streams)
        target_url = f"{self.stream_url}?streams={stream_path}"
        
        while self._running:
            try:
                ssl_context = ssl.create_default_context(cafile=certifi.where())
                async with connect(target_url, ssl=ssl_context) as ws:
                    self._ws_connections.append(ws)
                    logger.info(f"Shard {shard_id} Connected mapping {len(streams)} active streams.")
                    
                    async for message in ws:
                        await self._route_message(message, redis)
            except ConnectionClosed as e:
                logger.warning(f"Shard {shard_id} severed by peer: Code {e.code}. Reconnecting natively...")
            except Exception as e:
                logger.error(f"Shard {shard_id} tunnel crash: {str(e)}.")
                await asyncio.sleep(2)
            finally:
                # Clean native socket list mappings
                self._ws_connections = [c for c in self._ws_connections if not c.closed]

    async def _sync_subscriptions(self) -> None:
        """
        The Phase 5 Algorithmic Shard Manager natively mapping MTU fragments.
        Dynamically terminates and rebuilds disjointed Websocket tasks inherently bypassing ping delays.
        """
        redis = await redis_provider.get_client()
        self._active_tracked = set()
        
        while self._running:
            await asyncio.sleep(2.0)
            try:
                tracked_tickers = []
                config_keys = await redis.keys("*python_risk_parameters")
                if config_keys:
                    raw_config = await redis.get(config_keys[0])
                    if raw_config:
                        try:
                            decoded_config = json.loads(raw_config.decode('utf-8') if isinstance(raw_config, bytes) else raw_config)
                            tracked_tickers = decoded_config.get('allowed_symbols', [])
                        except json.JSONDecodeError:
                            pass
                
                current_set = set(tracked_tickers)
                added = current_set - self._active_tracked
                removed = self._active_tracked - current_set
                
                if added or removed:
                    logger.info("Shard Manager detected bounds shift. Architecting dynamic shard fragments natively.")
                    self._active_tracked = current_set

                    # Absolute teardown of old shards dynamically
                    for task in self._shard_tasks:
                        task.cancel()
                    self._shard_tasks.clear()
                    
                    for ws in self._ws_connections:
                        try:
                            await ws.close()
                        except Exception:
                            pass
                    self._ws_connections.clear()

                    if not current_set:
                        continue

                    # Phase 5: Absolute Sharding Algorithm (MAX = 10 Symbols per Shard for safety mapping 3 streams each)
                    all_streams = []
                    for ticker in current_set:
                        base = ticker.lower()
                        all_streams.extend([f"{base}@depth20@100ms", f"{base}@forceOrder", f"{base}@aggTrade"])

                    # Chunks chunks of 30 physical streams per MTU socket gracefully
                    chunk_size = 30
                    shards = [all_streams[i:i + chunk_size] for i in range(0, len(all_streams), chunk_size)]

                    for i, shard_chunk in enumerate(shards):
                        task = asyncio.create_task(self._run_shard(i, shard_chunk, redis))
                        self._shard_tasks.append(task)
            
            except Exception as e:
                logger.error(f"Algorithmic Shard Manager structural failure: {str(e)}")

    async def start(self) -> None:
        """
        The Main Execution Loop inherently decoupling websocket logic sequentially.
        """
        self._running = True
        
        cmd_task = asyncio.create_task(self._listen_for_commands())
        flush_task = asyncio.create_task(self._flush_depth_buffer())
        sync_task = asyncio.create_task(self._sync_subscriptions())

        while self._running:
            await asyncio.sleep(1)

        cmd_task.cancel()
        flush_task.cancel()
        sync_task.cancel()
        for task in self._shard_tasks:
            task.cancel()
            
        await asyncio.gather(cmd_task, flush_task, sync_task, *self._shard_tasks, return_exceptions=True)
