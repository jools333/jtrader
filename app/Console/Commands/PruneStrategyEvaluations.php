<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StrategyEvaluation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneStrategyEvaluations extends Command
{
    protected $signature = 'strategy:prune {--days= : Retention period in days}';

    protected $description = 'Prune strategy evaluation logs, specs, and chart images older than specified days';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('trading.cleanup.retention_days', 7));
        if ($days < 1) {
            $days = 7;
        }

        $cutoff = now()->subDays($days);
        $this->info("Pruning strategy evaluations older than {$days} days (before {$cutoff->toDateTimeString()})...");

        $evaluations = StrategyEvaluation::query()
            ->where('evaluated_at', '<', $cutoff)
            ->orWhere(function ($query) use ($cutoff) {
                $query->whereNull('evaluated_at')->where('created_at', '<', $cutoff);
            })
            ->get();

        $deletedRecords = 0;
        $deletedFiles = 0;

        foreach ($evaluations as $eval) {
            /** @var StrategyEvaluation $eval */
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

            $eval->delete();
            $deletedRecords++;
        }

        // Clean up any remaining orphaned files older than cutoff in storage directories
        $evalChartsDir = storage_path('app/public/charts/evaluations');
        if (File::isDirectory($evalChartsDir)) {
            foreach (File::files($evalChartsDir) as $file) {
                if ($file->getMTime() < $cutoff->getTimestamp()) {
                    File::delete($file->getPathname());
                    $deletedFiles++;
                }
            }
        }

        $specsDir = storage_path('app/charts/specs');
        if (File::isDirectory($specsDir)) {
            foreach (File::files($specsDir) as $file) {
                if ($file->getMTime() < $cutoff->getTimestamp()) {
                    File::delete($file->getPathname());
                    $deletedFiles++;
                }
            }
        }

        $this->info("Pruning complete. Deleted {$deletedRecords} evaluation records and {$deletedFiles} chart/spec files.");

        return self::SUCCESS;
    }
}
