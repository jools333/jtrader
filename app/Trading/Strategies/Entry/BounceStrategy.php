<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

// Импорт DTO свечи
use App\Market\DTO\Candle;
// Импорт контекста правил с индикаторами и свечами
use App\Trading\Agent\RuleContext;
// Импорт сервиса построения торгового плана
use App\Trading\Agent\TradePlanner;
// Импорт утилит для свечного анализа
use App\Trading\Analysis\CandleSignals;
// Импорт интерфейса логгера стратегий
use App\Trading\Contracts\StrategyLoggerInterface;
// Импорт интерфейса стратегии входа
use App\Trading\Contracts\EntryStrategyInterface;
// Импорт DTO отдельного критерия
use App\Trading\DTO\CriterionResult;
// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт DTO результата диагностики
use App\Trading\DTO\StrategyEvaluationResult;
// Импорт перечисления направления сделки (Long/Short)
use App\Trading\Enums\Direction;
// Импорт перечисления типов сигналов
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №1 — Отскок от ключевого уровня (Bounce / Пробой и откат).
 *
 * Двухуровневая модель оценки:
 *
 * 1. Обязательные (Hard) фильтры — 100% прохождение строго необходимо для входа:
 *    - trend_alignment: совпадение с трендом EMA 50 + буфер 0.10 ATR + наклон EMA 50;
 *    - macd_alignment: гистограмма MACD по направлению сделки (> 0 для LONG, < 0 для SHORT);
 *    - entry_zone: цена входа в пределах 0.50 ATR от уровня;
 *    - risk_reward: математический потенциал R:R >= min_rr (>= 2.0).
 *
 * 2. Балльные (Soft) критерии Price Action (8 условий):
 *    - prior_peak / prior_trough: предшествующий импульс (>= 0.35 ATR);
 *    - pullback_touch: откат в зону уровня (<= 0.25 ATR);
 *    - level_held: удержание уровня (провал < 0.40 ATR);
 *    - compression: компрессия на откате (>= 1 свечи);
 *    - impulse_trigger: импульсный отбой на триггерной свече (тело >= 0.35 ATR);
 *    - volume_confirmation: подтверждение всплеском объема (> 1.1x avg);
 *    - momentum_exhaustion: отсутствие агрессивных контр-свечей (< 0.70 ATR);
 *    - wick_rejection: прокол уровня / пин-бар с откупом.
 */
final class BounceStrategy implements EntryStrategyInterface
{
    /** Глубина анализируемого окна свечей */
    private const int LOOKBACK = 25;

    /** Обязательные (Hard) критерии: при непрохождении любого из них вход строго блокируется */
    private const array HARD_CRITERIA = [
        'trend_alignment',
        'macd_alignment',
        'entry_zone',
        'risk_reward',
    ];

    public function __construct(private readonly ?StrategyLoggerInterface $logger = null, private readonly float $minEntryScore = 83.33)
    {
    }

    /**
     * Оценка рынка на предмет отскока от поддержки или сопротивления.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        $eval = $this->diagnose($ctx, $planner);
        if ($eval === null) {
            return null;
        }

        // Логируем результат, если выполнено хотя бы 50% критериев
        if ($eval->score >= 50.0 && $this->logger !== null) {
            $this->logger->log($eval);
        }

        return $eval->score >= $this->minEntryScore ? $eval->entrySignal : null;
    }

    /**
     * Выполняет полный диагностический анализ всех условий алгоритма.
     */
    public function diagnose(RuleContext $ctx, TradePlanner $planner): ?StrategyEvaluationResult
    {
        if ($ctx->n < 15 || $ctx->atr <= 0.0) {
            return null;
        }

        $lookback = min($ctx->n, self::LOOKBACK);
        $window = $ctx->slice($lookback);
        $m = count($window);
        $last = $window[$m - 1];

        $longEval = $this->diagnoseLong($ctx, $planner, $window, $last);
        $shortEval = $this->diagnoseShort($ctx, $planner, $window, $last);

        // Если есть полный сигнал — выбираем его, иначе вариант с наибольшим баллом
        if ($longEval->isFullSignal) {
            return $longEval;
        }
        if ($shortEval->isFullSignal) {
            return $shortEval;
        }

        return $longEval->score >= $shortEval->score ? $longEval : $shortEval;
    }

