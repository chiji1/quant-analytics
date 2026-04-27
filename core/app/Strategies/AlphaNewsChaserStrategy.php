<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;

class AlphaNewsChaserStrategy implements TradingStrategyInterface
{
    private const BASE_ALLOCATION_USDT = 10000.0;

    public function evaluate(array $marketData): ?array
    {
        if (!isset($marketData['sentiment_score']) || !isset($marketData['symbol'])) {
            return null; // Force symbol to be explicitly provided by the LLM
        }

        $score = (float) $marketData['sentiment_score'];
        $symbol = $marketData['symbol'];

        // Pull dynamic risk parameters from the Redis configuration loop, with fallback defaults
        $baseAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['base_allocation_usdt'] ?? 10000.0);
        $buyThreshold = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['news_sentiment_buy_threshold'] ?? 0.85);
        $sellThreshold = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['news_sentiment_sell_threshold'] ?? -0.85);

        // 1. Guard against statistically irrelevant signals FIRST to avoid polluting logs with Cache missing errors on trash data
        if ($score < $buyThreshold && $score > $sellThreshold) {
            broadcast(new \App\Events\SignalRejected($symbol, $score, "Insufficient Sentiment (Score: {$score} did not satisfy {$buyThreshold} / {$sellThreshold})"));
            return null;
        }

        // Instantiating Binance Execution dependencies structurally for hot-fetching safely
        $executor = app(\App\Services\BinanceExecutionService::class);

        $maxAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['max_trade_allocation_usdt'] ?? 100000.0);
        $dynamicLeverage = (bool) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['dynamic_leverage_enabled'] ?? true);

        // Map leverage multiplier inherently
        $targetLeverage = 1;
        if ($dynamicLeverage) {
            $conviction = ($score >= $buyThreshold) 
                ? ($score - $buyThreshold) / (1.0 - $buyThreshold) 
                : (abs($score) - abs($sellThreshold)) / (1.0 - abs($sellThreshold));
            
            // Scaled dynamically natively (2x minimum up to 10x maximum purely based on AI extreme metrics)
            $targetLeverage = (int) round(2 + (8 * $conviction));
            $targetLeverage = $executor->setDynamicLeverage($symbol, $targetLeverage);
        }

        // Radically force-switch execution to cross leveraging inherently securing AI confidence sizing unconditionally
        $executor->setMarginType($symbol, 'CROSSED');

        // Geometric boundary scaling intrinsically limited identically to physical Margin structural allocations
        $scaledAllocation = min($maxAllocation, $baseAllocation * $targetLeverage);
        
        // Collateral checks natively elevated into BinanceExecutionService to universally track logic bounds.

        if ($score >= $buyThreshold) {
            return [
                'symbol' => $symbol,
                'side' => 'BUY',
                'usdt_allocation' => $scaledAllocation,
                'sl_percentage' => 0.03, // 3%
                'tp_percentage' => 0.06, // 6%
            ];
        } elseif ($score <= $sellThreshold) {
            return [
                'symbol' => $symbol,
                'side' => 'SELL',
                'usdt_allocation' => $scaledAllocation,
                'sl_percentage' => 0.03, // 3%
                'tp_percentage' => 0.06, // 6%
            ];
        }
        broadcast(new \App\Events\SignalRejected($symbol, $score, "Insufficient Sentiment (Score: {$score} did not satisfy {$buyThreshold} / {$sellThreshold})"));
        return null;
    }
}
