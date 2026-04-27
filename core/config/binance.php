<?php

declare(strict_types=1);

/**
 * Binance Configuration
 * 
 * Securely loads Binance API credentials from the environment.
 */
return [
    'api_key'     => env('BINANCE_API_KEY', ''),
    'api_secret'  => env('BINANCE_API_SECRET', ''),
    'testnet_url' => env('BINANCE_TESTNET_URL', 'https://testnet.binancefuture.com'),
];