    /**
     * Диагностика сценария LONG (Отбой от поддержки вверх).
     *
     * @param array<int, Candle> $window
     */
    private function diagnoseLong(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): StrategyEvaluationResult {
        $m = count($window);
        $atr = $ctx->atr;
        $level = $ctx->level;
        $criteria = [];
        $missing = [];

        // 1. Предшествующий импульс выше уровня
        $searchEnd = $m - 3;
        $peakHigh = -INF;
        $peakIdx = -1;

        if ($searchEnd >= 0) {
            for ($i = 0; $i <= $searchEnd; $i++) {
                if ($window[$i]->high > $peakHigh) {
                    $peakHigh = $window[$i]->high;
                    $peakIdx = $i;
                }
            }
        }

        $reqPeak = $level + $atr * 0.35;
        $passedPeak = $peakHigh >= $reqPeak && $peakIdx >= 0;
        $criteria['prior_peak'] = new CriterionResult(
            key: 'prior_peak',
            name: 'Предшествующий импульс выше уровня',
            passed: $passedPeak,
            expected: sprintf('>= %.4f (L + 0.35 ATR)', $reqPeak),
            actual: sprintf('%.4f (%+.2f ATR)', $peakHigh, ($peakHigh - $level) / $atr),
            actualValue: $peakHigh,
            thresholdValue: $reqPeak,
        );
        if (! $passedPeak) {
            $missing[] = 'Предшествующий импульс выше уровня';
        }

        // Анализ отката
        $pullbackCount = ($peakIdx >= 0) ? (($m - 1) - ($peakIdx + 1)) : 0;
        $pullbackCandles = ($pullbackCount > 0)
            ? array_slice($window, $peakIdx + 1, $pullbackCount)
            : [];

        $minLow = $pullbackCandles !== []
            ? min(array_map(static fn (Candle $c) => $c->low, $pullbackCandles))
            : $last->low;

        // 2. Касание зоны поддержки
        $reqTouch = $level + $atr * 0.25;
        $passedTouch = $pullbackCandles !== [] && $minLow <= $reqTouch;
        $criteria['pullback_touch'] = new CriterionResult(
            key: 'pullback_touch',
            name: 'Касание зоны поддержки на откате',
            passed: $passedTouch,
            expected: sprintf('<= %.4f (L + 0.25 ATR)', $reqTouch),
            actual: sprintf('%.4f (%+.2f ATR)', $minLow, ($minLow - $level) / $atr),
            actualValue: $minLow,
            thresholdValue: $reqTouch,
        );
        if (! $passedTouch) {
            $missing[] = 'Касание зоны поддержки на откате';
        }

        // 3. Удержание уровня поддержки
        $reqHeld = $level - $atr * 0.40;
        $passedHeld = $pullbackCandles !== [] && $minLow >= $reqHeld;
        $criteria['level_held'] = new CriterionResult(
            key: 'level_held',
            name: 'Удержание уровня поддержки',
            passed: $passedHeld,
            expected: sprintf('>= %.4f (не глубже L - 0.40 ATR)', $reqHeld),
            actual: sprintf('%.4f (%+.2f ATR)', $minLow, ($minLow - $level) / $atr),
            actualValue: $minLow,
            thresholdValue: $reqHeld,
        );
        if (! $passedHeld) {
            $missing[] = 'Удержание уровня поддержки (глубокий провал)';
        }

        // 4. Компрессия на откате
        $compCount = CandleSignals::countCompression($pullbackCandles, $atr);
        $hasNarrow = $this->hasNarrowRangeCandle($pullbackCandles, $atr);
        $passedComp = ($compCount >= 1 || $hasNarrow) && $pullbackCandles !== [];
        $criteria['compression'] = new CriterionResult(
            key: 'compression',
            name: 'Компрессия волатильности на откате',
            passed: $passedComp,
            expected: '>= 1 свечи сжатия / узкого бара',
            actual: sprintf('%d свечей сжатия%s', $compCount, $hasNarrow ? ' (+ узкий бар)' : ''),
            actualValue: (float) $compCount,
            thresholdValue: 1.0,
        );
        if (! $passedComp) {
            $missing[] = 'Компрессия волатильности на откате';
        }

        // 5. Импульсный отбой (триггерная свеча)
        $body = CandleSignals::body($last);
        $reqBody = $atr * 0.35;
        $passedTrigger = $last->close > $last->open && $body >= $reqBody && $last->close > $level;
        $criteria['impulse_trigger'] = new CriterionResult(
            key: 'impulse_trigger',
            name: 'Импульсный отбой от уровня',
            passed: $passedTrigger,
            expected: sprintf('Бычье тело >= %.4f (0.35 ATR), Close > L', $reqBody),
            actual: sprintf('%sтело %.4f (%.2f ATR), Close: %.4f', $last->close > $last->open ? 'Бычье ' : 'Медвежье ', $body, $body / $atr, $last->close),
            actualValue: $body,
            thresholdValue: $reqBody,
        );
        if (! $passedTrigger) {
            $missing[] = 'Импульсный отбой от уровня';
        }

        // 6. Допустимая зона входа
        $maxEntry = $level + $atr * 0.50;
        $passedEntryZone = $last->close <= $maxEntry;
        $criteria['entry_zone'] = new CriterionResult(
            key: 'entry_zone',
            name: 'Вход в допустимой зоне цены',
            passed: $passedEntryZone,
            expected: sprintf('Close <= %.4f (L + 0.50 ATR)', $maxEntry),
            actual: sprintf('Close = %.4f (%+.2f ATR от уровня)', $last->close, ($last->close - $level) / $atr),
            actualValue: $last->close,
            thresholdValue: $maxEntry,
        );
        if (! $passedEntryZone) {
            $missing[] = 'Цена ушла слишком далеко от уровня (> 0.50 ATR)';
        }

                // 7. Фильтр по тренду (усиленный: цена выше EMA50 + буфер, наклон EMA50 растёт)
        $ema50 = $ctx->ema50At($ctx->i);
        $ema50Slope = $ctx->i >= 3 ? ($ctx->ema50At($ctx->i) - $ctx->ema50At($ctx->i - 3)) : 0.0;
        $passedTrend = $ema50 > 0.0 ? ($last->close > $ema50 + $atr * 0.10 && $ema50Slope > 0) : true;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Совпадение с глобальным трендом (EMA 50)',
            passed: $passedTrend,
            expected: sprintf('Цена > EMA50 + 0.10 ATR (%.4f) и наклон EMA50 > 0', $ema50 + $atr * 0.10),
            actual: sprintf('Close = %.4f, EMA 50 = %.4f, Slope = %+.6f', $last->close, $ema50, $ema50Slope),
            actualValue: $last->close,
            thresholdValue: $ema50 + $atr * 0.10,
        );
        if (! $passedTrend) {
            $missing[] = 'Вход против тренда (Цена <= EMA 50 + буфер или EMA50 падает)';
        }

