<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StrategyEvaluation;
use DirectoryIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneStrategyEvaluations extends Command
{
    protected $signature = 'strategy:prune {--days= : Retention period in days}';

    protected $description = 'Prune strategy evaluation logs, specs, and chart images older than specified days';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('trading.cleanup.retention_days', 3));
        if ($days < 1) {
            $days = 3;
        }

        $cutoff = now()->subDays($days);
        $this->info("Pruning strategy evaluations older than {$days} days (before {$cutoff->toDateTimeString()})...");

        $deletedRecords = 0;
        $deletedFiles = 0;

        // Process records in chunks to prevent memory exhaustion
        StrategyEvaluation::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('evaluated_at', '<', $cutoff)
                    ->orWhere(function ($sub) use ($cutoff) {
                        $sub->whereNull('evaluated_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->select(['id', 'chart_path', 'outcome_chart_path'])
            ->chunkById(500, function ($evaluations) use (&$deletedRecords, &$deletedFiles) {
                $ids = [];

                foreach ($evaluations as $eval) {
                    $ids[] = $eval->id;

                    if ($eval->chart_path && File::exists(storage_path('app/public/'.$eval->chart_path))) {
                        File::delete(storage_path('app/public/'.$eval->chart_path));
                        $deletedFiles++;
                    }

                    if ($eval->outcome_chart_path && File::exists(storage_path('app/public/'.$eval->outcome_chart_path))) {
                        File::delete(storage_path('app/public/'.$eval->outcome_chart_path));
                        $deletedFiles++;
                    }

                    $spec1 = storage_path("app/charts/specs/eval_{$eval->id}.json");
                    if (File::exists($spec1)) {
                        File::delete($spec1);
                        $deletedFiles++;
                    }

                    $spec2 = storage_path("app/charts/specs/outcome_{$eval->id}.json");
                    if (File::exists($spec2)) {
                        File::delete($spec2);
                        $deletedFiles++;
                    }
                }

                if ($ids !== []) {
                    $count = StrategyEvaluation::whereIn('id', $ids)->delete();
                    $deletedRecords += $count;
                }
            });

        // Clean up any remaining orphaned files older than cutoff in storage directories
        $evalChartsDir = storage_path('app/public/charts/evaluations');
        if (File::isDirectory($evalChartsDir)) {
            foreach (new DirectoryIterator($evalChartsDir) as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getMTime() < $cutoff->getTimestamp()) {
                    File::delete($fileInfo->getPathname());
                    $deletedFiles++;
                }
            }
        }

        $specsDir = storage_path('app/charts/specs');
        if (File::isDirectory($specsDir)) {
            foreach (new DirectoryIterator($specsDir) as $fileInfo) {
                if ($fileInfo->isFile()
                    && !str_starts_with($fileInfo->getFilename(), 'position_')
                    && $fileInfo->getMTime() < $cutoff->getTimestamp()
                ) {
                    File::delete($fileInfo->getPathname());
                    $deletedFiles++;
                }
            }
        }

        $this->info("Pruning complete. Deleted {$deletedRecords} evaluation records and {$deletedFiles} chart/spec files.");

        return self::SUCCESS;
    }
}
