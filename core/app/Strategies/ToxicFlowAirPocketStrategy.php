<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;
use Illuminate\Support\Facades\Cache;

class ToxicFlowAirPocketStrategy implements TradingStrategyInterface
{

    public function evaluate(array $marketData): ?array
    {
        if (($marketData['event_type'] ?? '') !== 'forceOrder') {
            return null;
        }

        $symbol = $marketData['symbol'];
        $side = $marketData['side'];
        $liqPrice = (float) $marketData['price'];
        $liqQty = (float) $marketData['original_quantity'];
        
        // We look for SELL liquidations (longs forced out -> negative pressure)
        if ($side !== 'SELL') {
            return null;
        }
        
        $liqVolume = $liqPrice * $liqQty;
        
        $depthMap = Cache::get('latest_depth_' . $symbol);
        if (!$depthMap || !isset($depthMap['bids'])) {
            return null;
        }

        $bids = $depthMap['bids'];
        $asks = $depthMap['asks'] ?? [];
        if (empty($bids) || empty($asks)) return null;

        // 1. Hollow Spread Constraints
        $bestBid = (float) $bids[0]['price']; // Note: mapped as ['price'=>X, 'quantity'=>Y] from Execution Bus
        $bestAsk = (float) $asks[0]['price'];
        if ($bestBid <= 0) return null;
        $spreadPct = ($bestAsk - $bestBid) / $bestBid;
        
        if ($spreadPct > 0.005) {
            \Illuminate\Support\Facades\Log::info("Hollow Spread Trap Evaded: Spread width ({$spreadPct}) structurally exceeds 0.5% limit safely natively.");
            return null;
        }

        // 2. Infinite Drop-Off Guard
        $dropOffDev = abs($liqPrice - $bestBid) / $liqPrice;
        if ($dropOffDev > 0.02) {
            \Illuminate\Support\Facades\Log::info("Infinite VWAP Trap Evaded: Closest Bid natively detached by >2%, dropping short signal safely.");
            return null; 
        }

        $bidSumVolume = 0.0;
        $thresholdPrice = $liqPrice * 0.99; 
        
        // 3. The Contamination Solver (Evaluate LiquiditySweep internally)
        $sweepSumVolume = 0.0;
        foreach (array_slice($bids, 0, 50) as $bid) {
            $bidPrice = (float) $bid['price'];
            $bidQty = (float) $bid['quantity'];
            $sweepSumVolume += ($bidPrice * $bidQty);
            
            if ($bidPrice >= $thresholdPrice) {
                $bidSumVolume += ($bidPrice * $bidQty);
            }
        }

        if ($sweepSumVolume >= ($liqVolume * 1.5)) {
            // Liquidity Sweep condition met. Conflict exists! Abort short natively.
            \Illuminate\Support\Facades\Log::info("Cross-Contamination Avoided: {$symbol} Liquidity Sweep trigger physically overrides vacuum short block.");
            return null; 
        }
        
        // 4. Air Pocket Check
        if ($liqVolume > $bidSumVolume && $bidVolumeVacuumRatio = max(1.0, $liqVolume / max(1.0, $bidSumVolume))) {
            
            if ($bidVolumeVacuumRatio < 1.5) return null; // Baseline constraint limits

            // Predatory Scaling Bounds natively mapped (1.5x ratio = 2x, 4.0x+ ratio = 10x)
            $ratio = min(4.0, max(1.5, $bidVolumeVacuumRatio));
            $ratioScale = ($ratio - 1.5) / 2.5;
            $targetLeverage = (int) round(2 + ($ratioScale * 8));

            // Retail Risk Profile natively mapped
            $dynamicSL = 0.008 - ($ratioScale * 0.002); // 0.8% to 0.6%
            $dynamicTP = 0.010 + ($ratioScale * 0.010); // 1.0% to 2.0%

            $clampedBase = max(10.0, (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['base_allocation_usdt'] ?? 10.0));
            $maxAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['max_trade_allocation_usdt'] ?? 10000.0);
            
            $executor = app(\App\Services\BinanceExecutionService::class);
            $minNotional = $executor->getMinNotional($symbol);
            
            // Phase 3: The Minimum Notional Enforcer & Retail Safety Guard
            $worstCaseDrop = max($dynamicSL, $dynamicTP);
            $dynamicPaddingMultiplier = (1.0 / (1.0 - $worstCaseDrop)) * 1.05; 
            
            $notionalEnforcerLeverage = (int) ceil(($minNotional * $dynamicPaddingMultiplier) / $clampedBase);

            if ($notionalEnforcerLeverage > 20) {
                return null; // Hardware retail safety constraint
            }

            $targetLeverage = max($targetLeverage, $notionalEnforcerLeverage);
            $finalAllocation = min($maxAllocation, $clampedBase * $targetLeverage);

            $executor->setMarginType($symbol);
            $executor->setDynamicLeverage($symbol, $targetLeverage);

            return [
                'symbol' => $symbol,
                'side' => 'SELL',
                'usdt_allocation' => $finalAllocation,
                'sl_percentage' => $dynamicSL,
                'tp_percentage' => $dynamicTP,
            ];
        }

        return null;
    }
}