        // 8. Подтверждение объемом
        $avgVol = CandleSignals::avgVolume($pullbackCandles);
        $passedVol = $last->volume > $avgVol * 1.1;
        $criteria['volume_confirmation'] = new CriterionResult(
            key: 'volume_confirmation',
            name: 'Подтверждение отскока объемом',
            passed: $passedVol,
            expected: 'Объем триггерной свечи > 1.1 * Avg Vol отката',
            actual: sprintf('Объем = %.1f (Avg = %.1f)', $last->volume, $avgVol),
            actualValue: $last->volume,
            thresholdValue: $avgVol * 1.1,
        );
        if (! $passedVol) {
            $missing[] = 'Нет всплеска объема на отскоке';
        }

                // 9. Отсутствие агрессивного подхода (Momentum Thrust Filter)
        $aggressive = false;
        $pullbackCount2 = count($pullbackCandles);
        $recentPullback = array_slice($pullbackCandles, max(0, $pullbackCount2 - 3));
        foreach ($recentPullback as $pc) {
            if ($pc->close < $pc->open && CandleSignals::body($pc) > $atr * 0.7) {
                $aggressive = true;
                break;
            }
        }
        $passedExhaustion = !$aggressive;
        $criteria['momentum_exhaustion'] = new CriterionResult(
            key: 'momentum_exhaustion',
            name: 'Отсутствие агрессивного подхода',
            passed: $passedExhaustion,
            expected: 'Тела свечей отката < 0.7 ATR',
            actual: $aggressive ? 'Обнаружена аномально большая свеча отката' : 'Плавный подход',
            actualValue: $aggressive ? 1.0 : 0.0,
            thresholdValue: 0.0,
        );
        if (! $passedExhaustion) {
            $missing[] = 'Агрессивный подход (Momentum Thrust)';
        }

