import asyncio
import logging
import httpx
import json
from typing import Set

from app.providers.redis_client import redis_provider

logger = logging.getLogger(__name__)

class CryptoPanicPoller:
    """
    Module 1: The News Poller.
    Asynchronously tracks and ingests headlines from CryptoPanic, pushing new occurrences 
    to Redis Streams while tracking memory state to avoid duplicates.
    """

    def __init__(self):
        # In a real environment, the auth_token would be loaded from Settings, 
        # but configured here directly for the sake of the poller implementation.
        self.api_url = "https://cryptopanic.com/api/v1/posts/?auth_token=DEMO" 
        self.processed_ids: Set[int] = set()
        self._running = False
        self.poll_interval = 15.0  # Constraint: every 15 seconds

    async def start(self) -> None:
        """
        The Main Execution Loop for the News Poller.
        """
        self._running = True
        redis = await redis_provider.get_client()

        # Share one AsyncClient instance across the loop for HTTP connection pooling
        async with httpx.AsyncClient() as client:
            while self._running:
                try:
                    logger.debug("Polling CryptoPanic for new market headlines...")
                    
                    response = await client.get(self.api_url, timeout=10.0)
                    response.raise_for_status()
                    data = response.json()

                    results = data.get("results", [])
                    new_headlines = []

                    for post in results:
                        post_id = post.get("id")
                        
                        # Constraint: Track the ID of the most recently processed posts
                        if post_id and post_id not in self.processed_ids:
                            self.processed_ids.add(post_id)
                            
                            new_headlines.append({
                                "id": post_id,
                                "title": post.get("title"),
                                "domain": post.get("domain"),
                                "published_at": post.get("published_at")
                            })

                    # Cap memory state continuously to prevent memory leaks in production runtime
                    if len(self.processed_ids) > 10000:
                        logger.info("Wiping aged local ID memory to restore unified limits.")
                        self.processed_ids.clear() 

                    if new_headlines:
                        logger.info(f"Ingesting {len(new_headlines)} new headlines.")
                        pipeline = redis.pipeline()
                        for article in new_headlines:
                            # Push new headlines to a Redis Stream named ingestion:stream:news
                            stream_entry = {"payload": json.dumps(article)}
                            pipeline.xadd(
                                name="ingestion:stream:news",
                                fields=stream_entry,
                                maxlen=500,  # Constraint: Use redis.xadd with maxlen=500
                                approximate=True
                            )
                        await pipeline.execute()

                except httpx.HTTPError as e:
                    logger.warning(f"CryptoPanic HTTP Transport Error: {e}")
                except Exception as e:
                    logger.error(f"CryptoPanic Poller fatal iteration exception: {e}")

                await asyncio.sleep(self.poll_interval)
