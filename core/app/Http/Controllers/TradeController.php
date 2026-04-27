<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    /**
     * Natively resolves the active algorithmic bracket ledger cleanly for the Dashboard history sequence natively without WebSockets.
     */
    public function index()
    {
        $trades = Trade::latest()->take(50)->get()->map(function ($trade) {
            return [
                'id' => $trade->id,
                'symbol' => $trade->symbol,
                'side' => $trade->side,
                'price' => $trade->fill_price, // Remap schema nomenclature safely back into typical React UI interface structure keys
                'quantity' => $trade->executed_quantity,
                'timestamp' => $trade->created_at->timestamp * 1000,
                'status' => $trade->status,
            ];
        });

        return response()->json($trades);
    }
}
