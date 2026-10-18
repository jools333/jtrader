<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe key for strategy evaluations.
 *
 * The agent rescans the same closed candle every 30 seconds; without this index
 * the per-candle existence check is a table scan and the table grows with tens
 * of thousands of duplicate rows per day (which previously flooded the Redis
 * queue with chart jobs and exhausted server memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_evaluations', function (Blueprint $table) {
            $table->index(
                ['strategy', 'symbol', 'interval', 'direction', 'candle_open_time'],
                'se_dedupe_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('strategy_evaluations', function (Blueprint $table) {
            $table->dropIndex('se_dedupe_idx');
        });
    }
};
