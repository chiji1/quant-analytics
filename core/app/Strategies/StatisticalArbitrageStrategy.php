<?php

declare(strict_types=1);

namespace App\Strategies;

use App\Contracts\TradingStrategyInterface;
use Illuminate\Support\Facades\Redis;

class StatisticalArbitrageStrategy implements TradingStrategyInterface
{
    private const WINDOW_SIZE = 100;

    public function evaluate(array $marketData): ?array
    {
        if (($marketData['event_type'] ?? '') !== 'depthUpdate') {
            return null;
        }

        $symbol = $marketData['symbol'];
        
        if (!isset($marketData['bids'][0][0])) return null;
        $price = (float) $marketData['bids'][0][0];

        // Ensure state logic for the ETH/BTC spread
        if ($symbol === 'BTCUSDT') {
            Redis::set('latest_price_BTCUSDT', (string) $price);
            return null; 
        } elseif ($symbol === 'ETHUSDT') {
            Redis::set('latest_price_ETHUSDT', (string) $price);
            $btcPrice = (float) Redis::get('latest_price_BTCUSDT');
            
            if (!$btcPrice) return null;
            
            $ratio = $price / $btcPrice;
            $listKey = 'stat_arb_ratio_window';
            $lastUpdateKey = 'stat_arb_last_update';
            
            $lastUpdate = (float) Redis::get($lastUpdateKey);
            $now = microtime(true);
            
            // Stale Data Spiral Flush natively avoiding ghost WebSocket gaps
            if ($lastUpdate > 0 && ($now - $lastUpdate) > 60.0) {
                Redis::del($listKey);
                Redis::set($lastUpdateKey, (string) $now);
                \Illuminate\Support\Facades\Log::warning("Stat Arb WebSocket Gap Detected (>60s). Flushed Moving Average window inherently rebuilding organically securely.");
                return null;
            }

            if (($now - $lastUpdate) >= 5.0) {
                Redis::set($lastUpdateKey, (string) $now);
                Redis::lpush($listKey, (string) $ratio);
                Redis::ltrim($listKey, 0, self::WINDOW_SIZE - 1);
            }
            
            $list = Redis::lrange($listKey, 0, -1);
            if (count($list) < self::WINDOW_SIZE) return null; // Wait for full warm-up
            
            $sum = array_sum($list);
            $mean = $sum / self::WINDOW_SIZE;
            
            $variance = 0.0;
            foreach ($list as $val) {
                $variance += pow((float)$val - $mean, 2);
            }
            $stdDev = sqrt($variance / self::WINDOW_SIZE);
            if ($stdDev == 0) return null;
            
            $zScore = ($ratio - $mean) / $stdDev;
            
            // Regime Shift Killswitch mathematically terminating structural un-hedged paradigm gaps
            if (abs($zScore) >= 3.0) {
                return null;
            }
            
            $clampedBase = max(10.0, (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['base_allocation_usdt'] ?? 10.0));
            $maxAllocation = (float) (\Illuminate\Support\Facades\Cache::get('system:risk_parameters')['max_trade_allocation_usdt'] ?? 10000.0);
            
            // Native Volumetric Leverage Integration securely scaling logically 2x to 4x natively
            $targetLeverage = 2; // Default starting bounce threshold
            if (abs($zScore) > 2.0) {
                $modifier = min(1.0, (abs($zScore) - 2.0) / (3.0 - 2.0));
                $targetLeverage = (int) round(2 + (2 * $modifier)); 
            }
            
            $executor = app(\App\Services\BinanceExecutionService::class);
            $ethMinNotional = $executor->getMinNotional('ETHUSDT');
            $btcMinNotional = $executor->getMinNotional('BTCUSDT');
            $maxNotionalRequirement = max($ethMinNotional, $btcMinNotional);

            // Retail Risk Profile natively mapped
            $dynamicSL = 0.015; // Z-Score baseline
            $ethDynamicTP = abs($ratio - $mean) / $ratio;
            $btcDynamicTP = 0.01;

            $worstCaseDrop = max($dynamicSL, $ethDynamicTP, $btcDynamicTP);
            $dynamicPaddingMultiplier = (1.0 / (1.0 - $worstCaseDrop)) * 1.05; 

            // Minimum Notional Enforcer physically wrapping execution globally inherently
            $notionalEnforcerLeverage = (int) ceil(($maxNotionalRequirement * $dynamicPaddingMultiplier) / $clampedBase);

            if ($notionalEnforcerLeverage > 20) {
                return null; // Hardware retail safety constraint
            }

            $targetLeverage = max($targetLeverage, $notionalEnforcerLeverage);
            $finalAllocation = min($maxAllocation, $clampedBase * $targetLeverage);

            if ($zScore > 2.0) {
                // ETH is significantly overvalued vs BTC. Revert. SELL ETH, BUY BTC natively.
                $executor->setMarginType('ETHUSDT');
                $executor->setMarginType('BTCUSDT');
                $executor->setDynamicLeverage('ETHUSDT', $targetLeverage);
                $executor->setDynamicLeverage('BTCUSDT', $targetLeverage);

                return [
                    [
                        'symbol' => 'ETHUSDT',
                        'side' => 'SELL',
                        'usdt_allocation' => $finalAllocation,
                        'sl_percentage' => $dynamicSL,
                        'tp_percentage' => $ethDynamicTP,
                    ],
                    [
                        'symbol' => 'BTCUSDT',
                        'side' => 'BUY',
                        'usdt_allocation' => $finalAllocation,
                        'sl_percentage' => $dynamicSL,
                        'tp_percentage' => $btcDynamicTP,
                    ]
                ];
            } elseif ($zScore < -2.0) {
                // ETH is significantly undervalued vs BTC. Revert. BUY ETH, SELL BTC natively.
                $executor->setMarginType('ETHUSDT');
                $executor->setMarginType('BTCUSDT');
                $executor->setDynamicLeverage('ETHUSDT', $targetLeverage);
                $executor->setDynamicLeverage('BTCUSDT', $targetLeverage);

                return [
                    [
                        'symbol' => 'ETHUSDT',
                        'side' => 'BUY',
                        'usdt_allocation' => $finalAllocation,
                        'sl_percentage' => $dynamicSL,
                        'tp_percentage' => $ethDynamicTP,
                    ],
                    [
                        'symbol' => 'BTCUSDT',
                        'side' => 'SELL',
                        'usdt_allocation' => $finalAllocation,
                        'sl_percentage' => $dynamicSL,
                        'tp_percentage' => $btcDynamicTP,
                    ]
                ];
            }
        }
        
        return null;
    }
}
