<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;

class LiquiditySweepStrategy implements TradingStrategyInterface
{

    public function evaluate(array $marketData): ?array
    {
        if (($marketData['event_type'] ?? '') !== 'forceOrder') {
            return null;
        }

        $symbol = $marketData['symbol'];
        $side = $marketData['side'];
        $price = (float) $marketData['price'];
        $qty = (float) $marketData['original_quantity'];

        $liqVolume = $price * $qty;
        
        if ($side === 'SELL' && $liqVolume >= 5000000.0) {
            $depthCache = \Illuminate\Support\Facades\Cache::get('latest_depth_' . $symbol);
            if (!$depthCache || empty($depthCache['bids'])) {
                return null;
            }

            // Cascade Knife Breaker organically summing Bids[0:50]
            $summedBidVolume = 0.0;
            foreach (array_slice($depthCache['bids'], 0, 50) as $bid) {
                // $bid is mapped natively as ['price' => X, 'quantity' => Y] in the Execution Bus
                $summedBidVolume += (float) $bid['price'] * (float) $bid['quantity'];
            }

            if ($summedBidVolume < ($liqVolume * 1.5)) {
                \Illuminate\Support\Facades\Log::info("Cascade Knife Breaker: Aborting Liquidity Sweep for {$symbol}. Liquidated volume ({$liqVolume}) overpowers Bid depth ({$summedBidVolume}).");
                return null; // Hollow floor, crash continues
            }

            // Geometric Interpolation (2x to 8x)
            $ratio = min(5.0, max(1.5, $summedBidVolume / $liqVolume)); 
            $ratioScale = ($ratio - 1.5) / 3.5;
            $targetLeverage = (int) round(2 + ($ratioScale * 6));

            // Retail Risk Profile natively mapped
            $dynamicSL = 0.005 - ($ratioScale * 0.002); // 0.5% to 0.3%
            $dynamicTP = 0.015 + ($ratioScale * 0.005); // 1.5% to 2.0%

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
                'side' => 'BUY',
                'usdt_allocation' => $finalAllocation,
                'sl_percentage' => $dynamicSL,
                'tp_percentage' => $dynamicTP,
            ];
        }

        return null;
    }
}
