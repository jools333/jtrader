<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

use App\Market\Analysis\Support\SeriesMath;
use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Contracts\EntryStrategyInterface;
use App\Trading\Contracts\StrategyLoggerInterface;
use App\Trading\DTO\CriterionResult;
use App\Trading\DTO\EntrySignal;
use App\Trading\DTO\StrategyEvaluationResult;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №2 (параллельная) — «Пробой сжатия волатильности (4H Volatility Squeeze Breakout)».
 *
 * Логика:
 *   1) Сжатие волатильности: ширина полос Боллинджера BBWidth(20) в одной из
 *      последних 3 свечей опускалась в нижние 35% за последние 50 свечей (~8 дней).
 *   2) Импульсный пробой: закрытие 4H-свечи за границей BB(20, 2.0) с объёмом
 *      >= 1.3x от среднего (SMA20).
 *   3) Тренд: закрытие по нужную сторону от EMA50 (4H); межрыночный фильтр BTC
 *      EMA50 (4H) проверяется в {@see \App\Trading\Agent\EntryGuard}.
 *   4) Стоп: начальный стоп 2.0 × ATR(14) (Wilder) на бирже, БЕЗ фиксированного
 *      тейка 2R — прибыль ведётся трейлинг-стопом Chandelier (3.5 × ATR) по
 *      закрытым 4H-свечам.
 */
final class SqueezeBreakoutStrategy implements EntryStrategyInterface
{
    public function __construct(
        private readonly ?StrategyLoggerInterface $logger = null,
        private readonly int $bbPeriod = 20,
        private readonly float $bbStdMult = 2.0,
        private readonly int $squeezeLookback = 50,
        private readonly float $squeezePercentile = 0.35,
        private readonly int $squeezeWindow = 3,
        private readonly float $volumeMultiplier = 1.3,
        private readonly int $volumePeriod = 20,
        private readonly int $emaTrendPeriod = 50,
        private readonly float $initStopAtr = 2.0,
        private readonly float $minStopPercent = 0.40,
        private readonly float $maxStopPercent = 10.0,
        private readonly float $minEntryScore = 100.0,
        /** Рабочий таймфрейм стратегии; null = принимать любой (для тестов). */
        private readonly ?string $requiredInterval = '4h',
        /** Максимальный возраст закрытой 4h-свечи в минутах для входа (0 = без ограничения). */
        private readonly int $maxSignalAgeMinutes = 30,
    ) {}

    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        $eval = $this->diagnose($ctx);

        if ($eval === null) {
            return null;
        }

        if ($eval->score >= 50.0 && $this->logger !== null) {
            try {
                $this->logger->log($eval);
            } catch (\Throwable) {
                // Диагностика не должна ломать торговый цикл.
            }
        }

        if (! $eval->isFullSignal || $eval->score < $this->minEntryScore) {
            return null;
        }

        if ($this->maxSignalAgeMinutes > 0 && $eval->candleOpenTime !== null) {
            $intervalSec = $this->intervalSeconds($ctx->interval ?? $this->requiredInterval ?? '4h');
            $closeTimeSec = $eval->candleOpenTime + $intervalSec;
            $ageSec = \Illuminate\Support\Carbon::now()->timestamp - $closeTimeSec;
            if ($ageSec >= 0 && $ageSec < 86400 && $ageSec > $this->maxSignalAgeMinutes * 60) {
                return null;
            }
        }

