# GLOBAL ARCHITECTURE: TIER-1 TRADING ENGINE
**Hardware:** Apple Silicon M1 Max (64GB RAM) - Optimize for Local Unified Memory.
**Philosophy:** Zero-cost APIs where possible, maximum privacy, modular separation of concerns.

## SYSTEM TOPOLOGY (Monorepo)
1. `/ingestion` (Module 1): Python 3.12+. Asynchronous data collection (Binance WS, CryptoPanic). Routes strictly typed data to local Redis. Does NO analysis.
2. `/intelligence` (Module 2): Local Ollama (qwen2.5:7b). Listens to Redis, scores sentiment, pushes back to Redis.
3. `/core` (Module 3): Laravel 11 / PHP 8.3. The Strategy Engine. Consumes Redis data, evaluates strategies, logs to `shadow_trades` PostgreSQL table. 
4. `/dashboard` (Module 4): Laravel Inertia (Vue/React). Real-time control room via Reverb (WebSockets).

## UNIVERSAL ENGINEERING RULES
* **Strict Typing:** All Python code must use `pydantic` for data validation. All PHP code must use strict return types.
* **Service-Oriented Architecture:** No fat controllers or flat Python scripts. Inject dependencies.
* **Logging:** Failures must log the exact state variables present at the time of the crash.
* **Cost:** Do not integrate paid third-party APIs.