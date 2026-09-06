<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Trading\Services\BingXPositionSyncService;
use App\Trading\Services\DailyPositionReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDailyPositionReport extends Command
{
    protected $signature = 'report:daily-telegram
        {--date= : Specific date for report (YYYY-MM-DD)}
        {--yesterday : Generate report for yesterday}
        {--sync : Synchronize positions with BingX before generating report}
        {--dry-run : Output report to console without sending to Telegram}
        {--chat= : Override Telegram chat / channel ID}';

    protected $description = 'Send daily positions performance report to Telegram channel';

    public function handle(
        DailyPositionReportService $reportService,
        BingXPositionSyncService $syncService,
    ): int {
        $tz = (string) config('services.telegram.report_timezone', config('app.timezone', 'UTC'));

        if ($this->option('sync')) {
            $this->info('Synchronizing latest positions and fees with BingX...');
            $syncResult = $syncService->sync(lookbackDays: 2);
            $this->line("  • Sync result: Imported={$syncResult->imported}, Closed={$syncResult->closed}, Updated={$syncResult->updated}");
        }

        // Determine target date
        if ($this->option('yesterday')) {
            $targetDate = Carbon::now($tz)->subDay()->format('Y-m-d');
        } elseif ($this->option('date')) {
            $targetDate = (string) $this->option('date');
        } else {
            $targetDate = Carbon::now($tz)->format('Y-m-d');
        }

        $dryRun = (bool) $this->option('dry-run');
        $chatId = $this->option('chat') ? (string) $this->option('chat') : null;

        $this->info("Generating position report for date: {$targetDate} ({$tz})...");

        if ($dryRun) {
            $carbonDate = Carbon::parse($targetDate, $tz);
            $data = $reportService->buildReportData($carbonDate, $tz);
            $chunks = $reportService->formatHtmlReport($data);

            $this->newLine();
            $this->warn('--- [DRY RUN: TELEGRAM MESSAGE PREVIEW] ---');
            foreach ($chunks as $idx => $chunk) {
                if (count($chunks) > 1) {
                    $this->comment('--- Chunk '.($idx + 1).' / '.count($chunks).' ---');
                }
                $this->line($chunk);
            }
            $this->warn('--- [END PREVIEW] ---');
            $this->newLine();

            $this->table(
                ['Date', 'Timezone', 'Closed', 'Win Rate', 'Gross P&L', 'Fees', 'Net P&L', 'Status'],
                [[
                    $targetDate,
                    $tz,
                    $data['stats']['total_closed'],
                    $data['stats']['win_rate'].'%',
                    $data['stats']['gross_pnl'].' USDT',
                    $data['stats']['total_fees'].' USDT',
                    $data['stats']['net_pnl'].' USDT',
                    'DRY RUN (Not sent)',
                ]]
            );

            return self::SUCCESS;
        }

        $result = $reportService->sendReport(
            date: $targetDate,
            chatId: $chatId,
            timezone: $tz,
        );

        $statusLabel = $result['sent'] ? 'SENT' : 'NOT SENT';
        if ($result['sent']) {
            $this->info("Report successfully sent to Telegram channel ({$targetDate})!");
        } else {
            $this->error('Failed to send report: '.($result['error'] ?? 'Unknown error'));
        }

        $this->table(
            ['Date', 'Timezone', 'Closed', 'Open', 'Net P&L', 'Status'],
            [[
                $result['date'],
                $result['timezone'],
                $result['closed_count'],
                $result['open_count'],
                $result['net_pnl'].' USDT',
                $statusLabel,
            ]]
        );

        return $result['sent'] ? self::SUCCESS : self::FAILURE;
    }
}
