<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_evaluations', function (Blueprint $table) {
            $table->string('outcome_chart_path')->nullable()->after('chart_path');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_evaluations', function (Blueprint $table) {
            $table->dropColumn('outcome_chart_path');
        });
    }
};
