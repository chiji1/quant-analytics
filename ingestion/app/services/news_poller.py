import asyncio
import logging
import feedparser
import json
import httpx
from typing import Set

from app.providers.redis_client import redis_provider

logger = logging.getLogger(__name__)

class DirectNewsPoller:
    """
    Module 1 (V2): The Decentralized News Poller.
    Bypasses paid aggregators by asynchronously scraping Tier-1 crypto publication 
    RSS feeds directly, piping raw alpha straight into Redis for Ollama to score.
    """

    def __init__(self):
        # The raw alpha sources. You can add as many RSS feeds here as you want.
        self.feeds = [
            "https://cointelegraph.com/rss",
            "https://www.coindesk.com/arc/outboundfeeds/rss/",
            "https://decrypt.co/feed"
        ]
        self.processed_ids: Set[str] = set()
        self._running = False
        self.poll_interval = 15.0  # Still checking every 15 seconds

    async def fetch_and_parse(self, client: httpx.AsyncClient, url: str):
        """Fetches the XML asynchronously, then parses it safely."""
        try:
            response = await client.get(url, timeout=10.0)
            response.raise_for_status()
            # Offload the CPU-bound XML parsing to a separate thread
            parsed = await asyncio.to_thread(feedparser.parse, response.text)
            return parsed.entries
        except Exception as e:
            logger.warning(f"Failed to pull direct feed from {url}: {e}")
            return []

    async def start(self) -> None:
        """
        The Main Execution Loop for the Direct Poller.
        """
        self._running = True
        redis = await redis_provider.get_client()

        async with httpx.AsyncClient(follow_redirects=True) as client:
            while self._running:
                try:
                    logger.debug("Sweeping direct RSS feeds for market alpha...")
                    
                    # Fire all HTTP requests at the exact same time
                    tasks = [self.fetch_and_parse(client, url) for url in self.feeds]
                    results = await asyncio.gather(*tasks)

                    new_headlines = []

                    # Flatten the results and filter for new headlines
                    for entries in results:
                        for post in entries:
                            # RSS feeds use the link as the absolute unique identifier
                            post_id = post.get("link")
                            
                            if post_id and post_id not in self.processed_ids:
                                self.processed_ids.add(post_id)
                                
                                new_headlines.append({
                                    "id": post_id,
                                    "title": post.get("title", ""),
                                    "domain": post_id.split('/')[2] if '//' in post_id else "direct_feed",
                                })

                    # Memory management to prevent RAM leaks
                    if len(self.processed_ids) > 10000:
                        logger.info("Wiping aged local ID memory to restore unified limits.")
                        self.processed_ids.clear() 

                    if new_headlines:
                        logger.info(f"Ingested {len(new_headlines)} new direct headlines.")
                        pipeline = redis.pipeline()
                        
                        for article in new_headlines:
                            stream_entry = {"payload": json.dumps(article)}
                            pipeline.xadd(
                                name="ingestion:stream:news",
                                fields=stream_entry,
                                maxlen=500,
                                approximate=True
                            )
                        await pipeline.execute()

                except Exception as e:
                    logger.error(f"News Poller fatal iteration exception: {e}")

                await asyncio.sleep(self.poll_interval)