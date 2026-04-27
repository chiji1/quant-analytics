<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskParameter extends Model
{
    protected $fillable = [
        'allowed_symbols',
        'base_allocation_usdt',
        'max_trade_allocation_usdt',
        'news_sentiment_buy_threshold',
        'news_sentiment_sell_threshold',
        'global_kill_switch',
        'dynamic_leverage_enabled',
        'active_strategies',
    ];

    protected $casts = [
        'allowed_symbols' => 'array',
        'base_allocation_usdt' => 'float',
        'max_trade_allocation_usdt' => 'float',
        'news_sentiment_buy_threshold' => 'float',
        'news_sentiment_sell_threshold' => 'float',
        'global_kill_switch' => 'boolean',
        'dynamic_leverage_enabled' => 'boolean',
        'active_strategies' => 'array',
    ];
}
