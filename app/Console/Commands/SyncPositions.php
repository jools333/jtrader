<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Trading\Services\BingXPositionSyncService;
use Illuminate\Console\Command;

class SyncPositions extends Command
{
    protected $signature = 'positions:sync 
        {--symbol= : Specific symbol to sync} 
        {--days=3 : Lookback days for reconciling closed positions} 
        {--dry-run : Only report changes without writing to database}';

    protected $description = 'Synchronize positions, accurate commissions, and execution prices with BingX';

    public function handle(BingXPositionSyncService $syncService): int
    {
        $symbol = $this->option('symbol') ? (string) $this->option('symbol') : null;
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN MODE] No changes will be written to the database.');
        }

        $this->info("Starting position synchronization with BingX (lookback: {$days} days)...");

        $result = $syncService->sync(
            targetSymbol: $symbol,
            lookbackDays: $days,
            dryRun: $dryRun,
        );

        if (! empty($result->messages)) {
            foreach ($result->messages as $msg) {
                $this->line("  • {$msg}");
            }
        }

        $this->table(
            ['Imported (New)', 'Closed on BingX', 'Updated / Fees refreshed'],
            [[$result->imported, $result->closed, $result->updated]]
        );

        $this->info('Position synchronization finished.');

        return self::SUCCESS;
    }
}
