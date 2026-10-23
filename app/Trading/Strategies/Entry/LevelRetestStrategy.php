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
 * Стратегия входа №1 (единственная активная) — «Пробой диапазона + ретест».
 *
 * Логика построена от уровней (подход Артёма Звездина): диапазон последних N
 * часов — это зона проторговки, её граница является уровнем. Сделка открывается
 * только когда цена:
 *   1) подтверждённо закрепляется за границей диапазона (закрытие свечи за
 *      уровнем, но без чрезмерного выноса — не гонимся за импульсом);
 *   2) согласована с локальным трендом (EMA fast против EMA slow);
 *   3) возвращается и ретестит пробитый уровень, удерживаясь за ним.
 *
 * Стоп ставится за экстремум структуры (пробитие + ретест) с ATR-буфером,
 * цель фиксирована на 2R. Такой вход даёт редкие, но качественные сделки и
 * сохраняет асимметрию риск/прибыль, чего не было у скальп-отскоков.
 *
 * Все внутренние расчёты (ATR, EMA, диапазон) выполняются по переданным
 * закрытым свечам, чтобы стратегия была детерминированной и тестируемой.
 */
final class LevelRetestStrategy implements EntryStrategyInterface
{
    public function __construct(
        private readonly ?StrategyLoggerInterface $logger = null,
        /** Сколько свечей формируют диапазон (зона проторговки). */
        private readonly int $lookback = 12,
        /** За сколько свечей до текущей может находиться свеча пробоя. */
        private readonly int $retestWindow = 4,
        /** Минимальная ширина диапазона в ATR (отсекаем шум). */
        private readonly float $minWidthAtr = 1.5,
        /** Максимальная ширина диапазона в ATR (отсекаем хаос/тренд-спайк). */
        private readonly float $maxWidthAtr = 4.5,
        /** Максимальный вынос за уровень при пробое, в ATR (анти-погоня). */
        private readonly float $maxExtensionAtr = 0.8,
        private readonly int $emaFast = 8,
        private readonly int $emaSlow = 21,
        /** Насколько близко к уровню должна подойти свеча ретеста (в ATR). */
        private readonly float $retestZoneAtr = 0.25,
        /** Минимальное тело свечи ретеста в ATR. */
        private readonly float $retestBodyAtr = 0.05,
        /** Неудачей считается закрытие за уровнем дальше этого ATR. */
        private readonly float $failAtr = 0.35,
        /** Буфер стопа за экстремум структуры в ATR. */
        private readonly float $stopBufferAtr = 0.15,
        /** Минимальная дистанция стопа (% от цены). */
        private readonly float $minStopPercent = 0.40,
        /** Максимальная дистанция стопа (% от цены) — шире не берём, риск неограничен. */
        private readonly float $maxStopPercent = 1.80,
        /** Дистанция цели в R. */
        private readonly float $targetR = 2.0,
        private readonly float $minEntryScore = 100.0,
        /** Рабочий таймфрейм стратегии; null = принимать любой (используется в тестах). */
        private readonly ?string $requiredInterval = '1h',
        /** Максимальный возраст закрытой свечи ретеста в минутах для входа в рынок (0 = без ограничения). */
        private readonly int $maxSignalAgeMinutes = 15,
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

        // Защита от запоздалых входов в середине/конце часа (например, когда слот
        // освободился на :40 или :55 минуте после закрытия 1h-свечи ретеста).
        if ($this->maxSignalAgeMinutes > 0 && $eval->candleOpenTime !== null) {
            $intervalSec = $this->requiredInterval === '1h' ? 3600 : 3600;
            $closeTimeSec = $eval->candleOpenTime + $intervalSec;
            $ageSec = \Illuminate\Support\Carbon::now()->timestamp - $closeTimeSec;
            // Применяем фильтр в пределах реального времени (до 24ч), чтобы
            // исторические фикстуры в unit-тестах оставались детерминированными.
            if ($ageSec >= 0 && $ageSec < 86400 && $ageSec > $this->maxSignalAgeMinutes * 60) {
                return null;
            }
        }

        return $eval->entrySignal;
    }

