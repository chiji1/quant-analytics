<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;

class CascadingPanicShortStrategy implements TradingStrategyInterface
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
        $tapeVolume = (float) ($marketData['tape_volume'] ?? 0.0);

        $liqVolume = $price * $qty;

        if ($liqVolume < 15000.0 || $liqVolume < ($tapeVolume * 2.5)) {
            return null; // Absolute Institutional Firewall drops Altcoin noise naturally
        }

        if ($side === 'BUY') {
            $depthCache = \Illuminate\Support\Facades\Cache::get('latest_depth_' . $symbol);
            if (!$depthCache || empty($depthCache['asks'])) {
                return null;
            }

            // Structural Wick Deviation Analysis natively extrapolating array walk
            $summedAskVolume = 0.0;
            $terminalWickPrice = $price;
            $depthAsks = array_slice($depthCache['asks'], 0, 50);

            foreach ($depthAsks as $ask) {
                $levelVol = (float) $ask['price'] * (float) $ask['quantity'];
                $summedAskVolume += $levelVol;
                if ($summedAskVolume >= $liqVolume) {
                    $terminalWickPrice = (float) $ask['price'];
                    break;
                }
            }

            // Map absolute wipeout boundary seamlessly if liquidation eats the entire 50 levels structurally
            if ($summedAskVolume < $liqVolume) {
                $terminalWickPrice = (float) $depthAsks[count($depthAsks) - 1]['price'];
            }

            $wickDeviation = (($terminalWickPrice - $price) / $price) * 100;
            if ($wickDeviation < 1.0) {
                return null; // Structurally insignificant slippage deviation (Gap mapping aborted inherently)
            }

            // Volumetric Scaling limits dynamically natively mapped spanning 2x -> 10x
            $ratio = min(5.0, max(1.0, $liqVolume / $summedAskVolume));
            $ratioScale = ($ratio - 1.0) / 4.0;
            $targetLeverage = min(10, (int) round(2 + ($ratioScale * 8)));

            // Retail Risk Profile natively mapped
            $dynamicSL = 0.010 + ($ratioScale * 0.005); // 1.0% to 1.5%
            $dynamicTP = 0.020 + ($ratioScale * 0.015); // 2.0% to 3.5%

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
