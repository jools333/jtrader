<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of strategy evaluations: records both full (100%) and partial (>= 50%)
 * setups with detailed criteria pass/fail breakdown to analyse missed trades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->nullable();
            $table->string('interval', 8)->nullable();
            $table->string('strategy', 48);                // BounceStrategy, etc.
            $table->string('direction', 8);               // LONG | SHORT
            $table->string('status', 16)->default('partial'); // completed | partial

            // Progress scoring
            $table->decimal('score', 5, 2);               // 0.00 to 100.00 (%)
            $table->unsignedTinyInteger('passed_count');  // e.g. 5
            $table->unsignedTinyInteger('total_count');   // e.g. 7

            // Market parameters at evaluation
            $table->decimal('level', 24, 10);
            $table->decimal('atr', 24, 10);
            $table->decimal('current_price', 24, 10);

            // Trade plan if formed
            $table->decimal('entry_price', 24, 10)->nullable();
            $table->decimal('stop_price', 24, 10)->nullable();
            $table->decimal('target1', 24, 10)->nullable();
            $table->decimal('target2', 24, 10)->nullable();
            $table->decimal('rr_ratio', 12, 4)->nullable();

            // Diagnostic analysis
            $table->json('missing_criteria')->nullable();    // List of failed condition descriptions
            $table->json('criteria_breakdown')->nullable();  // Full detailed criteria results
            $table->json('indicators')->nullable();          // EMA / MACD snapshot if available

            // Candle timing
            $table->unsignedBigInteger('candle_open_time')->nullable();
            $table->timestamp('evaluated_at')->useCurrent();
            $table->timestamps();

            $table->index(['symbol', 'interval', 'evaluated_at']);
            $table->index(['strategy', 'score']);
            $table->index(['status', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_evaluations');
    }
};
