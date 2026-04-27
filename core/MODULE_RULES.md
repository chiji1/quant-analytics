# LARAVEL ENGINE PROTOCOLS (MODULE 3 & 4)
**Role:** The Strategy Execution Engine and Control Room.
**Framework:** Laravel 11 (PHP 8.3+).
**Hardware:** Local M1 Max execution. No heavy cloud dependencies.

## STRICT ARCHITECTURAL RULES
1. **Separation of Concerns:** - Strategy Classes (`app/Strategies`): Only decide *when* to trade. Pure math/logic. No API calls.
   - Execution Service (`app/Services/BinanceExecutionService.php`): Only decides *how* to trade. Handles network, API, and safety.
   - Daemons (`app/Console/Commands`): Consume Redis streams (`XREADBLOCK`) and pass data to Strategies.
2. **Execution Safety (The Bracket Rule):** NEVER execute a naked `MARKET` order. All entries must use an Immediate-Or-Cancel (IOC) Limit order. All entries must be immediately followed by a `STOP_MARKET` and `TAKE_PROFIT_MARKET` order (OCO).
3. **Idempotency:** All Binance API calls must generate and pass a UUID as `newClientOrderId`.
4. **Time Synchronization:** The Execution Service must ping Binance for a time offset to prevent `recvWindow` rejection errors.
5. **Strict Typing:** PHP 8.3 strict typing is mandatory. Use `declare(strict_types=1);` on all files. Return types are non-negotiable.
6. **Frontend Ecosystem:** We use Laravel Breeze (Inertia Stack) with React or Vue. We use Laravel Reverb for real-time WebSocket broadcasting. Livewire is strictly forbidden due to XHR DOM repaint latency.