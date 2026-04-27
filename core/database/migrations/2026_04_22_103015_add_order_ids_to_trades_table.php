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
        Schema::table('trades', function (Blueprint $table) {
            $table->string('stop_loss_order_id')->nullable()->after('fill_price');
            $table->string('take_profit_order_id')->nullable()->after('stop_loss_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn(['stop_loss_order_id', 'take_profit_order_id']);
        });
    }
};
