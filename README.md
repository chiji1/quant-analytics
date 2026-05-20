# Algorithmic Sentiment Trading Engine

An end-to-end autonomous trading system that combines real-time market data ingestion, LLM-powered news sentiment analysis, and algorithmic trade execution on Binance Futures. Built entirely as a solo project.

---

## Overview

Most retail trading bots react to price. This one reacts to *meaning* — it continuously ingests financial news, scores it for sentiment using a locally-hosted LLM, and executes trades only when the signal passes a defined confidence threshold, cross-referenced against live order book and macro momentum data.

```
News feed → LLM sentiment scoring → Signal validation → VWAP execution → Binance Futures
               (Python)               (PHP/Laravel)        (PHP/Laravel)
```

---

## Architecture

### 1. Ingestion Engine (Python)

Handles real-time market data and news at high throughput.

- **Algorithmic MTU Sharding** — Binance WebSocket streams are dynamically chunked across 30 physical socket connections, handling extreme data volumes without triggering Binance disconnect protocols
- **Zero-I/O RAM Caches** — velocity delta and 5-minute rolling velocity EMA are computed in-memory directly from 3-second snapshot sequences, bypassing Redis I/O on the hot path
- **LLM Intelligence Layer** — integrates a locally-hosted `qwen2.5:7b` model via Ollama to continuously pull financial news and return structured JSON sentiment scores; a strict 2-second `asyncio` circuit breaker guards against model hangs in the pipeline
- **Redis Streams transport** — processed buffers are pushed to Redis Streams with `maxlen=1000` to prevent memory leaks over long sessions

### 2. Strategy Engine (PHP / Laravel)

An `engine:listen` daemon consumes the Redis Stream matrix and applies multi-factor signal logic before any trade is considered.

- **Anti-Spoofing Order Book Analysis** — uses an Inverted Mid-Book Weighting system that suppresses DOM levels 0–3 (front-book noise from HFT spoofing) and heavily weights levels 4–12 for a cleaner signal
- **BTC Gravity Vector Guard** — altcoin signals are cross-referenced against the live BTC velocity EMA cache; signals fighting macro momentum are rejected unless the altcoin shows extreme, decoupled volume
- **Dynamic Bracket Scaling** — leverage, stop-loss, and take-profit targets scale organically with the market confidence/imbalance ratio rather than using static values

### 3. VWAP Execution Engine (PHP / Laravel)

Prioritises capital preservation over naive market execution.

- **Pre-trade DOM traversal** — before any API call is made, the engine iterates over the cached order book to calculate the exact execution ceiling; if the position size exhausts the top 50 levels, the trade is aborted natively to prevent slippage
- **Late Wick Suppressor** — pulls the 1-minute Kline REST data; if the VWAP diverges more than 0.8% from the 1-minute open, the signal is dropped (chasing an exhausted candle)
- **Atomic Brackets + Orphan Sweep** — trades are dispatched with an immediate IOC limit entry plus `STOP_MARKET` and `TAKE_PROFIT_MARKET` brackets; if any network failure or zero-fill occurs, a `sweepNakedOrphans` fallback forcefully liquidates partial fills and cleans up stranded limit structures

---

## Tech Stack

| Layer | Technology |
|---|---|
| Ingestion | Python, asyncio, WebSockets |
| LLM | Ollama (local), qwen2.5:7b |
| Message bus | Redis Pub/Sub, Redis Streams |
| Strategy engine | PHP 8, Laravel, CLI daemon |
| Execution | Binance Futures API (REST + WebSocket) |
| Caching | Redis (in-memory velocity caches) |

---

## Key Design Decisions

**Why local LLM over OpenAI API?**
Latency. A remote API call on the sentiment path adds 300–800ms per news item. With `qwen2.5:7b` running locally via Ollama, inference stays under 100ms on modest hardware, and there's no per-token cost at scale.

**Why PHP/Laravel for the strategy engine?**
The strategy layer is CPU-light and I/O-bound — it reads from Redis and writes to the Binance API. Laravel's queue/daemon primitives handle this cleanly, and the type strictness in PHP 8 makes financial logic easier to reason about than loose JS.

**Why abort on spoofing noise rather than filter it?**
Most anti-spoofing approaches try to weight out the noise. This engine takes a harder stance — if the front book is suspicious, the signal is dropped entirely. A missed trade costs nothing; a spoofed entry can cost real capital.

---

## Status

Personal research project — not financial advice. The engine is functional and has been tested against live Binance Testnet data. Not currently running in production.

---

## Author

**Chijioke Okafor** — Senior Full Stack Engineer  
[linkedin.com/in/chijiokeokaforifeanyi](https://linkedin.com/in/chijiokeokaforifeanyi)


## Prerequisites

Before running the application, ensure the following background services are running on your machine:

1. **Redis Server**: The core message broker for the system.
   ```bash
   redis-server
   ```

2. **Ollama**: The local AI engine (requires the `qwen2.5:7b` model).
   ```bash
   ollama run qwen2.5:7b
   ```

3. **PostgreSQL**: Ensure your database is running and configured in `core/.env`.

---

## Terminal Commands to Run the Application

You will need multiple terminal windows to run all the microservices concurrently.

### Terminal 1: Laravel Web Server, Frontend Vite, and Standard Queues
This command utilizes concurrently to start the PHP development server, the Vite frontend build server, and the standard Laravel job listener.
```bash
cd core
composer run dev
```

### Terminal 2: Reverb WebSocket Server
Required for real-time React dashboard updates via broadcasting.
```bash
cd core
php artisan reverb:start
```

### Terminal 3: The Trading Engine Daemon
This runs the core event loop that consumes the Redis streams and executes algorithmic strategies.
```bash
cd core
php artisan engine:listen
```

### Terminal 4: Python Data Ingestion Pulse
This starts the asynchronous Binance WebSocket data streamer, news poller, and the Ollama worker.
```bash
cd ingestion
# (Activate your virtual environment if applicable, e.g., source venv/bin/activate)
pip install -r requirements.txt
python main.py
```

---

## Initial Setup (First Run Only)

If this is your first time setting up the repository, run the following commands to initialize the project state:

**Core (Laravel/React):**
```bash
cd core
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

**Ingestion (Python):**
```bash
cd ingestion
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env # (If applicable)
```
