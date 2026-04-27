<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('risk_parameters', function (Blueprint $table) {
            $table->decimal('max_trade_allocation_usdt', 16, 2)->default(100000.00);
            $table->boolean('dynamic_leverage_enabled')->default(true);
            $table->json('active_strategies')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('risk_parameters', function (Blueprint $table) {
            $table->dropColumn(['max_trade_allocation_usdt', 'dynamic_leverage_enabled', 'active_strategies']);
        });
    }
};
