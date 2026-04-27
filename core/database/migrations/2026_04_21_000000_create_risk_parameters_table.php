<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_parameters', function (Blueprint $table) {
            $table->id();
            $table->json('allowed_symbols');
            $table->decimal('base_allocation_usdt', 16, 4)->default(10000.0);
            $table->decimal('news_sentiment_buy_threshold', 8, 4)->default(0.85);
            $table->decimal('news_sentiment_sell_threshold', 8, 4)->default(-0.85);
            $table->boolean('global_kill_switch')->default(false);
            $table->timestamps();
        });

        // Insert Default Global Parameters on migration
        DB::table('risk_parameters')->insert([
            'allowed_symbols' => json_encode([]), // Default blocked
            'base_allocation_usdt' => 10000.0,
            'news_sentiment_buy_threshold' => 0.85,
            'news_sentiment_sell_threshold' => -0.85,
            'global_kill_switch' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_parameters');
    }
};