        // 10. Прокол уровня / Пин-бар
        // Триггерная или предыдущая свеча должна касаться зоны L + 0.1 ATR
        $prev = $m > 1 ? $window[$m - 2] : $last;
        $testedLevel = $last->low <= $level + $atr * 0.15 || $prev->low <= $level + $atr * 0.15;
        // Тень снизу у триггера
        $lowerWick = min($last->open, $last->close) - $last->low;
        $isPinBar = $lowerWick >= CandleSignals::body($last) * 0.5;
        
        $passedWick = $testedLevel && ($isPinBar || $last->close > $level);
        $criteria['wick_rejection'] = new CriterionResult(
            key: 'wick_rejection',
            name: 'Прокол уровня (Пин-бар / Тест)',
            passed: $passedWick,
            expected: 'Тест L+0.15 ATR и откуп (тень)',
            actual: sprintf('Low: %.4f, Тень: %.4f', $last->low, $lowerWick),
            actualValue: $last->low,
            thresholdValue: $level + $atr * 0.15,
        );
        if (! $passedWick) {
            $missing[] = 'Нет явного прокола или тени откупа';
        }

        // 11. Торговый план и R:R
        $stopPrice = $minLow - $atr * 0.10;
        $plan = $planner->plan($ctx, SignalType::Bounce, Direction::Long, false, $stopPrice);
        $passedRr = $plan !== null && $plan->rrRatio >= 2.0;
        $criteria['risk_reward'] = new CriterionResult(
            key: 'risk_reward',
            name: 'Коэффициент прибыль/риск (R:R)',
            passed: $passedRr,
            expected: 'R:R >= 2.0 и корректный стоп',
            actual: $plan ? sprintf('R:R = 1:%.2f (Риск: %.4f, Стоп: %.4f)', $plan->rrRatio, $last->close - $plan->stop, $plan->stop) : 'Не удалось построить план (стоп >= вход)',
            actualValue: $plan?->rrRatio,
            thresholdValue: 2.0,
        );
        if (! $passedRr) {
            $missing[] = 'Коэффициент R:R < 2.0 или некорректный стоп';
        }

        // 12. Подтверждение MACD (гистограмма в направлении сделки)
        $macdHist = $ctx->macdHistAt($ctx->i);
        $passedMacd = $macdHist > 0;
        $criteria['macd_alignment'] = new CriterionResult(
            key: 'macd_alignment',
            name: 'Подтверждение MACD (гистограмма)',
            passed: $passedMacd,
            expected: 'MACD гистограмма > 0 (бычий импульс)',
            actual: sprintf('MACD hist = %+.6f', $macdHist),
            actualValue: $macdHist,
            thresholdValue: 0.0,
        );
        if (! $passedMacd) {
            $missing[] = 'MACD гистограмма отрицательная (медвежий импульс)';
        }

