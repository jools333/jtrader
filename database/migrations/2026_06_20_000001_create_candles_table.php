<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->unsignedBigInteger('open_time'); // ms epoch
            $table->decimal('open', 24, 10);
            $table->decimal('high', 24, 10);
            $table->decimal('low', 24, 10);
            $table->decimal('close', 24, 10);
            $table->decimal('volume', 30, 10);
            $table->unsignedBigInteger('close_time');
            $table->timestamps();

            $table->unique(['symbol', 'interval', 'open_time']);
            $table->index(['symbol', 'interval', 'open_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