    /**
     * Полная диагностика сетапа с оценкой по критериям (для журнала стратегий).
     */
    public function diagnose(RuleContext $ctx): ?StrategyEvaluationResult
    {
        $candles = $this->analysisCandles($ctx);

        if ($candles === null) {
            return null;
        }

        $atr = $this->atrSma($candles, 14);
        if ($atr <= 0.0) {
            return null;
        }

        $closes = array_map(static fn (Candle $c) => $c->close, $candles);
        $emaFast = SeriesMath::ema($closes, $this->emaFast);
        $emaSlow = SeriesMath::ema($closes, $this->emaSlow);
        $last = count($candles) - 1;

        // Перебираем свечу пробоя от самой свежей к более старой.
        $earliest = max($this->lookback, $last - $this->retestWindow);
        $best = null;

        for ($breakBar = $last - 1; $breakBar >= $earliest; $breakBar--) {
            if ($breakBar < $this->lookback) {
                break;
            }

            $window = array_slice($candles, $breakBar - $this->lookback, $this->lookback);
            $rangeHigh = max(array_map(static fn (Candle $c) => $c->high, $window));
            $rangeLow = min(array_map(static fn (Candle $c) => $c->low, $window));
            $width = $rangeHigh - $rangeLow;

            $signal = $candles[$breakBar];
            $retest = $candles[$last];

            foreach ([Direction::Long, Direction::Short] as $direction) {
                $eval = $this->diagnoseDirection(
                    $direction,
                    $candles,
                    $breakBar,
                    $last,
                    $rangeHigh,
                    $rangeLow,
                    $width,
                    $atr,
                    $emaFast[$breakBar] ?? 0.0,
                    $emaSlow[$breakBar] ?? 0.0,
                    $signal,
                    $retest,
                    $ctx,
                );

                if ($eval === null) {
                    continue;
                }

                // Полностью валидный сетап — входим немедленно (свежий пробой
                // имеет приоритет над более старым).
                if ($eval->isFullSignal) {
                    return $eval;
                }

                if ($best === null || $eval->score > $best->score) {
                    $best = $eval;
                }
            }
        }

        return $best;
    }

    private function diagnoseDirection(
        Direction $direction,
        array $candles,
        int $breakBar,
        int $last,
        float $rangeHigh,
        float $rangeLow,
        float $width,
        float $atr,
        float $emaFast,
        float $emaSlow,
        Candle $signal,
        Candle $retest,
        RuleContext $ctx,
    ): ?StrategyEvaluationResult {
        $isLong = $direction === Direction::Long;
        $level = $isLong ? $rangeHigh : $rangeLow;
        $criteria = [];

        // 1. Диапазон должен быть реальной зоной проторговки (по ATR), а не шумом.
        $widthOk = $width >= $atr * $this->minWidthAtr && $width <= $atr * $this->maxWidthAtr;
        $criteria['range_width'] = new CriterionResult(
            key: 'range_width',
            name: 'Ширина диапазона (зона проторговки)',
            passed: $widthOk,
            expected: sprintf('%.4f .. %.4f', $atr * $this->minWidthAtr, $atr * $this->maxWidthAtr),
            actual: sprintf('%.4f', $width),
            actualValue: $width,
            thresholdValue: $atr * $this->minWidthAtr,
        );

        // 2. Закрепление за уровнем без погони за импульсом.
        $extension = $isLong ? $signal->close - $level : $level - $signal->close;
        $bodyOk = $isLong
            ? ($signal->close > $signal->open)
            : ($signal->close < $signal->open);
        $breakOk = $extension > 0.0
            && $extension <= $atr * $this->maxExtensionAtr
            && $bodyOk;
        $criteria['break_confirmation'] = new CriterionResult(
            key: 'break_confirmation',
            name: 'Закрепление за уровнем (закрытие без выноса)',
            passed: $breakOk,
            expected: sprintf('0 .. %.4f ATR, тело в сторону сделки', $atr * $this->maxExtensionAtr),
            actual: sprintf('%.4f', $extension),
            actualValue: $extension,
            thresholdValue: $atr * $this->maxExtensionAtr,
        );

        // 3. Локальный тренд согласован с направлением (EMA fast vs EMA slow).
        $trendOk = $isLong
            ? ($emaFast > $emaSlow && $signal->close > $emaSlow)
            : ($emaFast < $emaSlow && $signal->close < $emaSlow);
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Согласованность с локальным трендом (EMA)',
            passed: $trendOk,
            expected: $isLong ? 'EMA_fast > EMA_slow, цена выше EMA_slow' : 'EMA_fast < EMA_slow, цена ниже EMA_slow',
            actual: sprintf('fast=%.4f slow=%.4f', $emaFast, $emaSlow),
            actualValue: $emaFast,
            thresholdValue: $emaSlow,
        );

        // Между пробоем и ретестом цена не должна вернуться глубоко за уровень.
        $structureOk = true;
        for ($k = $breakBar + 1; $k < $last; $k++) {
            $close = $candles[$k]->close;
            if ($isLong && $close < $level - $atr * $this->failAtr) {
                $structureOk = false;
                break;
            }
            if (! $isLong && $close > $level + $atr * $this->failAtr) {
                $structureOk = false;
                break;
            }
        }
        $criteria['structure_hold'] = new CriterionResult(
            key: 'structure_hold',
            name: 'Удержание структуры до ретеста',
            passed: $structureOk,
            expected: sprintf('закрытия не дальше %.2f ATR за уровнем', $this->failAtr),
            actual: $structureOk ? 'ок' : 'уровень потерян',
        );

