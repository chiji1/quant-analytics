# INGESTION MODULE PROTOCOLS
**Role:** The Data Pulse. 
**Framework:** Async Python mimicking Laravel's Service Container architecture.

## REQUIRED DIRECTORY STRUCTURE
* `/app/console/`: Entry points (Typer/Argparse).
* `/app/models/`: Pydantic schemas (DTOs) for incoming market data.
* `/app/providers/`: Singleton connections (Redis async client).
* `/app/services/`: Core logic (Binance WebSocket handler, REST API pollers).
* `/config/`: Environment loading via `pydantic-settings`.

## RULES
1. **Never block the event loop:** Use `asyncio` for all I/O, `httpx` for REST calls, and `websockets` for streaming.
2. **Data Integrity:** Raw JSON from Binance/APIs must be immediately cast to a Pydantic model before touching internal logic.
3. **Outbound only:** This module writes to Redis. It does not read from the database or execute trades.