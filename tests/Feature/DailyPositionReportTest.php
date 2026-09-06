<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Position;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Services\DailyPositionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyPositionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_stats_with_closed_positions(): void
    {
        // 1 winning LONG
        Position::create([
            'symbol' => 'ETH-USDT',
            'interval' => '5m',
            'direction' => Direction::Long->value,
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 3000.0,
            'stop_price' => 2950.0,
            'target1' => 3100.0,
            'target2' => 3200.0,
            'exit_price' => 3050.0,
            'quantity' => 1.0,
            'size' => 1.0,
            'exit_type' => ExitType::Target1->value,
            'realized_pnl' => 50.0,
            'commission' => 2.0,
            'funding_fee' => 0.5,
            'opened_at' => Carbon::parse('2026-09-04 10:00:00'),
            'closed_at' => Carbon::parse('2026-09-04 11:30:00'),
        ]);

        // 1 losing SHORT
        Position::create([
            'symbol' => 'SOL-USDT',
            'interval' => '5m',
            'direction' => Direction::Short->value,
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 100.0,
            'stop_price' => 106.0,
            'target1' => 90.0,
            'target2' => 85.0,
            'exit_price' => 105.0,
            'quantity' => 2.0,
            'size' => 1.0,
            'exit_type' => ExitType::StopLoss->value,
            'realized_pnl' => -10.0,
            'commission' => 1.0,
            'funding_fee' => 0.0,
            'opened_at' => Carbon::parse('2026-09-04 12:00:00'),
            'closed_at' => Carbon::parse('2026-09-04 12:15:00'),
        ]);

        // 1 position on a different day (should be ignored for 2026-09-04)
        Position::create([
            'symbol' => 'BTC-USDT',
            'interval' => '5m',
            'direction' => Direction::Long->value,
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 60000.0,
            'stop_price' => 59000.0,
            'target1' => 62000.0,
            'target2' => 64000.0,
            'exit_price' => 61000.0,
            'quantity' => 0.1,
            'size' => 1.0,
            'exit_type' => ExitType::Target1->value,
            'realized_pnl' => 100.0,
            'commission' => 5.0,
            'funding_fee' => 0.0,
            'opened_at' => Carbon::parse('2026-09-03 10:00:00'),
            'closed_at' => Carbon::parse('2026-09-03 11:00:00'),
        ]);

        $service = app(DailyPositionReportService::class);
        $reportData = $service->buildReportData(Carbon::parse('2026-09-04', 'UTC'), 'UTC');

        $this->assertCount(2, $reportData['closed_positions']);
        $stats = $reportData['stats'];

        $this->assertSame(2, $stats['total_closed']);
        $this->assertSame(1, $stats['wins']);
        $this->assertSame(1, $stats['losses']);
        $this->assertSame(50.0, $stats['win_rate']);
        $this->assertSame(40.0, $stats['gross_pnl']); // 50 - 10
        $this->assertSame(3.0, $stats['commission']); // 2 + 1
        $this->assertSame(0.5, $stats['funding_fee']);
        $this->assertSame(3.5, $stats['total_fees']);
        $this->assertSame(36.5, $stats['net_pnl']); // 40 - 3.5

        $this->assertSame(1, $stats['long_count']);
        $this->assertSame(47.5, $stats['long_net']); // 50 - 2.5
        $this->assertSame(1, $stats['short_count']);
        $this->assertSame(-11.0, $stats['short_net']); // -10 - 1.0

        $this->assertSame('ETH-USDT', $stats['best_trade']->symbol);
        $this->assertSame('SOL-USDT', $stats['worst_trade']->symbol);

        $chunks = $service->formatHtmlReport($reportData);
        $this->assertNotEmpty($chunks);
        $text = $chunks[0];
        $this->assertStringContainsString('Отчет по позициям за 04.09.2026', $text);
        $this->assertStringContainsString('Чистый P&L: <code>+36.50 USDT</code> 🟢', $text);
        $this->assertStringContainsString('#ETH-USDT LONG', $text);
        $this->assertStringContainsString('#SOL-USDT SHORT', $text);
    }

    public function test_format_empty_day_report(): void
    {
        $service = app(DailyPositionReportService::class);
        $reportData = $service->buildReportData(Carbon::parse('2026-09-05', 'UTC'), 'UTC');

        $chunks = $service->formatHtmlReport($reportData);
        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('За выбранный день закрытых сделок не было', $chunks[0]);
    }

    public function test_command_dry_run_executes_successfully(): void
    {
        $this->artisan('report:daily-telegram', [
            '--date' => '2026-09-04',
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    public function test_command_sends_to_telegram_when_configured(): void
    {
        config([
            'services.telegram.bot_token' => '123456:FAKE_TOKEN',
            'services.telegram.chat_id' => '-100999999999',
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:FAKE_TOKEN/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 12345],
            ], 200),
        ]);

        $this->artisan('report:daily-telegram', [
            '--date' => '2026-09-04',
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:FAKE_TOKEN/sendMessage'
                && $request['chat_id'] === '-100999999999';
        });
    }

    public function test_command_fails_when_telegram_not_configured(): void
    {
        config([
            'services.telegram.bot_token' => '',
            'services.telegram.chat_id' => '',
        ]);

        $this->artisan('report:daily-telegram', [
            '--date' => '2026-09-04',
        ])->assertFailed();
    }
}