        return $eval->entrySignal;
    }

    /**
     * Полная диагностика сетапа по 5 критериям (для журнала стратегий).
     */
    public function diagnose(RuleContext $ctx): ?StrategyEvaluationResult
    {
        $candles = $this->analysisCandles($ctx);

        if ($candles === null) {
            return null;
        }

        $n = count($candles);
        $last = $n - 1;
        $closes = array_map(static fn (Candle $c) => $c->close, $candles);
        $volumes = array_map(static fn (Candle $c) => $c->volume, $candles);

        $atr = $this->atrWilder($candles, 14);
        if ($atr <= 0.0) {
            return null;
        }

        $ema50 = SeriesMath::ema($closes, $this->emaTrendPeriod);
        $ema50Last = $ema50[$last] ?? 0.0;

        // Вычисляем BBWidth для последних (squeezeLookback + squeezeWindow + 1) свечей.
        [$bbMid, $bbStd] = $this->bollingerSeries($closes, $this->bbPeriod);
        $bbw = [];
        for ($i = 0; $i < $n; $i++) {
            $bbw[$i] = $bbMid[$i] > 0.0 ? (2.0 * $bbStd[$i] / $bbMid[$i]) : 0.0;
        }

        $histSlice = array_slice($bbw, $last - $this->squeezeLookback, $this->squeezeLookback);
        sort($histSlice);
        $pctIdx = min(count($histSlice) - 1, max(0, (int) floor($this->squeezePercentile * count($histSlice))));
        $squeezeThreshold = $histSlice[$pctIdx] ?? 0.0;

        $recentBbw = array_slice($bbw, $last - $this->squeezeWindow, $this->squeezeWindow);
        $minRecentBbw = $recentBbw !== [] ? min($recentBbw) : PHP_FLOAT_MAX;
        $squeezeOk = $squeezeThreshold > 0.0 && $minRecentBbw <= $squeezeThreshold;

        $volSlice = array_slice($volumes, max(0, $last - $this->volumePeriod + 1), min($this->volumePeriod, $n));
        $avgVol = $volSlice !== [] ? array_sum($volSlice) / count($volSlice) : 0.0;
        $lastVol = $volumes[$last];
        $volOk = $avgVol > 0.0 && $lastVol >= $avgVol * $this->volumeMultiplier;

        $upper = $bbMid[$last] + $this->bbStdMult * $bbStd[$last];
        $lower = $bbMid[$last] - $this->bbStdMult * $bbStd[$last];
        $bar = $candles[$last];

        $best = null;
        foreach ([Direction::Long, Direction::Short] as $direction) {
            $eval = $this->diagnoseDirection(
                direction: $direction,
                candles: $candles,
                bar: $bar,
                upper: $upper,
                lower: $lower,
                bbMid: $bbMid[$last],
                ema50: $ema50Last,
                atr: $atr,
                squeezeOk: $squeezeOk,
                minRecentBbw: $minRecentBbw,
                squeezeThreshold: $squeezeThreshold,
                volOk: $volOk,
                lastVol: $lastVol,
                avgVol: $avgVol,
                ctx: $ctx,
            );

            if ($eval->isFullSignal) {
                return $eval;
            }

            if ($best === null || $eval->score > $best->score) {
                $best = $eval;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, Candle>  $candles
     */
    private function diagnoseDirection(
        Direction $direction,
        array $candles,
        Candle $bar,
        float $upper,
        float $lower,
        float $bbMid,
        float $ema50,
        float $atr,
        bool $squeezeOk,
        float $minRecentBbw,
        float $squeezeThreshold,
        bool $volOk,
        float $lastVol,
        float $avgVol,
        RuleContext $ctx,
    ): StrategyEvaluationResult {
        $isLong = $direction === Direction::Long;
        $level = $isLong ? $upper : $lower;
        $entry = $bar->close;
        $criteria = [];

        // 1. Сжатие волатильности (BBWidth в нижних 35% за 50 баров).
        $criteria['volatility_squeeze'] = new CriterionResult(
            key: 'volatility_squeeze',
            name: 'Сжатие волатильности (BB Squeeze)',
            passed: $squeezeOk,
            expected: sprintf('<= %.4f (перцентиль %.0f%%)', $squeezeThreshold, $this->squeezePercentile * 100),
            actual: sprintf('%.4f', $minRecentBbw),
            actualValue: $minRecentBbw,
            thresholdValue: $squeezeThreshold,
        );

        // 2. Закрытие свечи за границей Боллинджера.
        $breakOk = $isLong ? ($entry > $upper) : ($entry < $lower);
        $criteria['bb_breakout'] = new CriterionResult(
            key: 'bb_breakout',
            name: 'Пробой полосы Боллинджера (BB 20, 2.0)',
            passed: $breakOk,
            expected: $isLong ? sprintf('> %.4f', $upper) : sprintf('< %.4f', $lower),
            actual: sprintf('%.4f', $entry),
            actualValue: $entry,
            thresholdValue: $level,
        );

        // 3. Подтверждение объёмом.
        $volRatio = $avgVol > 0.0 ? $lastVol / $avgVol : 0.0;
        $criteria['volume_confirmation'] = new CriterionResult(
            key: 'volume_confirmation',
            name: 'Всплеск объёма на пробое',
            passed: $volOk,
            expected: sprintf('>= %.2fx SMA%d', $this->volumeMultiplier, $this->volumePeriod),
            actual: sprintf('%.2fx', $volRatio),
            actualValue: $volRatio,
            thresholdValue: $this->volumeMultiplier,
        );

        // 4. Согласованность со старшим трендом (EMA50 на 4H).
        $trendOk = $ema50 > 0.0 && ($isLong ? ($entry > $ema50) : ($entry < $ema50));
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Тренд 4H (цена относительно EMA50)',
            passed: $trendOk,
            expected: $isLong ? sprintf('> %.4f', $ema50) : sprintf('< %.4f', $ema50),
            actual: sprintf('%.4f', $entry),
            actualValue: $entry,
            thresholdValue: $ema50,
        );

        // 5. Начальный стоп 2.0 ATR в допустимом окне риска (без потолка 2R).
        $stopDistance = $this->initStopAtr * $atr;
        $stop = $isLong ? ($entry - $stopDistance) : ($entry + $stopDistance);
        $stopPct = $entry > 0.0 ? ($stopDistance / $entry * 100.0) : 0.0;
        $riskOk = $entry > 0.0 && $stopPct >= $this->minStopPercent && $stopPct <= $this->maxStopPercent;

        $criteria['risk_window'] = new CriterionResult(
            key: 'risk_window',
            name: 'Риск в допустимом окне (2.0 ATR)',
            passed: $riskOk,
            expected: sprintf('%.2f%% .. %.2f%%', $this->minStopPercent, $this->maxStopPercent),
            actual: sprintf('%.2f%%', $stopPct),
            actualValue: $stopPct,
            thresholdValue: $this->maxStopPercent,
        );

        $passed = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $total = count($criteria);
        $score = $total > 0 ? $passed / $total * 100.0 : 0.0;
        $missing = [];
        foreach ($criteria as $c) {
            if (! $c->passed) {
                $missing[] = $c->name;
            }
        }

        $isFull = $score >= 100.0 && $riskOk;
        $planned = $isFull ? new EntrySignal(
            type: SignalType::SqueezeBreakout,
            direction: $direction,
            entryPrice: $entry,
            stop: $stop,
            // Без фиксированного тейк-профита: тренд сопровождается Chandelier ATR трейлингом.
            target1: 0.0,
            target2: 0.0,
            rrRatio: 3.0,
            confluence: true,
        ) : null;

        return new StrategyEvaluationResult(
            strategy: 'SQUEEZE_BREAKOUT',
            direction: $direction,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: $planned,
            level: $level,
            atr: $atr,
            currentPrice: $entry,
            criteria: $criteria,
            missingCriteria: $missing,
            indicators: [
                'bb_upper' => $upper,
                'bb_mid' => $bbMid,
                'bb_lower' => $lower,
                'bb_width' => $minRecentBbw,
                'bb_squeeze_threshold' => $squeezeThreshold,
                'ema50' => $ema50,
                'vol_ratio' => $volRatio,
            ],
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: intdiv((int) $bar->openTime, 1000),
            candles: $isFull ? $candles : null,
        );
    }

    /**
     * Закрытые свечи рабочего таймфрейма.
     *
     * @return array<int, Candle>|null
     */
    private function analysisCandles(RuleContext $ctx): ?array
    {
        if ($this->requiredInterval !== null && $ctx->interval !== null && $ctx->interval !== $this->requiredInterval) {
            return null;
        }

        $candles = array_values($ctx->candles);
        $now = \Illuminate\Support\Carbon::now()->timestamp;

        while ($candles !== [] && intdiv((int) end($candles)->closeTime, 1000) >= $now) {
            array_pop($candles);
        }

        $minimum = max(70, $this->squeezeLookback + $this->bbPeriod + 5);

        return count($candles) >= $minimum ? $candles : null;
    }

    /**
     * @param  array<int, float>  $closes
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function bollingerSeries(array $closes, int $period): array
    {
        $n = count($closes);
        $mid = array_fill(0, $n, 0.0);
        $std = array_fill(0, $n, 0.0);
        $sum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sum += $closes[$i];
            if ($i >= $period) {
                $sum -= $closes[$i - $period];
            }
            $count = min($i + 1, $period);
            $mid[$i] = $sum / $count;

            if ($i >= $period - 1) {
                $mu = $mid[$i];
                $var = 0.0;
                for ($j = $i - $period + 1; $j <= $i; $j++) {
                    $diff = $closes[$j] - $mu;
                    $var += $diff * $diff;
                }
                $std[$i] = sqrt($var / $period);
            }
        }

        return [$mid, $std];
    }

    /**
     * ATR по методу Уайлдера (совпадает с бэктестом).
     *
     * @param  array<int, Candle>  $candles
     */
    private function atrWilder(array $candles, int $period = 14): float
    {
        $n = count($candles);
        if ($n <= $period) {
            return 0.0;
        }

        $tr = array_fill(0, $n, 0.0);
        $tr[0] = $candles[0]->high - $candles[0]->low;
        for ($i = 1; $i < $n; $i++) {
            $pc = $candles[$i - 1]->close;
            $tr[$i] = max(
                $candles[$i]->high - $candles[$i]->low,
                abs($candles[$i]->high - $pc),
                abs($candles[$i]->low - $pc),
            );
        }

        $atr = array_sum(array_slice($tr, 1, $period)) / $period;
        for ($i = $period + 1; $i < $n; $i++) {
            $atr = ($atr * ($period - 1) + $tr[$i]) / $period;
        }

        return $atr;
    }

    private function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            default => 14400,
        };
    }
}
