<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Trade;
use App\Services\BinanceExecutionService;
use Illuminate\Support\Facades\Log;

class SyncBinancePositionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:sync-positions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Structurally syncs local database execution states directly with physical Binance open limits unconditionally.';

    /**
     * Execute the console command.
     */
    public function handle(BinanceExecutionService $executor)
    {
        $this->info("Initializing Binance physical synchronization garbage sweep...");

        try {
            // 1. Map open local limits purely sequentially
            $localOpenTrades = Trade::where('status', 'open')->get();

            if ($localOpenTrades->isEmpty()) {
                $this->info("No active algorithmic trades physically locked locally. Sweep Complete.");
                return Command::SUCCESS;
            }

            // 2. Map structural API physical limits organically
            $binancePositions = $executor->fetchOpenPositions();

            $closures = 0;

            // 3. Sweep organically avoiding array boundaries
            foreach ($localOpenTrades as $trade) {
                // If the coin is no longer in Binance's physical open ledger (value array), it mathematically closed on the exchange organically.
                if (!isset($binancePositions[$trade->symbol])) {
                    Log::info("Physical sync successfully mapped {$trade->symbol} organically disconnected. Clearing database lock intrinsically.");
                    
                    $trade->update([
                        'status' => 'closed',
                        // Note: To natively record perfectly accurate PNL fill details organically without User Data Streams, 
                        // one would append a GET /fapi/v1/userTrades call here to map exact exit prices. 
                        // For stabilization, purely dissolving the lock is the mathematical priority natively.
                    ]);
                    
                    $closures++;
                }
            }

            $this->info("Sync successful organically natively. Total locks formally dropped: {$closures}");
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error("Physical Sync Command Fault natively bypassed: " . $e->getMessage());
            $this->error("Physical Sync Command Fault natively bypassed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
