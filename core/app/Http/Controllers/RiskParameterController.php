<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RiskParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RiskParameterController extends Controller
{
    /**
     * Retrieve global risk parameters for the UI store.
     */
    public function show()
    {
        $parameters = RiskParameter::first();

        if (!$parameters) {
            $parameters = RiskParameter::create([
                'allowed_symbols' => [],
                'base_allocation_usdt' => 10000.0,
                'news_sentiment_buy_threshold' => 0.85,
                'news_sentiment_sell_threshold' => -0.85,
                'global_kill_switch' => false,
            ]);
        }

        return response()->json($parameters);
    }

    /**
     * Update global parameters via React payload, sync to DB, and mirror to Redis Cache.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'allowed_symbols' => 'array',
            'base_allocation_usdt' => 'numeric|min:10',
            'max_trade_allocation_usdt' => 'numeric|min:10',
            'news_sentiment_buy_threshold' => 'numeric|min:0.01|max:1',
            'news_sentiment_sell_threshold' => 'numeric|min:-1|max:-0.01',
            'dynamic_leverage_enabled' => 'boolean',
            'global_kill_switch' => 'boolean',
            'active_strategies' => 'array',
        ]);

        // Clean up UI symbols
        if (isset($validated['allowed_symbols'])) {
            $validated['allowed_symbols'] = array_map(fn($sym) => strtoupper(trim($sym)), $validated['allowed_symbols']);
        }

        $parameters = RiskParameter::first();
        if ($parameters) {
            $parameters->update($validated);
        } else {
            $parameters = RiskParameter::create($validated);
        }

        // --- THE REDIS BRIDGE ---
        // 1. Mirror natively for PHP Core execution strategies
        Cache::put('system:risk_parameters', [
            'allowed_symbols'               => $parameters->allowed_symbols,
            'base_allocation_usdt'          => (float) $parameters->base_allocation_usdt,
            'max_trade_allocation_usdt'     => (float) ($parameters->max_trade_allocation_usdt ?? 100000.0),
            'news_sentiment_buy_threshold'  => (float) $parameters->news_sentiment_buy_threshold,
            'news_sentiment_sell_threshold' => (float) $parameters->news_sentiment_sell_threshold,
            'dynamic_leverage_enabled'      => (bool) ($parameters->dynamic_leverage_enabled ?? true),
            'global_kill_switch'            => (bool) $parameters->global_kill_switch,
            'active_strategies'             => $parameters->active_strategies ?? ['AlphaNewsChaserStrategy'],
        ], now()->addYears(10));

        // 2. Export flat JSON explicitly mapped for Python's non-blocking ingestion engine
        \Illuminate\Support\Facades\Redis::set('python_risk_parameters', json_encode([
            'allowed_symbols' => $parameters->allowed_symbols
        ]));

        return response()->json(['success' => true, 'data' => $parameters]);
    }
}
