<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Market\DTO\Candle;
use App\Models\StrategyEvaluation;
use App\Models\User;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Agent\TradingAgent;
use App\Trading\DTO\CriterionResult;
use App\Trading\DTO\StrategyEvaluationResult;
use App\Trading\Enums\Direction;
use App\Trading\Services\DatabaseStrategyLogger;
use App\Trading\Strategies\Entry\BounceStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $vol = 1.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $vol, $this->t + 3_599_999);
        $this->t += 3_600_000;

        return $candle;
    }

    private function baseline(int $n, float $start, float $end): array
    {
        $candles = [];
        $step = ($end - $start) / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $c = $start + $step * $i;
            $candles[] = $this->candle($c - 0.1, $c + 1, $c - 1, $c);
        }

        return $candles;
    }

    public function test_bounce_strategy_diagnoses_partial_setup_and_missing_criteria(): void
    {
        $atr = 10.0;
        $level = 100.0;

        // Baseline approach
        $candles = $this->baseline(45, 100, 100);
        for ($i = 0; $i < 10; $i++) {
            $candles[] = $this->candle(100.0, 100.5, 99.5, 100.0);
        }

        $strategy = new BounceStrategy();
        $ctx = new RuleContext($candles, $level, $atr, [], [], [], ['line' => [], 'signal' => [], 'histogram' => []], 'BTC-USDT', '5m');
        $planner = new TradePlanner();

        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->isFullSignal);
        $this->assertGreaterThanOrEqual(30.0, $diag->score);
        $this->assertNotEmpty($diag->missingCriteria);
        $this->assertArrayHasKey('level_approach', $diag->criteria);
        $this->assertTrue($diag->criteria['level_approach']->passed);
    }

    public function test_database_logger_persists_evaluations_above_threshold(): void
    {
        $logger = new DatabaseStrategyLogger(50.0);

        $result = new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Long,
            score: 71.4,
            passedCount: 5,
            totalCount: 11,
            isFullSignal: false,
            entrySignal: null,
            level: 100.0,
            atr: 10.0,
            currentPrice: 101.0,
            criteria: [
                'prior_peak' => new CriterionResult('prior_peak', 'Предшествующий импульс', true, '>= 103.5', '104.5'),
                'compression' => new CriterionResult('compression', 'Компрессия', false, '>= 1', '0'),
            ],
            missingCriteria: ['Компрессия'],
            symbol: 'ETH-USDT',
            interval: '15m',
            candleOpenTime: 1_700_000_000_000,
        );

        $logger->log($result);

        $this->assertDatabaseHas('strategy_evaluations', [
            'symbol' => 'ETH-USDT',
            'interval' => '15m',
            'strategy' => 'BounceStrategy',
            'direction' => 'LONG',
            'status' => 'partial',
            'passed_count' => 5,
            'total_count' => 11,
        ]);

        $eval = StrategyEvaluation::first();
        $this->assertNotNull($eval);
        $this->assertSame(['Компрессия'], $eval->missing_criteria);
        $this->assertArrayHasKey('prior_peak', $eval->criteria_breakdown);
    }

    public function test_database_logger_ignores_evaluations_below_threshold(): void
    {
        $logger = new DatabaseStrategyLogger(50.0);

        $result = new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Long,
            score: 28.5,
            passedCount: 2,
            totalCount: 11,
            isFullSignal: false,
            entrySignal: null,
            level: 100.0,
            atr: 10.0,
            currentPrice: 101.0,
            criteria: [],
            missingCriteria: ['Критерий 1', 'Критерий 2'],
            symbol: 'SOL-USDT',
            interval: '1h',
        );

        $logger->log($result);

        $this->assertDatabaseCount('strategy_evaluations', 0);
    }

    public function test_trading_agent_logs_evaluation_when_analyzing(): void
    {
        $atr = 10.0;
        $level = 100.0;

        $candles = $this->baseline(45, 92, 98);
        $candles[] = $this->candle(98.0, 103.0, 97.8, 102.5);
        $candles[] = $this->candle(102.5, 104.5, 102.0, 104.0);
        $candles[] = $this->candle(104.0, 104.2, 101.5, 102.0);
        $candles[] = $this->candle(102.0, 102.2, 100.2, 100.8);
        $candles[] = $this->candle(100.8, 101.2, 99.6, 100.2);
        $candles[] = $this->candle(100.2, 100.6, 99.7, 100.1);
        $candles[] = $this->candle(100.1, 104.0, 99.9, 103.8, 2000); // 100% full signal

        $agent = app(TradingAgent::class);
        $result = $agent->evaluate($candles, $level, $atr, null, [], 'BTC-USDT', '5m');

        $this->assertNotNull($result->entrySignal);
        $this->assertDatabaseHas('strategy_evaluations', [
            'symbol' => 'BTC-USDT',
            'interval' => '5m',
            'status' => 'completed',
            'score' => 100.0,
        ]);
    }

    public function test_strategy_evaluation_filament_resource_accessible(): void
    {
        $user = User::factory()->create(['email' => 'admin@jtrader.local']);

        StrategyEvaluation::create([
            'symbol' => 'BTC-USDT',
            'interval' => '5m',
            'strategy' => 'BounceStrategy',
            'direction' => 'LONG',
            'status' => 'completed',
            'score' => 100.0,
            'passed_count' => 11,
            'total_count' => 11,
            'level' => 100.0,
            'atr' => 10.0,
            'current_price' => 103.8,
            'entry_price' => 103.8,
            'stop_price' => 98.5,
            'target1' => 114.4,
            'target2' => 125.0,
            'rr_ratio' => 4.0,
            'missing_criteria' => [],
            'criteria_breakdown' => [
                'prior_peak' => ['name' => 'Импульс', 'passed' => true, 'expected' => '>= 103.5', 'actual' => '104.5'],
            ],
            'evaluated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/admin/strategy-evaluations');
        $response->assertSuccessful();
        $response->assertSee('Статистика стратегий');
        $response->assertSee('BTC-USDT');
    }

    public function test_chart_rendered_for_strategy_evaluation(): void
    {
        $eval = StrategyEvaluation::create([
            'symbol' => 'BTC-USDT',
            'interval' => '5m',
            'strategy' => 'BounceStrategy',
            'direction' => 'LONG',
            'status' => 'completed',
            'score' => 100.0,
            'passed_count' => 11,
            'total_count' => 11,
            'level' => 100.0,
            'atr' => 10.0,
            'current_price' => 103.8,
            'entry_price' => 103.8,
            'stop_price' => 98.5,
            'target1' => 114.4,
            'target2' => 125.0,
            'rr_ratio' => 4.0,
            'missing_criteria' => [],
            'criteria_breakdown' => [],
            'evaluated_at' => now(),
        ]);

        $candles = $this->baseline(30, 95, 103);
        $chartRenderer = new \App\Trading\Charting\ChartRenderer(['enabled' => true]);

        $path = $chartRenderer->renderEvaluation($eval, $candles);

        $this->assertNotNull($path);
        $this->assertStringContainsString("charts/evaluations/eval_{$eval->id}.png", $path);
        $this->assertFileExists(storage_path("app/public/{$path}"));
    }

    public function test_candle_repository_window_around(): void
    {
        $repo = app(\App\Market\Repositories\CandleRepository::class);
        $candles = $this->baseline(50, 100, 150);
        $repo->persist('BTC-USDT', '5m', $candles);

        $targetTime = $candles[20]->openTime;

        // When requesting 20 after, we have 29 after (index 21 to 49)
        $window = $repo->windowAround('BTC-USDT', '5m', $targetTime, beforeCount: 15, afterCount: 20);
        $this->assertNotNull($window);
        $this->assertCount(35, $window); // 15 before/at + 20 after = 35 candles

        // When requesting 35 after, we only have 29, so it returns null
        $tooMany = $repo->windowAround('BTC-USDT', '5m', $targetTime, beforeCount: 15, afterCount: 35);
        $this->assertNull($tooMany);
    }

    public function test_render_strategy_outcomes_command(): void
    {
        $repo = app(\App\Market\Repositories\CandleRepository::class);
        $candles = $this->baseline(80, 100, 180);
        $repo->persist('ETH-USDT', '15m', $candles);

        $targetTime = $candles[30]->openTime;

        $eval = StrategyEvaluation::create([
            'symbol' => 'ETH-USDT',
            'interval' => '15m',
            'strategy' => 'BounceStrategy',
            'direction' => 'LONG',
            'status' => 'completed',
            'score' => 100.0,
            'passed_count' => 11,
            'total_count' => 11,
            'level' => 100.0,
            'atr' => 10.0,
            'current_price' => 130.0,
            'candle_open_time' => $targetTime,
            'missing_criteria' => [],
            'criteria_breakdown' => [],
            'evaluated_at' => now(),
        ]);

        $this->assertNull($eval->outcome_chart_path);

        $this->artisan('strategy:render-outcomes', ['--limit' => 10])
            ->assertSuccessful();

        $eval->refresh();
        $this->assertNotNull($eval->outcome_chart_path);
        $this->assertStringContainsString("charts/evaluations/outcome_{$eval->id}.png", $eval->outcome_chart_path);
        $this->assertFileExists(storage_path("app/public/{$eval->outcome_chart_path}"));
    }

    public function test_prune_strategy_evaluations_command(): void
    {
        // Old evaluation (8 days ago)
        $oldEval = StrategyEvaluation::create([
            'symbol' => 'SOL-USDT',
            'interval' => '1h',
            'strategy' => 'BounceStrategy',
            'direction' => 'SHORT',
            'status' => 'partial',
            'score' => 60.0,
            'passed_count' => 4,
            'total_count' => 11,
            'level' => 100.0,
            'atr' => 5.0,
            'current_price' => 99.0,
            'chart_path' => 'charts/evaluations/test_old.png',
            'evaluated_at' => now()->subDays(8),
        ]);

        // Create dummy file for old chart
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/public/charts/evaluations'));
        \Illuminate\Support\Facades\File::put(storage_path('app/public/charts/evaluations/test_old.png'), 'dummy');

        // Recent evaluation (2 days ago)
        $recentEval = StrategyEvaluation::create([
            'symbol' => 'SOL-USDT',
            'interval' => '1h',
            'strategy' => 'BounceStrategy',
            'direction' => 'SHORT',
            'status' => 'completed',
            'score' => 100.0,
            'passed_count' => 11,
            'total_count' => 11,
            'level' => 100.0,
            'atr' => 5.0,
            'current_price' => 99.0,
            'chart_path' => 'charts/evaluations/test_recent.png',
            'evaluated_at' => now()->subDays(2),
        ]);
        \Illuminate\Support\Facades\File::put(storage_path('app/public/charts/evaluations/test_recent.png'), 'dummy');

        $this->artisan('strategy:prune', ['--days' => 7])
            ->assertSuccessful();

        $this->assertDatabaseMissing('strategy_evaluations', ['id' => $oldEval->id]);
        $this->assertFileDoesNotExist(storage_path('app/public/charts/evaluations/test_old.png'));

        $this->assertDatabaseHas('strategy_evaluations', ['id' => $recentEval->id]);
        $this->assertFileExists(storage_path('app/public/charts/evaluations/test_recent.png'));

        // Clean up test dummy file
        \Illuminate\Support\Facades\File::delete(storage_path('app/public/charts/evaluations/test_recent.png'));
    }
}
