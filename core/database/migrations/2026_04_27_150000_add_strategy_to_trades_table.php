<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('trades')) {
            Schema::table('trades', function (Blueprint $table) {
                if (!Schema::hasColumn('trades', 'strategy')) {
                    $table->string('strategy')->nullable()->after('side');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('trades')) {
            Schema::table('trades', function (Blueprint $table) {
                if (Schema::hasColumn('trades', 'strategy')) {
                    $table->dropColumn('strategy');
                }
            });
        }
    }
};
