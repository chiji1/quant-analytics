import asyncio
import logging
import signal
import sys

from app.services.binance_ws import BinanceDataStreamer
from app.services.news_poller import DirectNewsPoller
# from app.services.crypto_panic import CryptoPanicPoller
from app.services.ollama_worker import OllamaIntelligenceEngine
from app.providers.redis_client import redis_provider

# Apply strict enterprise logging standards to standard output securely
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[logging.StreamHandler(sys.stdout)]
)

logger = logging.getLogger("SystemBootstrapper")

async def shutdown(loop, signal=None) -> None:
    """
    Robust termination sequence hooking into system interrupts.
    Cleanly unwinds tasks and destroys active connections.
    """
    if signal:
        logger.info(f"System Exit Trapped (Signal: {signal.name}). Initializing shutdown sequence...")
    
    # Retrieve all running nodes excluding the active termination routine
    tasks = [t for t in asyncio.all_tasks() if t is not asyncio.current_task()]
    
    logger.info(f"Cancelling {len(tasks)} outstanding background services.")
    for task in tasks:
        task.cancel()
    
    await asyncio.gather(*tasks, return_exceptions=True)
    
    logger.info("Dismantling Redis connection container...")
    await redis_provider.close()
    
    loop.stop()
    logger.info("Kernel successfully powered down.")

def main():
    """
    The Core Bootstrapper initializing Module 1 and Module 2 concurrently.
    """
    loop = asyncio.get_event_loop()
    
    # Configure precise POSIX/Linux process hooks
    signals = (signal.SIGHUP, signal.SIGTERM, signal.SIGINT)
    for s in signals:
        loop.add_signal_handler(
            s, lambda s=s: asyncio.create_task(shutdown(loop, signal=s))
        )

    # Initialize Object Classes via DI principles
    streamer = BinanceDataStreamer()
    # poller = CryptoPanicPoller()
    poller = DirectNewsPoller()
    intelligence = OllamaIntelligenceEngine()

    logger.info("Initiating Monorepo Concurrency. Spinning Up All Modules...")
    
    # Standardize loop invocation to support modern Python event cycles without deprecation noise
    try:
        loop = asyncio.get_running_loop()
    except RuntimeError:
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
    
    try:
        # We hook into system signals directly allowing Graceful shutdown cycles
        for sig in (signal.SIGINT, signal.SIGTERM):
            loop.add_signal_handler(sig, lambda signature=sig: asyncio.create_task(shutdown(loop, signal=signature)))

        # Launch the core processing subsystems concurrently 
        loop.run_until_complete(
            asyncio.gather(
                streamer.start(),
                poller.start(),
                intelligence.start()
            )
        )
    except asyncio.CancelledError:
        pass
    except Exception as e:
        logger.error(f"Uncrecoverable Exception bypassed execution layer: {e}")
    finally:
        logger.info("Bootstrapper completed lifecycle.")

if __name__ == "__main__":
    main()
