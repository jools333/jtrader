<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use App\Trading\Strategies\Entry\LevelRetestStrategy;
use PHPUnit\Framework\TestCase;

/**
 * «Пробой диапазона + ретест» — единственная активная стратегия входа.
 *
 * Фикстуры строятся на закрытых часовых свечах с фиксированными метками
 * времени (прошлое), поэтому результат детерминирован и не зависит от момента
 * запуска теста.
 */
class LevelRetestStrategyTest extends TestCase
{
    /** @var int */
    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $v = 100.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $v, $this->t + 3_599_999);
        $this->t += 3_600_000;

        return $candle;
    }

    /**
     * Часовой ряд: восходящий тренд, затем зона проторговки с уровнями
     * диапазона, затем свеча пробоя и свеча ретеста.
     *
     * @param bool $withRetest Если false — последняя свеча возвращается обратно под уровень.
     * @param bool $narrowRange Если true — диапазон слишком узкий (шум).
     * @param bool $extraOpenCandle Добавить незакрытую (текущую) свечу.
     *
     * @return array<int, Candle>
     */
    private function setupCandles(
        bool $withRetest = true,
        bool $narrowRange = false,
        bool $extraOpenCandle = false,
    ): array {
        $candles = [];

        // 1. Восходящий тренд: EMA_fast выше EMA_slow.
        $price = 100.0;
        for ($i = 0; $i < 45; $i++) {
            $open = $price;
            $close = $price + 0.2;
            $candles[] = $this->candle($open, $close + 0.4, $open - 0.4, $close);
            $price = $close;
        }

        // 2. Зона проторговки (12 свечей): границы диапазона формируются
        //    тенями, внутри — узкие тела.
        $rangeHigh = $narrowRange ? 110.0 : 111.2;
        $rangeLow = $narrowRange ? 109.2 : 108.4;

        $osc = [109.6, 110.4];
        for ($i = 0; $i < 12; $i++) {
            $open = $price;
            $close = $osc[$i % 2];
            $high = max($open, $close) + 0.1;
            $low = min($open, $close) - 0.1;
            $candles[] = $this->candle($open, $high, $low, $close);
            $price = $close;
        }

        // Тень верхней границы и тень нижней границы диапазона.
        $candles[] = $this->candle($price, $rangeHigh, $price - 0.1, $price - 0.4);
        $price -= 0.4;
        $candles[] = $this->candle($price, $price + 0.2, $rangeLow, $price - 0.4);
        $price -= 0.4;

        // 3. Свеча пробоя: закрытие за верхней границей без чрезмерного выноса.
        $breakClose = $rangeHigh + 0.4;
        $candles[] = $this->candle($breakClose - 1.2, $breakClose + 0.1, $breakClose - 1.35, $breakClose);
        $price = $breakClose;

        // 4. Свеча ретеста: подход к уровню и удержание за ним.
        if ($withRetest) {
            $candles[] = $this->candle(
                $price + 0.05,
                $rangeHigh + 1.0,
                $rangeHigh + 0.05,
                $price + 0.25,
            );
        } else {
            // Возврат под уровень — сетап не подтверждён.
            $candles[] = $this->candle($price - 0.3, $price - 0.1, $rangeHigh - 1.2, $rangeHigh - 1.0);
        }

        if ($extraOpenCandle) {
            // Незакрытая свеча: closeTime в будущем, стратегия обязана её игнорировать.
            $future = (int) (microtime(true) * 1000) + 3_600_000;
            $candles[] = new Candle($future, 111.0, 111.2, 108.0, 108.1, 10.0, $future + 3_599_999);
        }

        return $candles;
    }

    /**
     * Зеркальное отражение ряда: лонг-сетап превращается в шорт-сетап.
     *
     * @param array<int, Candle> $candles
     * @return array<int, Candle>
     */
    private function mirror(array $candles): array
    {
        return array_map(static fn (Candle $c) => new Candle(
            $c->openTime,
            220.0 - $c->open,
            220.0 - $c->low,
            220.0 - $c->high,
            220.0 - $c->close,
            $c->volume,
            $c->closeTime,
        ), $candles);
    }

    /**
     * @param array<int, Candle> $candles
     */
    private function context(array $candles, string $interval = '1h'): RuleContext
    {
        $n = count($candles);

        return new RuleContext(
            candles: $candles,
            level: 111.2,
            atr: 1.0,
            ema8: array_fill(0, $n, 100.0),
            ema21: array_fill(0, $n, 99.0),
            ema50: array_fill(0, $n, 98.0),
            macd: [
                'line' => array_fill(0, $n, 0.0),
                'signal' => array_fill(0, $n, 0.0),
                'histogram' => array_fill(0, $n, 0.0),
            ],
            symbol: 'SOL-USDT',
            interval: $interval,
        );
    }

    public function test_breakout_retest_generates_long_entry_with_2r_target(): void
    {
        $candles = $this->setupCandles();
        $strategy = new LevelRetestStrategy;

        $signal = $strategy->evaluate($this->context($candles), new TradePlanner);

        $this->assertNotNull($signal);
        $this->assertSame(SignalType::LevelRetest, $signal->type);
        $this->assertSame(Direction::Long, $signal->direction);
        $this->assertLessThan($signal->entryPrice, $signal->stop);
        $this->assertGreaterThan($signal->entryPrice, $signal->target1);

        $risk = $signal->entryPrice - $signal->stop;
        $reward = $signal->target1 - $signal->entryPrice;
        $this->assertEqualsWithDelta(2.0, $reward / $risk, 0.05);

        // Стоп технический: за экстремум структуры, в допустимом окне риска.
        $stopPercent = $risk / $signal->entryPrice * 100.0;
        $this->assertGreaterThanOrEqual(0.40, $stopPercent);
        $this->assertLessThanOrEqual(1.80, $stopPercent);
    }

    public function test_mirrored_setup_generates_short_entry(): void
    {
        $candles = $this->mirror($this->setupCandles());
        $strategy = new LevelRetestStrategy;

        $signal = $strategy->evaluate($this->context($candles), new TradePlanner);

        $this->assertNotNull($signal);
        $this->assertSame(Direction::Short, $signal->direction);
        $this->assertGreaterThan($signal->entryPrice, $signal->stop);
        $this->assertLessThan($signal->entryPrice, $signal->target1);
    }

    public function test_no_entry_without_retest_hold(): void
    {
        $candles = $this->setupCandles(withRetest: false);
        $strategy = new LevelRetestStrategy;

        $this->assertNull($strategy->evaluate($this->context($candles), new TradePlanner));
    }

    public function test_no_entry_when_range_is_too_narrow(): void
    {
        $candles = $this->setupCandles(narrowRange: true);
        $strategy = new LevelRetestStrategy;

        $this->assertNull($strategy->evaluate($this->context($candles), new TradePlanner));
    }

    public function test_strategy_is_inactive_on_other_timeframes(): void
    {
        $candles = $this->setupCandles();
        $strategy = new LevelRetestStrategy;

        $this->assertNull($strategy->evaluate($this->context($candles, '5m'), new TradePlanner));
    }

    public function test_in_progress_candle_is_ignored(): void
    {
        $candles = $this->setupCandles(extraOpenCandle: true);
        $strategy = new LevelRetestStrategy;

        // Последняя (незакрытая) свеча обнулила бы сетап, но стратегия её не видит.
        $signal = $strategy->evaluate($this->context($candles), new TradePlanner);

        $this->assertNotNull($signal);
        $this->assertSame(Direction::Long, $signal->direction);
    }

    public function test_stale_signal_older_than_max_age_is_rejected_while_fresh_signal_passes(): void
    {
        $candles = $this->setupCandles();
        $lastCandle = end($candles);
        $closeTimeSec = intdiv($lastCandle->openTime, 1000) + 3600;
        $strategy = new LevelRetestStrategy(maxSignalAgeMinutes: 15);

        try {
            // 1. Свежий сигнал (2 минуты после закрытия часовой свечи) -> вход разрешён
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::createFromTimestamp($closeTimeSec + 120));
            $freshSignal = $strategy->evaluate($this->context($candles), new TradePlanner);
            $this->assertNotNull($freshSignal);

            // 2. Устаревший сигнал (40 минут после закрытия часовой свечи) -> вход заблокирован
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::createFromTimestamp($closeTimeSec + 2400));
            $staleSignal = $strategy->evaluate($this->context($candles), new TradePlanner);
            $this->assertNull($staleSignal);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }
}
