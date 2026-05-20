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

        if ($bestAsk <= 0 || $bestBid <= 0) return null;

        $velocityEma = (float) ($marketData['velocity_ema'] ?? 0.0);

        // Phase 1: Dynamic Spread Circuit Breaker scaling to market surge
        $spreadPercentage = (($bestAsk - $bestBid) / $bestAsk) * 100;
        $maxSpread = (abs($velocityEma) > 10.0) ? 0.45 : 0.15;
        
        if ($spreadPercentage > $maxSpread) {
            return null;
        }

        // Phase 2: Anti-Spoofing Deep Book Matrix
        $bids = array_slice($marketData['bids'], 0, 20);
        $asks = array_slice($marketData['asks'], 0, 20);

        // Inverted Mid-Book Weighting (Ignores Front-Book HFT noise)
        $rawWeights = [
            0.1, 0.1, 0.2, 0.2, // Levels 0-3
            1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, // Levels 4-12
            0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2 // Levels 13-19
        ];

        $bidsCount = count($bids);
        if ($bidsCount < 10) return null;
        $normalizer = 20.0 / $bidsCount; 

        $bidVolume = 0.0;
        foreach ($bids as $index => $bid) {
            if (!isset($rawWeights[$index]) || !isset($bid[0]) || !isset($bid[1])) continue;
            $volume = ((float)$bid[0] * (float)$bid[1]);
            $multiplier = $rawWeights[$index] * $normalizer;
            $bidVolume += ($volume * $multiplier);
        }

        $asksCount = count($asks);
        if ($asksCount < 10) return null;
        $normalizerAsk = 20.0 / $asksCount;

        $askVolume = 0.0;
        foreach ($asks as $index => $ask) {
            if (!isset($rawWeights[$index]) || !isset($ask[0]) || !isset($ask[1])) continue;
            $volume = ((float)$ask[0] * (float)$ask[1]);
            $multiplier = $rawWeights[$index] * $normalizerAsk;
            $askVolume += ($volume * $multiplier);
        }

        if ($bidVolume === 0.0 || $askVolume === 0.0) return null;

        $imbalanceRatio = max($bidVolume, $askVolume) / min($bidVolume, $askVolume);

        if ($imbalanceRatio < 7.0) {
            return null; 
        }

        // Phase 3 & 4: Institutional Volumetric Validity
        $velocityDelta = (float) ($marketData['velocity_delta'] ?? 0.0);

        // Drop static spoof matrices
        if (abs($velocityDelta) < 2.0) {
            return null; 
        }

        $symbol = $marketData['symbol'];
        $imbalanceDirection = ($bidVolume > $askVolume) ? 'BUY' : 'SELL';

        // BTC Gravity Vector Check & News Decoupling ByPass
        $btcVelocity = (float) \Illuminate\Support\Facades\Cache::get('system:btc_velocity_ema', 0.0);
        $isOpposingBtc = ($imbalanceDirection === 'BUY' && $btcVelocity < -2.0) || ($imbalanceDirection === 'SELL' && $btcVelocity > 2.0);
        
        if ($isOpposingBtc) {
            // Tier 1 Dual-Layer Momentum Guard: Pair must be experiencing explosive immediate volume (HFT) AND sustained structural 5-m surges.
            if (!(abs($velocityDelta) > 10.0 && abs($velocityEma) > 3.0)) { 
                return null;
            }
        }

        // Phase 2: Inverted Risk Profile Matrix (Volatility-Damped Pre-Trade scaling)
        $ratioScale = min(1.0, ($imbalanceRatio - 7.0) / (14.0 - 7.0)); 
        
        // Target Leverage drops as Confidence increases
        $targetLeverage = (int) round(5.0 - (3.0 * $ratioScale)); // Scales 5x down to 2x 

        // Stop Loss widens as Confidence increases
        $dynamicSL = 0.006 + ($ratioScale * 0.006); // Scales 0.006 up to 0.012
        $dynamicTP = 0.010 + ($ratioScale * 0.005); // Scales 0.010 up to 0.015

        $maxAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['max_trade_allocation_usdt'] ?? 100000.0);
        $clampedBase = max(10.0, (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['base_allocation_usdt'] ?? 10.0));

        $executor = app(\App\Services\BinanceExecutionService::class);
        $minNotional = $executor->getMinNotional($symbol);
        
        // Phase 3: The Minimum Notional Base-Allocation Bump Rescue
        $worstCaseDrop = max($dynamicSL, $dynamicTP);
        $dynamicPaddingMultiplier = (1.0 / (1.0 - $worstCaseDrop)) * 1.05; 
        
        $requiredNotional = $minNotional * $dynamicPaddingMultiplier;
        $organicNotional = $clampedBase * $targetLeverage;
        
        if ($organicNotional < $requiredNotional) {
            // Bounce the unleveraged base limit natively up
            $bumpedBase = $requiredNotional / $targetLeverage;
            if ($bumpedBase > $maxAllocation) {
                return null; 
            }
            $clampedBase = $bumpedBase;
        }

        $scaledAllocation = min($maxAllocation, $clampedBase * $targetLeverage);

        return [
            'symbol' => $symbol,
            'side' => $imbalanceDirection,
            'usdt_allocation' => $scaledAllocation,
            'sl_percentage' => $dynamicSL,
            'tp_percentage' => $dynamicTP,
            'ioc_price' => ($imbalanceDirection === 'BUY') ? $bestAsk : $bestBid,
            'target_leverage' => $targetLeverage
        ];
    }
}
