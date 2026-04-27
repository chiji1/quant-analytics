<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\BinanceExecutionService;
use Throwable;

class EngineListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'engine:listen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Primary core event loop consuming Redis streams and evaluating strategies.';

    /**
     * @var \App\Services\BinanceExecutionService
     */
    private BinanceExecutionService $executor;

    /**
     * @var float
     */
    private float $lastTrailingTime = 0.0;
    
    /**
     * @var array
     */
    private array $cachedStrategyStrings = [];

    public function handle(BinanceExecutionService $executor): int
    {
        $this->executor = $executor;
        $this->lastTrailingTime = microtime(true);

        $this->info("Initializing Tier-1 Trading Engine... Listening to Redis streams.");

        $streams = [
            'ingestion:stream:liquidations' => '$',
            'processed:stream:signals' => '$',
            'ingestion:stream:depth' => '$',
        ];

        while (true) {
            try {
                // Execute Trailing Routine strictly governed structurally outside Redis blocks
                if ((microtime(true) - $this->lastTrailingTime) > 7.0) {
                    $this->lastTrailingTime = microtime(true);
                    $this->evaluateTrailingSafeguards();
                }

                $args = ['XREAD', 'BLOCK', 2000, 'STREAMS']; // Reduced blocking implicitly feeding Monitor sequence dynamically
                $keys = array_keys($streams);
                $ids = array_values($streams);

                $response = Redis::executeRaw(array_merge($args, $keys, $ids));

                if (!$response) {
                    continue; // Timeout, loop natively executes trailing checks again
                }

                foreach ($response as $streamResult) {
                    $streamName = $streamResult[0];
                    $messages = $streamResult[1];

                    foreach ($messages as $messageRaw) {
                        $messageId = $messageRaw[0];
                        $payloadDict = $this->parseRedisHash($messageRaw[1]);

                        $streams[$streamName] = $messageId;

                        if (isset($payloadDict['payload'])) {
                            $decodedPayload = json_decode($payloadDict['payload'], true) ?? [];
                        } else {
                            $decodedPayload = $payloadDict; // Fallback Native mapping
                        }

                        $this->processPayload($decodedPayload);
                    }
                }

            } catch (Throwable $e) {
                Log::error("Daemon Error: {$e->getMessage()}", [
                    'exception' => $e,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Intercepts payload logic to cache depths and distributes cleanly to strategies.
     */
    private function processPayload(array $payload): void
    {
        $eventType = $payload['event_type'] ?? $payload['e'] ?? '';
        $isDepth = ($eventType === 'depthUpdate') || (empty($eventType) && (isset($payload['bids']) || isset($payload['b'])));

        if ($isDepth && (isset($payload['bids']) || isset($payload['b'])) && (isset($payload['asks']) || isset($payload['a']))) {
            $bids = $payload['bids'] ?? $payload['b'];
            $asks = $payload['asks'] ?? $payload['a'];

            // Map string pairs back into strict associative arrays for React frontend {price, quantity}
            try {
                $mappedBids = array_map(fn($item) => ['price' => (float)$item[0], 'quantity' => (float)$item[1]], $bids);
                $mappedAsks = array_map(fn($item) => ['price' => (float)$item[0], 'quantity' => (float)$item[1]], $asks);
                
                $symbol = $payload['symbol'] ?? $payload['s'] ?? 'UNKNOWN';
                broadcast(new \App\Events\DepthUpdated($symbol, $mappedBids, $mappedAsks));

                if ($symbol !== 'UNKNOWN') {
                    Cache::put('latest_depth_' . $symbol, [
                        'bids' => array_slice($mappedBids, 0, 50),
                        'asks' => array_slice($mappedAsks, 0, 50),
                        'updated_at' => microtime(true),
                    ], 60);
                }
            } catch (\Throwable $err) {
                // Ignore corrupt cast attempts safely
            }
        }

        if (isset($payload['sentiment_score'])) {
            \Illuminate\Support\Facades\Log::info("SUCCESS [Bridge Cleared]: Ingested AI Signal for '{$payload['symbol']}' (Score: {$payload['sentiment_score']}). Routing to Engine ->");
            broadcast(new \App\Events\SentimentScored((float) $payload['sentiment_score']));
        }

        // 1. Core pre-computation has been hoisted to strictly map decoded strings above
        
        // Normalize the payload dynamically ensuring strict JSON alignment mapping natively across ALL strategies
        $payload['event_type'] = $eventType;
        if ($isDepth) {
            $payload['bids'] = $payload['bids'] ?? $payload['b'];
            $payload['asks'] = $payload['asks'] ?? $payload['a'];
            $payload['symbol'] = $payload['symbol'] ?? $payload['s'] ?? 'UNKNOWN';
        }

        // Substitute static PHP array bindings natively replacing dynamically executing Laravel class_exists() IO dependencies
        $strategiesList = \Illuminate\Support\Facades\Cache::get('system:risk_parameters')['active_strategies'] ?? ['AlphaNewsChaserStrategy'];
        $strategies = [];

        foreach ($strategiesList as $stratName) {
            $classMap = "App\\Strategies\\" . $stratName;
            if (class_exists($classMap)) {
                $strategies[] = app($classMap);
            }
        }

        // 2. Loop payload through dynamically bound algorithms intrinsically mapping
        foreach ($strategies as $strategy) {
            $signal = $strategy->evaluate($payload);

            if ($signal !== null && isset($signal['symbol'])) {
                $this->executeSignal($signal, get_class($strategy));
            }
            // Add capability to iterate over multi-leg trades synchronously with Atomicity pre-checks
            elseif (is_array($signal) && !isset($signal['symbol']) && count($signal) > 0) {
                $this->executeMultiLegSignal($signal, get_class($strategy));
            }
        }
    }

    /**
     * Executes identical external Trailing Heartbeat mechanics mapping over MySQL structs natively
     */
    private function evaluateTrailingSafeguards(): void
    {
        $openTrades = \App\Models\Trade::where('status', 'open')->get();

        foreach ($openTrades as $trade) {
            $depthCache = \Illuminate\Support\Facades\Cache::get('latest_depth_' . $trade->symbol);
            if (!$depthCache) continue;

            $currentPrice = (float) ((strtoupper($trade->side) === 'BUY') ? $depthCache['bids'][0]['price'] : $depthCache['asks'][0]['price']);
            $entryPrice = (float) $trade->fill_price;
            
            // Execute tightly bound trailing constraints pushing dynamically specifically in-profit natively
            $shouldTrail = false;
            $closeSide = (strtoupper($trade->side) === 'BUY') ? 'SELL' : 'BUY';
            $newStopPrice = 0.0;

            if (strtoupper($trade->side) === 'BUY') {
                $shouldTrail = (($currentPrice - $entryPrice) / $entryPrice) >= 0.005;
                $newStopPrice = $currentPrice * 0.99;
            } else {
                $shouldTrail = (($entryPrice - $currentPrice) / $entryPrice) >= 0.005;
                $newStopPrice = $currentPrice * 1.01;
            }

            if ($shouldTrail) {

                try {
                    $this->executor->updateTrailingBracket(
                        $trade->symbol,
                        $closeSide,
                        $trade->stop_loss_order_id,
                        $newStopPrice
                    );
                    \Illuminate\Support\Facades\Log::info("Atomic Trailing Executed correctly identical natively for {$trade->symbol}");
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Atomic cancelReplace boundary rejection purely due to structural limits: {$e->getMessage()}");
                }
            }
        }
        
        // Logical ghost sweeps tracking Strategy boundaries dynamically inside native bounds
        $activeStrats = \Illuminate\Support\Facades\Cache::get('system:risk_parameters')['active_strategies'] ?? ['AlphaNewsChaserStrategy'];
        if (empty($activeStrats)) {
            // Initiate Ghost Protocol Dump unconditionally naturally sweeping physical orphan limitations naturally safely
            foreach ($openTrades as $nakedTrade) {
                $this->executor->sweepNakedOrphans(
                    $nakedTrade->symbol,
                    (strtoupper($nakedTrade->side) === 'BUY') ? 'SELL' : 'BUY',
                    (float) $nakedTrade->executed_quantity
                );
                $nakedTrade->update(['status' => 'closed']);
            }
        }
    }

    /**
     * Dispatches multi-leg signal arrays securely enforcing sequential Atomicity and Fallback mechanisms natively.
     */
    private function executeMultiLegSignal(array $signals, string $strategyClass): void
    {
        // Pre-Evaluate MySQL Trade Locks & Mutex for ALL array legs simultaneously
        foreach ($signals as $subSignal) {
            if (!isset($subSignal['symbol'])) return;
            
            if (\Illuminate\Support\Facades\Cache::has('wash_trade_cooldown_' . $subSignal['symbol'])) {
                $this->warn("Telescopic Mute active for {$subSignal['symbol']}. Dropping multi-leg signal.");
                return;
            }

            if (!\Illuminate\Support\Facades\Cache::add('execute_lock_' . $subSignal['symbol'], true, 5)) {
                $this->warn("Execution Mutex: Dropping multi-leg signal for {$subSignal['symbol']} - Concurrency flood blockade active.");
                return;
            }
            if (\App\Models\Trade::where('symbol', $subSignal['symbol'])->where('status', 'open')->exists()) {
                $this->warn("Execution Lock: Dropping entire dual-leg array - Active position natively exists for {$subSignal['symbol']}");
                return; 
            }
        }

        $executedLegs = [];

        foreach ($signals as $subSignal) {
            $this->info("Signal Generated via {$strategyClass} for {$subSignal['symbol']} -> {$subSignal['side']} (Multi-Leg)");

            try {
                $executionResponse = $this->executor->dispatchIntelligentBracket($subSignal);

                if ($executionResponse === false || isset($executionResponse['error'])) {
                    $this->warn("VWAP Bus Rejected Leg natively. Rollback Flatten Initiated!");
                    foreach ($executedLegs as $executedTrade) {
                        $this->executor->sweepNakedOrphans(
                            $executedTrade->symbol,
                            strtoupper($executedTrade->side) === 'BUY' ? 'SELL' : 'BUY',
                            (float) $executedTrade->executed_quantity
                        );
                        $executedTrade->update(['status' => 'closed']);
                        \Illuminate\Support\Facades\Log::warning("EMERGENCY FLATTEN: Closed naked leg {$executedTrade->symbol} naturally.");
                    }
                    return;
                }

                $actualQty = (float) ($executionResponse['ioc_entry']['executedQty'] ?? 0);
                $actualPrice = (float) ($executionResponse['ioc_entry']['avgPrice'] ?? ($subSignal['ioc_price'] ?? 0));
                
                $tradeId = \Illuminate\Support\Str::uuid()->toString();

                $tradeRecord = \App\Models\Trade::create([
                    'id' => $tradeId,
                    'symbol' => $subSignal['symbol'],
                    'side' => $subSignal['side'],
                    'strategy' => class_basename($strategyClass),
                    'executed_quantity' => $actualQty,
                    'fill_price' => $actualPrice,
                    'status' => 'open',
                    'stop_loss_order_id' => (string) ($executionResponse['stop_loss']['orderId'] ?? ''),
                    'take_profit_order_id' => (string) ($executionResponse['take_profit']['orderId'] ?? ''),
                ]);

                $executedLegs[] = $tradeRecord;

                $subSignal['id'] = $tradeId;
                $subSignal['timestamp'] = $tradeRecord->created_at->timestamp * 1000;
                $subSignal['price'] = $actualPrice;
                $subSignal['quantity'] = $actualQty;
                $subSignal['strategy'] = class_basename($strategyClass);

                broadcast(new \App\Events\TradeExecuted($subSignal));

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Multi-Leg Layer Failure: {$e->getMessage()}");
                foreach ($executedLegs as $executedTrade) {
                    $this->executor->sweepNakedOrphans(
                        $executedTrade->symbol,
                        strtoupper($executedTrade->side) === 'BUY' ? 'SELL' : 'BUY',
                        (float) $executedTrade->executed_quantity
                    );
                    $executedTrade->update(['status' => 'closed']);
                }
                return;
            }
        }
    }

    /**
     * Dispatches the signal parameters securely to the execution layer.
     */
    private function executeSignal(array $signal, string $strategyClass): void
    {
        if (\Illuminate\Support\Facades\Cache::has('wash_trade_cooldown_' . $signal['symbol'])) {
            $this->warn("Telescopic Mute active for {$signal['symbol']}. Ignoring signal.");
            return;
        }

        // 0. Global Race-Condition Mutex (5-second precision block) neutralizing WS floods natively
        if (!\Illuminate\Support\Facades\Cache::add('execute_lock_' . $signal['symbol'], true, 5)) {
            return;
        }

        // 1. Entanglement Lock: Prevent simultaneous executions on overlapping Symbols
        if (\App\Models\Trade::where('symbol', $signal['symbol'])->where('status', 'open')->exists()) {
            $this->warn("Execution Lock: Dropping signal for {$signal['symbol']} - Active algorithmic position natively exists on One-Way mode!");
            return;
        }

        $this->info("Signal Generated via {$strategyClass} for {$signal['symbol']} -> {$signal['side']}");

        try {
            $executionResponse = $this->executor->dispatchIntelligentBracket($signal);

            if ($executionResponse === false) {
                $this->warn("Universal VWAP Bus Rejected Execution natively (Exhaustion/Late-Wick/Reverse Notional Squeeze).");
                $score = (float) ($signal['sentiment_score'] ?? 0.0);
                broadcast(new \App\Events\SignalRejected($signal['symbol'], $score, 'Execution Fault: Structural limits violated globally across VWAP barriers.', class_basename($strategyClass)));
                return;
            }

            if (isset($executionResponse['error'])) {
                $this->warn("Execution Zombie Guard Triggered: {$executionResponse['error']}");
                \Illuminate\Support\Facades\Log::warning("Phantom Bracket execution halted gracefully due to 0-fill entry limits. Signal dropped natively.");
                
                $score = (float) ($signal['sentiment_score'] ?? 0.0);
                broadcast(new \App\Events\SignalRejected($signal['symbol'], $score, 'Execution Rejected: ' . $executionResponse['error'], class_basename($strategyClass)));
                
                return;
            }

            $actualQty = (float) ($executionResponse['ioc_entry']['executedQty'] ?? 0);
            $actualPrice = (float) ($executionResponse['ioc_entry']['avgPrice'] ?? $signal['ioc_price']);
            
            // Extract limit sequence Identifiers natively
            $slOrderId = (string) ($executionResponse['stop_loss']['orderId'] ?? '');
            $tpOrderId = (string) ($executionResponse['take_profit']['orderId'] ?? '');

            $tradeId = \Illuminate\Support\Str::uuid()->toString();

            $tradeRecord = \App\Models\Trade::create([
                'id' => $tradeId,
                'symbol' => $signal['symbol'],
                'side' => $signal['side'],
                'strategy' => class_basename($strategyClass),
                'executed_quantity' => $actualQty,
                'fill_price' => $actualPrice,
                'status' => 'open',
                'stop_loss_order_id' => $slOrderId,
                'take_profit_order_id' => $tpOrderId,
            ]);

            $this->info("Bracket Execution Dispatched and Acknowledged for {$signal['symbol']} completely.");
            \Illuminate\Support\Facades\Log::info("EXECUTION SUCCESS: Deployed algorithmic Bracket over MySQL ledger securely for {$signal['symbol']} -> {$signal['side']} at strict avg_price {$actualPrice}");

            // Route precise Ledger configurations universally across React Socket channels 
            $signal['id'] = $tradeId;
            $signal['timestamp'] = $tradeRecord->created_at->timestamp * 1000;
            $signal['price'] = $actualPrice;
            $signal['quantity'] = $actualQty; // Strictly overwrite intended quantity with exchange execution matrix parameters
            $signal['strategy'] = class_basename($strategyClass);

            broadcast(new \App\Events\TradeExecuted($signal));
        } catch (Throwable $e) {
            Log::error("Execution Layer Failure Post-Signal: {$e->getMessage()}", [
                'signal' => $signal,
                'strategy' => $strategyClass,
            ]);
            
            $score = (float) ($signal['sentiment_score'] ?? 0.0);
            broadcast(new \App\Events\SignalRejected($signal['symbol'], $score, 'Execution Fault: ' . $e->getMessage()));
        }
    }

    /**
     * Normalizes the predis/phpredis output hash flattening into an associative array.
     */
    private function parseRedisHash(array $rawList): array
    {
        $out = [];
        $count = count($rawList);
        for ($i = 0; $i < $count; $i += 2) {
            $out[(string) $rawList[$i]] = $rawList[$i + 1];
        }
        return $out;
    }
}
