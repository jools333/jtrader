<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('commission', 24, 10)->default(0)->after('realized_pnl');
            $table->decimal('funding_fee', 24, 10)->default(0)->after('commission');
            $table->unsignedTinyInteger('leverage')->nullable()->after('funding_fee');
            $table->string('external_id', 64)->nullable()->after('exit_order_id')->index();
            $table->timestamp('synced_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'commission',
                'funding_fee',
                'leverage',
                'external_id',
                'synced_at',
            ]);
        });
    }
};
