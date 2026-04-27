<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Interface TradingStrategyInterface
 * 
 * Defines the contract for all trading strategies in the engine.
 * Strategies evaluate market data to determine if a trade should be executed.
 */
interface TradingStrategyInterface
{
    /**
     * Evaluates the provided market data to determine entry.
     * 
     * @param array $marketData The market data payload (e.g. from Redis).
     * @return array|null Returns a strict Bracket Order parameter array if valid, null otherwise.
     */
    public function evaluate(array $marketData): ?array;
}
