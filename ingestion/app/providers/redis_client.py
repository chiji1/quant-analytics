import asyncio
from typing import Optional
from redis.asyncio import Redis, ConnectionPool
from config.settings import settings

class RedisClientProvider:
    """
    Singleton provider for an asynchronous Redis connection pool, 
    mimicking the Laravel Service Container pattern.
    """
    _instance: Optional['RedisClientProvider'] = None
    _redis: Optional[Redis] = None
    _lock: asyncio.Lock

    def __new__(cls) -> 'RedisClientProvider':
        if cls._instance is None:
            cls._instance = super(RedisClientProvider, cls).__new__(cls)
            cls._instance._lock = asyncio.Lock()
        return cls._instance

    async def get_client(self) -> Redis:
        """
        Returns the active async Redis connection, initializing it if necessary.
        """
        if self._redis is None:
            async with self._lock:
                if self._redis is None:
                    # Initialize the async connection pool
                    pool = ConnectionPool.from_url(
                        settings.redis_url, 
                        decode_responses=True
                    )
                    self._redis = Redis.from_pool(pool)
        return self._redis

    async def close(self) -> None:
        """
        Gracefully closes the Redis connection pool.
        """
        if self._redis is not None:
            async with self._lock:
                if self._redis is not None:
                    await self._redis.aclose() # type: ignore
                    self._redis = None

# Export the singleton instance to be injected/imported
redis_provider = RedisClientProvider()
