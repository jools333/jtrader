<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Position;
use App\Trading\Services\BingXPositionSyncService;
use Illuminate\Console\Command;

/**
 * One-off command to backfill exit_reason for closed positions that have NULL exit_reason.
 * Uses price-based inference by comparing exit_price with stop_price/target1.
 */
class BackfillExitReasonCommand extends Command
{
    protected $signature = 'positions:backfill-exit-reason
                            {--dry-run : Show what would be updated without saving}
                            {--days=30 : How many days back to look}';

    protected $description = 'Backfill exit_reason for closed positions based on exit_price vs stop/target levels';

    public function handle(BingXPositionSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');

        $positions = Position::query()
            ->where('status', Position::STATUS_CLOSED)
            ->where(function ($q) {
                $q->whereNull('exit_reason')
                    ->orWhere('exit_reason', 'exchange_closed');
            })
            ->where('closed_at', '>=', now()->subDays($days))
            ->whereNotNull('exit_price')
            ->where('exit_price', '>', 0)
            ->where('entry_price', '>', 0)
            ->orderBy('closed_at', 'desc')
            ->get();

        $this->info(sprintf('Found %d positions to backfill (last %d days)%s', $positions->count(), $days, $dryRun ? ' [DRY RUN]' : ''));

        $updated = 0;
        foreach ($positions as $pos) {
            $inferred = $syncService->inferExitReasonFromPrice($pos, (float) $pos->exit_price);

            $this->line(sprintf(
                '  #%-5d %-11s %-5s | entry: %.4f | exit: %.4f | stop: %.4f | tp1: %.4f | old: %-16s → new: %s',
                $pos->id,
                $pos->symbol,
                $pos->direction,
                $pos->entry_price,
                $pos->exit_price,
                $pos->stop_price ?? 0.0,
                $pos->target1 ?? 0.0,
                $pos->exit_reason ?? 'NULL',
                $inferred
            ));

            if (! $dryRun) {
                $pos->update(['exit_reason' => $inferred]);
            }
            $updated++;
        }

        $this->info(sprintf('%s %d positions.', $dryRun ? 'Would update' : 'Updated', $updated));

        return self::SUCCESS;
    }
}
