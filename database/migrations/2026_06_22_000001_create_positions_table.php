<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of every position the agent opens: the setup and indicators that
 * triggered entry, and the conditions that triggered exit. `chart_path` is an
 * optional pointer to a rendered chart (levels / EMA / signal) for the trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->string('direction', 8);          // LONG | SHORT
            $table->string('signal_type', 24);        // BOUNCE | RETEST | ...
            $table->boolean('confluence')->default(false);
            $table->string('status', 12)->default('open'); // open | closed

            // Trade plan.
            $table->decimal('entry_price', 24, 10);
            $table->decimal('stop_price', 24, 10);
            $table->decimal('target1', 24, 10);
            $table->decimal('target2', 24, 10);
            $table->decimal('rr_ratio', 12, 4)->default(0);
            $table->decimal('quantity', 30, 10)->default(0);
            $table->decimal('size', 8, 4)->default(1); // remaining fraction (1.0 = full)

            // Exit.
            $table->string('exit_type', 24)->nullable();
            $table->string('exit_reason', 24)->nullable();
            $table->decimal('exit_price', 24, 10)->nullable();
            $table->decimal('realized_pnl', 24, 10)->nullable();

            // Why we entered / exited (indicator snapshot + signal payload).
            $table->json('entry_context')->nullable();
            $table->json('exit_context')->nullable();

            // Optional rendered chart with levels/EMA/signal overlays.
            $table->string('chart_path')->nullable();

            $table->string('entry_order_id')->nullable();
            $table->string('exit_order_id')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'interval', 'status']);
            $table->index(['status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
