import asyncio
import logging
import json
import httpx
import re
from typing import Dict, Any

from app.providers.redis_client import redis_provider

logger = logging.getLogger(__name__)

class OllamaIntelligenceEngine:
    """
    Module 2: The Intelligence Worker.
    Continuously drains the ingestion news stream. Hits local Ollama LLM with a strict 
    timeout constraint to calculate real-time sentiment without blocking.
    """

    def __init__(self):
        self._running = False
        self.ollama_url = "http://localhost:11434/api/generate"
        self.stream_in = "ingestion:stream:news"
        self.stream_out = "processed:stream:signals"
        
        # Redis Consumer Group Identifiers
        self.consumer_group = "intelligence_group"
        self.consumer_name = "ollama_worker_1"

    async def _process_headline(self, article: Dict[str, Any], client: httpx.AsyncClient) -> tuple[float, str]:
        """
        Queries local Ollama. Enforces a ruthless timeout policy to prevent System Hangs.
        Returns a tuple mapping (sentiment_score, symbol).
        """
        title = article.get("title", "")
        # Prompt engineered strictly for structured JSON mapping
        payload = {
            "model": "qwen2.5:7b",
            "prompt": f"You are a quant module. Analyze the sentiment of this crypto headline and extract the primary Binance Futures ticker symbol (e.g. BTCUSDT, ETHUSDT, SOLUSDT). Return strictly ONLY a JSON object: {{\"sentiment\": <float between -1.0 to 1.0>, \"symbol\": \"<string>\"}} (No text, no explanation): '{title}'",
            "stream": False,
            "format": "json" # Force Ollama natively into JSON mode
        }

        # The Fail-Safe: Strict 2.0s asyncio timeout window
        try:
            response = await asyncio.wait_for(client.post(self.ollama_url, json=payload), timeout=2.0)
            response.raise_for_status()
            data = response.json()
                
            raw_response = data.get("response", "").strip()
            try:
                # Force strictly mapped outputs
                result = json.loads(raw_response)
                score = float(result.get("sentiment", 0.0))
                symbol = str(result.get("symbol", "")).upper()
                return max(-1.0, min(1.0, score)), symbol
            except (ValueError, json.JSONDecodeError):
                logger.warning(f"Ollama ignored JSON rules and hallucinated unstructured text: {raw_response}")
                return 0.0, ""

        except TimeoutError:
            # CRITICAL EDGE CASE DETECTED: Silent Brain Death.
            logger.critical(f"CRITICAL: Ollama execution timeout (Silent Brain Death) on event: '{title}'. Reverting to safe default (0.0) without crashing.")
            return 0.0, ""
            
        except httpx.HTTPError as e:
            logger.error(f"Local AI instance disconnected or inaccessible: {e}")
            return 0.0, ""
            
        except Exception as e:
            logger.error(f"Internal generation exception caught: {e}")
            return 0.0, ""

    async def start(self) -> None:
        """
        The continuous AI ingestion loop.
        Drains Redis -> Hits Ollama locally -> Repopulates Downstream.
        """
        self._running = True
        redis = await redis_provider.get_client()

        # Initialize the Consumer Group safely, ignore if it already exists
        try:
            await redis.xgroup_create(self.stream_in, self.consumer_group, id="$", mkstream=True)
        except Exception:
            pass 

        # Keep alive connection spanning the continuous evaluation loop
        async with httpx.AsyncClient() as client:
            while self._running:
                try:
                    # Dynamically ping Redis for live UI Configurations (No downtime reboots required!)
                    # Resolve Laravel's dynamic connection string prefix natively by trailing wildcard match
                    allowed_symbols = []
                    config_keys = await redis.keys("*python_risk_parameters")
                    if config_keys:
                        raw_config = await redis.get(config_keys[0])
                        if raw_config:
                            try:
                                decoded_config = json.loads(raw_config.decode('utf-8') if isinstance(raw_config, bytes) else raw_config)
                                allowed_symbols = decoded_config.get('allowed_symbols', [])
                            except json.JSONDecodeError:
                                pass

                    # Construct Auto-Discovery Reverse Projection (0ms latency, pure memory evaluation)
                    futures_map = {}
                    for sym in allowed_symbols:
                        # Tier 1: Identity Map (Safeguard)
                        futures_map[sym] = sym
                        
                        # Tier 2: Multiplier Stripped (e.g. PEPEUSDT -> 1000PEPEUSDT)
                        stripped = re.sub(r'^(1000000|100000|10000|1000)', '', sym)
                        if stripped != sym:
                            futures_map[stripped] = sym
                            
                        # Tier 3: True Base Truncation (e.g. PEPE -> 1000PEPEUSDT)
                        base_only = re.sub(r'USDT$', '', stripped)
                        if base_only != stripped:
                            futures_map[base_only] = sym

                    # Non-blocking poll holding for 1s 
                    messages = await redis.xreadgroup(
                        groupname=self.consumer_group,
                        consumername=self.consumer_name,
                        streams={self.stream_in: ">"},
                        count=5, # Evaluate max 5 concurrent news bytes at a time
                        block=1000 
                    )

                    if not messages:
                        continue

                    # Stream layout: [[stream_queue_name, [(redis_id, msg_data), ...]]]
                    for stream_name, msg_list in messages:
                        pipeline = redis.pipeline()
                        for msg_id, msg_data in msg_list:
                            try:
                                # Safe native decode from byte keys
                                raw_payload = msg_data.get(b"payload") or msg_data.get("payload")
                                if raw_payload:
                                    article = json.loads(raw_payload)
                                    
                                    # Execute robust sentiment scoring 
                                    score, symbol = await self._process_headline(article, client)
                                    
                                    # Auto-Discovery Routing: Translate Hallucination or Extracted Spot to Futures Matrix Native
                                    symbol = futures_map.get(symbol, symbol)
                                    
                                    # WHITELIST GUARD: Suppress hallucinations and untracked asset routing
                                    if not symbol or symbol not in allowed_symbols:
                                        logger.warning(f"Engine Guard blocked untracked/hallucinated ticker '{symbol}' from pipeline. Allowed active configurations: {allowed_symbols}")
                                        pipeline.xack(self.stream_in, self.consumer_group, msg_id)
                                        continue
                                    
                                    article["sentiment_score"] = score
                                    article["symbol"] = symbol
                                    
                                    logger.info(f"SUCCESS [Engine Guard Cleared]: Vetted '{symbol}' (Score: {score}). Funneling into Redis Signal Pipeline ->")
                                    
                                    # Output the enriched intelligence JSON into the processed signals pipeline
                                    pipeline.xadd(
                                        name=self.stream_out,
                                        fields={"payload": json.dumps(article)},
                                        maxlen=1000,
                                        approximate=True
                                    )
                                
                                # Acknowledge message clearance so it never repeats
                                pipeline.xack(self.stream_in, self.consumer_group, msg_id)
                                
                            except json.JSONDecodeError:
                                logger.error("JSON Error parsing inbound stream string.")
                                pipeline.xack(self.stream_in, self.consumer_group, msg_id)

                        # Single transactional execute
                        await pipeline.execute()

                except Exception as e:
                    logger.error(f"Fatal iteration exception in Intelligence Framework: {e}")
                    await asyncio.sleep(1)