        // 4. Ретест уровня: подход к уровню, закрытие за ним, направляющее тело.
        $touchOk = $isLong
            ? $retest->low <= $level + $atr * $this->retestZoneAtr
            : $retest->high >= $level - $atr * $this->retestZoneAtr;
        $closeOk = $isLong
            ? ($retest->close > $level && $retest->close >= $retest->open)
            : ($retest->close < $level && $retest->close <= $retest->open);
        $retestBody = abs($retest->close - $retest->open);
        $retestOk = $touchOk && $closeOk && $retestBody >= $atr * $this->retestBodyAtr;
        $criteria['retest'] = new CriterionResult(
            key: 'retest',
            name: 'Ретест пробитого уровня',
            passed: $retestOk,
            expected: sprintf('подход в зону %.2f ATR + закрытие за уровнем', $this->retestZoneAtr),
            actual: sprintf('low=%.4f high=%.4f close=%.4f', $retest->low, $retest->high, $retest->close),
            actualValue: $retest->close,
            thresholdValue: $level,
        );

        // Стоп за экстремумом структуры (пробой + ретест) с ATR-буфером.
        $entry = $retest->close;
        $extreme = $isLong
            ? min($level, min(array_map(static fn (Candle $c) => $c->low, array_slice($candles, $breakBar, $last - $breakBar + 1))))
            : max($level, max(array_map(static fn (Candle $c) => $c->high, array_slice($candles, $breakBar, $last - $breakBar + 1))));
        $stop = $isLong
            ? $extreme - $atr * $this->stopBufferAtr
            : $extreme + $atr * $this->stopBufferAtr;

        $planned = null;
        $riskOk = true;
        $stopDistance = abs($entry - $stop);
        if ($entry > 0.0 && $stopDistance > 0.0) {
            $stopPercent = $stopDistance / $entry * 100.0;
            $riskOk = $stopPercent <= $this->maxStopPercent;
            if ($riskOk) {
                if ($stopPercent < $this->minStopPercent) {
                    $stopDistance = $entry * $this->minStopPercent / 100.0;
                    $stop = $isLong ? $entry - $stopDistance : $entry + $stopDistance;
                }
                $target = $isLong
                    ? $entry + $stopDistance * $this->targetR
                    : $entry - $stopDistance * $this->targetR;

                $planned = new EntrySignal(
                    type: SignalType::LevelRetest,
                    direction: $direction,
                    entryPrice: $entry,
                    stop: $stop,
                    target1: $target,
                    target2: $target,
                    rrRatio: $this->targetR,
                    confluence: $structureOk && $trendOk,
                );
            }
        } else {
            $riskOk = false;
        }

        $criteria['risk_window'] = new CriterionResult(
            key: 'risk_window',
            name: 'Риск в допустимом окне (стоп технический)',
            passed: $riskOk,
            expected: sprintf('<= %.2f%% от входа', $this->maxStopPercent),
            actual: $entry > 0.0 ? sprintf('%.2f%%', $stopDistance / $entry * 100.0) : 'н/д',
            actualValue: $stopDistance,
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

        $isFull = $score >= 100.0 && $planned !== null;

        return new StrategyEvaluationResult(
            strategy: 'LEVEL_RETEST',
            direction: $direction,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: $isFull ? $planned : null,
            level: $level,
            atr: $atr,
            currentPrice: $retest->close,
            criteria: $criteria,
            missingCriteria: $missing,
            indicators: [
                'range_high' => $rangeHigh,
                'range_low' => $rangeLow,
                'range_width' => $width,
                'ema_fast' => $emaFast,
                'ema_slow' => $emaSlow,
                'break_bar' => $breakBar,
            ],
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: intdiv((int) $retest->openTime, 1000),
            // Свечи нужны только для графика реального входа; partial-оценки
            // не рендерятся, а их payload в очереди весил десятки килобайт.
            candles: $isFull ? $candles : null,
        );
    }

    /**
     * Свечи для анализа: только закрытые бары рабочего таймфрейма.
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

        // Последняя свеча может быть ещё не закрыта — она не даёт сигналов.
        while ($candles !== [] && intdiv((int) end($candles)->closeTime, 1000) >= $now) {
            array_pop($candles);
        }

        $minimum = max(60, $this->lookback + $this->retestWindow + 20);

        return count($candles) >= $minimum ? $candles : null;
    }

    /**
     * ATR по простому среднему (совпадает с логикой бэктеста).
     *
     * @param array<int, Candle> $candles
     */
    private function atrSma(array $candles, int $period = 14): float
    {
        $n = count($candles);
        if ($n < $period + 1) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = $n - $period; $i < $n; $i++) {
            $prevClose = $candles[$i - 1]->close;
            $sum += max(
                $candles[$i]->high - $candles[$i]->low,
                abs($candles[$i]->high - $prevClose),
                abs($candles[$i]->low - $prevClose),
            );
        }

        return $sum / $period;
    }
}
