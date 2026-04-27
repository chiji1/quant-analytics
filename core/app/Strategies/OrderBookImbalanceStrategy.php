<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;

class OrderBookImbalanceStrategy implements TradingStrategyInterface
{

    public function evaluate(array $marketData): ?array
    {
        if (($marketData['event_type'] ?? '') !== 'depthUpdate') {
            return null;
        }

        if (empty($marketData['bids']) || empty($marketData['asks'])) {
            return null;
        }
        
        $bestBid = (float) $marketData['bids'][0][0];
        $bestAsk = (float) $marketData['asks'][0][0];

        if ($bestAsk <= 0) return null;

        // Phase 1: Spread Circuit Breaker completely halting execution during violent evaporation structurally
        $spreadPercentage = (($bestAsk - $bestBid) / $bestAsk) * 100;
        if ($spreadPercentage > 0.15) {
            // \Illuminate\Support\Facades\Log::warning("Spread Circuit Breaker Triggered: Spread is " . number_format($spreadPercentage, 3) . "%. Signal Dropped.");
            return null;
        }

        // Phase 2: Deep 20-Level Institutional Depth Penetration natively
        $bids = array_slice($marketData['bids'], 0, 20);
        $asks = array_slice($marketData['asks'], 0, 20);

        // Extended 20-level Exponential Decay Weighting limits spoof walls.
        $decayFactors = [
            1.00, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70, 0.65, 0.60, 0.55,
            0.50, 0.45, 0.40, 0.35, 0.30, 0.25, 0.20, 0.15, 0.10, 0.05
        ];

        $bidVolume = 0.0;
        foreach ($bids as $index => $bid) {
            $volume = ((float)$bid[0] * (float)$bid[1]);
            $multiplier = $decayFactors[$index] ?? 0.2;
            $bidVolume += ($volume * $multiplier);
        }

        $askVolume = 0.0;
        foreach ($asks as $index => $ask) {
            $volume = ((float)$ask[0] * (float)$ask[1]);
            $multiplier = $decayFactors[$index] ?? 0.2;
            $askVolume += ($volume * $multiplier);
        }

        if ($bidVolume === 0.0 || $askVolume === 0.0) return null;

        $imbalanceRatio = max($bidVolume, $askVolume) / min($bidVolume, $askVolume);

        if ($imbalanceRatio < 7.0) {
            return null; // Suppresses mathematically structurally insignificant deviations safely
        }

        // Phase 3 & 4: Institutional RAM Validation Matrix inherently parsed from Python
        $velocityDelta = (float) ($marketData['velocity_delta'] ?? 0.0);
        $tapeVolume = (float) ($marketData['tape_volume'] ?? 0.0);

        // Positive velocity structurally implies physical absorption natively.
        // Drops static spoof networks mathematically implicitly.
        if ($velocityDelta < 2.0 && $velocityDelta > -2.0) {
            return null; 
        }

        // Tape bounds inherently require $25,000 to physically cross the spread validating intensity natively 
        if ($tapeVolume < 25000.0) {
            return null; 
        }

        $symbol = $marketData['symbol'];
        $maxAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['max_trade_allocation_usdt'] ?? 100000.0);
        $clampedBase = max(10.0, (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['base_allocation_usdt'] ?? 10.0));

        // Phase 2: Dynamic Volume Scale Factor & Risk Profile Matrix
        $ratioScale = min(1.0, ($imbalanceRatio - 7.0) / (14.0 - 7.0)); 
        $targetLeverage = (int) round(2 + (3 * $ratioScale));

        // Retail Risk Calculations (High confidence -> tighter SL, wider TP)
        $dynamicSL = 0.006 - ($ratioScale * 0.003); // Scales from 0.006 to 0.003
        $dynamicTP = 0.010 + ($ratioScale * 0.005); // Scales from 0.010 to 0.015

        $executor = app(\App\Services\BinanceExecutionService::class);
        $minNotional = $executor->getMinNotional($symbol);
        
        // Phase 3: The Minimum Notional Enforcer & Retail Safety Guard
        $worstCaseDrop = max($dynamicSL, $dynamicTP);
        $dynamicPaddingMultiplier = (1.0 / (1.0 - $worstCaseDrop)) * 1.05; 
        
        $notionalEnforcerLeverage = (int) ceil(($minNotional * $dynamicPaddingMultiplier) / $clampedBase);

        if ($notionalEnforcerLeverage > 20) {
            return null; // Hardware retail safety constraint: Leverage exceeds Altcoin capacities natively.
        }

        $targetLeverage = max($targetLeverage, $notionalEnforcerLeverage);
        $scaledAllocation = min($maxAllocation, $clampedBase * $targetLeverage);

        // Safely push API execution modifiers securely before emitting brackets natively
        $executor->setMarginType($symbol, 'CROSSED');
        $executor->setDynamicLeverage($symbol, $targetLeverage);

        if ($bidVolume > $askVolume) {
            return [
                'symbol' => $symbol,
                'side' => 'BUY',
                'usdt_allocation' => $scaledAllocation,
                'sl_percentage' => $dynamicSL,
                'tp_percentage' => $dynamicTP,
            ];
        } else {
            return [
                'symbol' => $symbol,
                'side' => 'SELL',
                'usdt_allocation' => $scaledAllocation,
                'sl_percentage' => $dynamicSL,
                'tp_percentage' => $dynamicTP,
            ];
        }
    }
}