        $total = count($criteria);
        $passed = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passed / $total) * 100, 2);
        $isFull = ($passed === $total);

        $passedHard = true;
        foreach (self::HARD_CRITERIA as $hardKey) {
            if (! ($criteria[$hardKey]->passed ?? false)) {
                $passedHard = false;
                break;
            }
        }

        return new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Long,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: ($passedHard && $score >= $this->minEntryScore) ? $plan : null,
            level: $level,
            atr: $atr,
            currentPrice: $last->close,
            criteria: $criteria,
            missingCriteria: $missing,
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: $last->openTime,
            candles: $window,
        );
    }

    /**
     * Диагностика сценария SHORT (Отбой от сопротивления вниз).
     *
     * @param array<int, Candle> $window
     */
    private function diagnoseShort(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): StrategyEvaluationResult {
        $m = count($window);
        $atr = $ctx->atr;
        $level = $ctx->level;
        $criteria = [];
        $missing = [];

        // 1. Предшествующий импульс ниже уровня
        $searchEnd = $m - 3;
        $troughLow = INF;
        $troughIdx = -1;

        if ($searchEnd >= 0) {
            for ($i = 0; $i <= $searchEnd; $i++) {
                if ($window[$i]->low < $troughLow) {
                    $troughLow = $window[$i]->low;
                    $troughIdx = $i;
                }
            }
        }

        $reqTrough = $level - $atr * 0.35;
        $passedTrough = $troughLow <= $reqTrough && $troughIdx >= 0;
        $criteria['prior_trough'] = new CriterionResult(
            key: 'prior_trough',
            name: 'Предшествующий импульс ниже уровня',
            passed: $passedTrough,
            expected: sprintf('<= %.4f (L - 0.35 ATR)', $reqTrough),
            actual: sprintf('%.4f (%+.2f ATR)', $troughLow, ($troughLow - $level) / $atr),
            actualValue: $troughLow,
            thresholdValue: $reqTrough,
        );
        if (! $passedTrough) {
            $missing[] = 'Предшествующий импульс ниже уровня';
        }

        // Анализ отката
        $pullbackCount = ($troughIdx >= 0) ? (($m - 1) - ($troughIdx + 1)) : 0;
        $pullbackCandles = ($pullbackCount > 0)
            ? array_slice($window, $troughIdx + 1, $pullbackCount)
            : [];

        $maxHigh = $pullbackCandles !== []
            ? max(array_map(static fn (Candle $c) => $c->high, $pullbackCandles))
            : $last->high;

        // 2. Касание зоны сопротивления
        $reqTouch = $level - $atr * 0.25;
        $passedTouch = $pullbackCandles !== [] && $maxHigh >= $reqTouch;
        $criteria['pullback_touch'] = new CriterionResult(
            key: 'pullback_touch',
            name: 'Касание зоны сопротивления на откате',
            passed: $passedTouch,
            expected: sprintf('>= %.4f (L - 0.25 ATR)', $reqTouch),
            actual: sprintf('%.4f (%+.2f ATR)', $maxHigh, ($maxHigh - $level) / $atr),
            actualValue: $maxHigh,
            thresholdValue: $reqTouch,
        );
        if (! $passedTouch) {
            $missing[] = 'Касание зоны сопротивления на откате';
        }

        // 3. Удержание уровня сопротивления
        $reqHeld = $level + $atr * 0.40;
        $passedHeld = $pullbackCandles !== [] && $maxHigh <= $reqHeld;
        $criteria['level_held'] = new CriterionResult(
            key: 'level_held',
            name: 'Удержание уровня сопротивления',
            passed: $passedHeld,
            expected: sprintf('<= %.4f (не выше L + 0.40 ATR)', $reqHeld),
            actual: sprintf('%.4f (%+.2f ATR)', $maxHigh, ($maxHigh - $level) / $atr),
            actualValue: $maxHigh,
            thresholdValue: $reqHeld,
        );
        if (! $passedHeld) {
            $missing[] = 'Удержание уровня сопротивления (вылет выше)';
        }

        // 4. Компрессия на откате
        $compCount = CandleSignals::countCompression($pullbackCandles, $atr);
        $hasNarrow = $this->hasNarrowRangeCandle($pullbackCandles, $atr);
        $passedComp = ($compCount >= 1 || $hasNarrow) && $pullbackCandles !== [];
        $criteria['compression'] = new CriterionResult(
            key: 'compression',
            name: 'Компрессия волатильности на откате',
            passed: $passedComp,
            expected: '>= 1 свечи сжатия / узкого бара',
            actual: sprintf('%d свечей сжатия%s', $compCount, $hasNarrow ? ' (+ узкий бар)' : ''),
            actualValue: (float) $compCount,
            thresholdValue: 1.0,
        );
        if (! $passedComp) {
            $missing[] = 'Компрессия волатильности на откате';
        }

        // 5. Импульсный отбой (триггерная свеча)
        $body = CandleSignals::body($last);
        $reqBody = $atr * 0.35;
        $passedTrigger = $last->close < $last->open && $body >= $reqBody && $last->close < $level;
        $criteria['impulse_trigger'] = new CriterionResult(
            key: 'impulse_trigger',
            name: 'Импульсный отбой от уровня',
            passed: $passedTrigger,
            expected: sprintf('Медвежье тело >= %.4f (0.35 ATR), Close < L', $reqBody),
            actual: sprintf('%sтело %.4f (%.2f ATR), Close: %.4f', $last->close < $last->open ? 'Медвежье ' : 'Бычье ', $body, $body / $atr, $last->close),
            actualValue: $body,
            thresholdValue: $reqBody,
        );
        if (! $passedTrigger) {
            $missing[] = 'Импульсный отбой от уровня';
        }

        // 6. Допустимая зона входа
        $minEntry = $level - $atr * 0.50;
        $passedEntryZone = $last->close >= $minEntry;
        $criteria['entry_zone'] = new CriterionResult(
            key: 'entry_zone',
            name: 'Вход в допустимой зоне цены',
            passed: $passedEntryZone,
            expected: sprintf('Close >= %.4f (L - 0.50 ATR)', $minEntry),
            actual: sprintf('Close = %.4f (%+.2f ATR от уровня)', $last->close, ($last->close - $level) / $atr),
            actualValue: $last->close,
            thresholdValue: $minEntry,
        );
        if (! $passedEntryZone) {
            $missing[] = 'Цена ушла слишком далеко от уровня (> 0.50 ATR)';
        }

                // 7. Фильтр по тренду (усиленный: цена ниже EMA50 - буфер, наклон EMA50 падает)
        $ema50 = $ctx->ema50At($ctx->i);
        $ema50Slope = $ctx->i >= 3 ? ($ctx->ema50At($ctx->i) - $ctx->ema50At($ctx->i - 3)) : 0.0;
        $passedTrend = $ema50 > 0.0 ? ($last->close < $ema50 - $atr * 0.10 && $ema50Slope < 0) : true;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Совпадение с глобальным трендом (EMA 50)',
            passed: $passedTrend,
            expected: sprintf('Цена < EMA50 - 0.10 ATR (%.4f) и наклон EMA50 < 0', $ema50 - $atr * 0.10),
            actual: sprintf('Close = %.4f, EMA 50 = %.4f, Slope = %+.6f', $last->close, $ema50, $ema50Slope),
            actualValue: $last->close,
            thresholdValue: $ema50 - $atr * 0.10,
        );
        if (! $passedTrend) {
            $missing[] = 'Вход против тренда (Цена >= EMA 50 - буфер или EMA50 растёт)';
        }

        // 8. Подтверждение объемом
        $avgVol = CandleSignals::avgVolume($pullbackCandles);
        $passedVol = $last->volume > $avgVol * 1.1;
        $criteria['volume_confirmation'] = new CriterionResult(
            key: 'volume_confirmation',
            name: 'Подтверждение отскока объемом',
            passed: $passedVol,
            expected: 'Объем триггерной свечи > 1.1 * Avg Vol отката',
            actual: sprintf('Объем = %.1f (Avg = %.1f)', $last->volume, $avgVol),
            actualValue: $last->volume,
            thresholdValue: $avgVol * 1.1,
        );
        if (! $passedVol) {
            $missing[] = 'Нет всплеска объема на отскоке';
        }

                // 9. Отсутствие агрессивного подхода (Momentum Thrust Filter)
        $aggressive = false;
        $pullbackCount2 = count($pullbackCandles);
        $recentPullback = array_slice($pullbackCandles, max(0, $pullbackCount2 - 3));
        foreach ($recentPullback as $pc) {
            if ($pc->close > $pc->open && CandleSignals::body($pc) > $atr * 0.7) {
                $aggressive = true;
                break;
            }
        }
        $passedExhaustion = !$aggressive;
        $criteria['momentum_exhaustion'] = new CriterionResult(
            key: 'momentum_exhaustion',
            name: 'Отсутствие агрессивного подхода',
            passed: $passedExhaustion,
            expected: 'Тела свечей отката < 0.7 ATR',
            actual: $aggressive ? 'Обнаружена аномально большая свеча отката' : 'Плавный подход',
            actualValue: $aggressive ? 1.0 : 0.0,
            thresholdValue: 0.0,
        );
        if (! $passedExhaustion) {
            $missing[] = 'Агрессивный подход (Momentum Thrust)';
        }

        // 10. Прокол уровня / Пин-бар
        // Триггерная или предыдущая свеча должна касаться зоны L - 0.15 ATR
        $prev = $m > 1 ? $window[$m - 2] : $last;
        $testedLevel = $last->high >= $level - $atr * 0.15 || $prev->high >= $level - $atr * 0.15;
        // Тень сверху у триггера
        $upperWick = $last->high - max($last->open, $last->close);
        $isPinBar = $upperWick >= CandleSignals::body($last) * 0.5;
        
        $passedWick = $testedLevel && ($isPinBar || $last->close < $level);
        $criteria['wick_rejection'] = new CriterionResult(
            key: 'wick_rejection',
            name: 'Прокол уровня (Пин-бар / Тест)',
            passed: $passedWick,
            expected: 'Тест L-0.15 ATR и отторжение (тень)',
            actual: sprintf('High: %.4f, Тень: %.4f', $last->high, $upperWick),
            actualValue: $last->high,
            thresholdValue: $level - $atr * 0.15,
        );
        if (! $passedWick) {
            $missing[] = 'Нет явного прокола или тени отторжения';
        }

        // 11. Торговый план и R:R
        $stopPrice = $maxHigh + $atr * 0.10;
        $plan = $planner->plan($ctx, SignalType::Bounce, Direction::Short, false, $stopPrice);
        $passedRr = $plan !== null && $plan->rrRatio >= 2.0;
        $criteria['risk_reward'] = new CriterionResult(
            key: 'risk_reward',
            name: 'Коэффициент прибыль/риск (R:R)',
            passed: $passedRr,
            expected: 'R:R >= 2.0 и корректный стоп',
            actual: $plan ? sprintf('R:R = 1:%.2f (Риск: %.4f, Стоп: %.4f)', $plan->rrRatio, $plan->stop - $last->close, $plan->stop) : 'Не удалось построить план (стоп <= вход)',
            actualValue: $plan?->rrRatio,
            thresholdValue: 2.0,
        );
        if (! $passedRr) {
            $missing[] = 'Коэффициент R:R < 2.0 или некорректный стоп';
        }

        // 12. Подтверждение MACD (гистограмма в направлении сделки)
        $macdHist = $ctx->macdHistAt($ctx->i);
        $passedMacd = $macdHist < 0;
        $criteria['macd_alignment'] = new CriterionResult(
            key: 'macd_alignment',
            name: 'Подтверждение MACD (гистограмма)',
            passed: $passedMacd,
            expected: 'MACD гистограмма < 0 (медвежий импульс)',
            actual: sprintf('MACD hist = %+.6f', $macdHist),
            actualValue: $macdHist,
            thresholdValue: 0.0,
        );
        if (! $passedMacd) {
            $missing[] = 'MACD гистограмма положительная (бычий импульс)';
        }

        $total = count($criteria);
        $passed = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passed / $total) * 100, 2);
        $isFull = ($passed === $total);

        $passedHard = true;
        foreach (self::HARD_CRITERIA as $hardKey) {
            if (! ($criteria[$hardKey]->passed ?? false)) {
                $passedHard = false;
                break;
            }
        }

        return new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Short,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: ($passedHard && $score >= $this->minEntryScore) ? $plan : null,
            level: $level,
            atr: $atr,
            currentPrice: $last->close,
            criteria: $criteria,
            missingCriteria: $missing,
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: $last->openTime,
            candles: $window,
        );
    }

    /**
     * Дополнительная проверка на свечи с узким телом/диапазоном в фазе отката.
     *
     * @param array<int, Candle> $candles
     */
    private function hasNarrowRangeCandle(array $candles, float $atr): bool
    {
        foreach ($candles as $c) {
            if (CandleSignals::body($c) <= $atr * 0.35 && $c->range() <= $atr * 0.70) {
                return true;
            }
        }

        return false;
    }
}

