<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Contracts\StrategyLoggerInterface;
use App\Trading\Contracts\EntryStrategyInterface;
use App\Trading\DTO\CriterionResult;
use App\Trading\DTO\EntrySignal;
use App\Trading\DTO\StrategyEvaluationResult;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №1 — Отскок от ключевого уровня (Bounce).
 */
final class BounceStrategy implements EntryStrategyInterface
{
    public function __construct(
        private readonly ?StrategyLoggerInterface $logger = null,
        private readonly float $minEntryScore = 100.0,
        private readonly int $lookbackCandles = 10,
        private readonly float $levelApproachAtr = 0.50,
        private readonly float $bounceReversalAtr = 0.10,
        private readonly float $minAtrPercent = 0.20,
        private readonly float $stopBufferAtr = 0.25,
    ) {
    }

    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        $eval = $this->diagnose($ctx, $planner);
        if ($eval === null) {
            return null;
        }

        if ($eval->score >= 50.0 && $this->logger !== null) {
            $this->logger->log($eval);
        }

        return $eval->score >= $this->minEntryScore ? $eval->entrySignal : null;
    }

    public function diagnose(RuleContext $ctx, TradePlanner $planner): ?StrategyEvaluationResult
    {
        if ($ctx->n < $this->lookbackCandles || $ctx->atr <= 0.0) {
            return null;
        }

        $lookback = min($ctx->n, $this->lookbackCandles);
        $window = $ctx->slice($lookback);
        $last = $window[count($window) - 1];

        $longEval = $this->diagnoseLong($ctx, $planner, $window, $last);
        $shortEval = $this->diagnoseShort($ctx, $planner, $window, $last);

        if ($longEval->isFullSignal && !$shortEval->isFullSignal) {
            return $longEval;
        }
        if ($shortEval->isFullSignal && !$longEval->isFullSignal) {
            return $shortEval;
        }

        return $longEval->score >= $shortEval->score ? $longEval : $shortEval;
    }

    private function diagnoseLong(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): StrategyEvaluationResult {
        $atr = $ctx->atr;
        $level = $ctx->level;
        $criteria = [];
        $missing = [];

        // 1. Подход к уровню поддержки
        $minLow = min(array_map(static fn (Candle $c) => $c->low, $window));
        // Зона поддержки: от L - 0.5 ATR до L + 0.5 ATR
        $passedApproach = $minLow <= $level + $atr * $this->levelApproachAtr && $minLow >= $level - $atr * $this->levelApproachAtr;
        
        $criteria['level_approach'] = new CriterionResult(
            key: 'level_approach',
            name: 'Подход к уровню поддержки',
            passed: $passedApproach,
            expected: sprintf('В зоне [%.4f, %.4f]', $level - $atr * $this->levelApproachAtr, $level + $atr * $this->levelApproachAtr),
            actual: sprintf('%.4f', $minLow),
            actualValue: $minLow,
            thresholdValue: $level,
        );
        if (! $passedApproach) {
            $missing[] = 'Цена не подходила к зоне поддержки или пробила слишком глубоко';
        }

        // 2. Отбой на 10% от ATR вверх от минимальной цены
        $reqBounce = $minLow + $atr * $this->bounceReversalAtr;
        $passedBounce = $last->close >= $reqBounce;
        
        $criteria['atr_bounce'] = new CriterionResult(
            key: 'atr_bounce',
            name: 'Отбой от минимума на 10% ATR',
            passed: $passedBounce,
            expected: sprintf('>= %.4f', $reqBounce),
            actual: sprintf('Close = %.4f', $last->close),
            actualValue: $last->close,
            thresholdValue: $reqBounce,
        );
        if (! $passedBounce) {
            $missing[] = 'Нет явного отбоя вверх от низов';
        }

        // 3. Нормальный уровень ATR
        $minAtr = $last->close * ($this->minAtrPercent / 100.0);
        $passedNormalAtr = $atr > $minAtr;
        
        $criteria['normal_atr'] = new CriterionResult(
            key: 'normal_atr',
            name: 'Нормальный уровень ATR',
            passed: $passedNormalAtr,
            expected: sprintf('ATR > %.4f (%.2f%%%% от цены)', $minAtr, $this->minAtrPercent),
            actual: sprintf('ATR = %.4f', $atr),
            actualValue: $atr,
            thresholdValue: $minAtr,
        );
        if (! $passedNormalAtr) {
            $missing[] = 'Слишком маленький ATR';
        }

        // 4. Цена входа в допустимой зоне уровня (не перекуплена / не на пике)
        $passedEntryZone = $last->close <= $level + $atr * $this->levelApproachAtr && $last->close >= $level - $atr * $this->levelApproachAtr;

        $criteria['entry_zone'] = new CriterionResult(
            key: 'entry_zone',
            name: 'Цена в зоне входа от уровня',
            passed: $passedEntryZone,
            expected: sprintf('В зоне [%.4f, %.4f]', $level - $atr * $this->levelApproachAtr, $level + $atr * $this->levelApproachAtr),
            actual: sprintf('Close = %.4f', $last->close),
            actualValue: $last->close,
            thresholdValue: $level,
        );
        if (! $passedEntryZone) {
            $missing[] = 'Цена ушла слишком далеко от уровня поддержки (вход на пике)';
        }

        // 5. Подтверждение бычьей свечой (закрытие выше открытия или EMA8 растет)
        $passedBullish = $last->close >= $last->open || $ctx->ema8Rising();

        $criteria['bullish_confirmation'] = new CriterionResult(
            key: 'bullish_confirmation',
            name: 'Подтверждение отскока (зеленая свеча или EMA8 растет)',
            passed: $passedBullish,
            expected: 'Close >= Open или EMA8 растет',
            actual: sprintf('Close %.4f vs Open %.4f, EMA8 Rising: %s', $last->close, $last->open, $ctx->ema8Rising() ? 'Yes' : 'No'),
            actualValue: $last->close,
            thresholdValue: $last->open,
        );
        if (! $passedBullish) {
            $missing[] = 'Нет подтверждения разворота вверх (медвежья свеча)';
        }

        $total = count($criteria);
        $passed = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passed / $total) * 100, 2);
        $isFull = ($passed === $total);

        $technicalStop = min($minLow, $level) - ($atr * $this->stopBufferAtr);
        $plan = $isFull ? $planner->plan($ctx, SignalType::Bounce, Direction::Long, stopPrice: $technicalStop) : null;

        return new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Long,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: $plan,
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

    private function diagnoseShort(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): StrategyEvaluationResult {
        $atr = $ctx->atr;
        $level = $ctx->level;
        $criteria = [];
        $missing = [];

        // 1. Подход к уровню сопротивления
        $maxHigh = max(array_map(static fn (Candle $c) => $c->high, $window));
        // Зона сопротивления: от L - 0.5 ATR до L + 0.5 ATR
        $passedApproach = $maxHigh >= $level - $atr * $this->levelApproachAtr && $maxHigh <= $level + $atr * $this->levelApproachAtr;
        
        $criteria['level_approach'] = new CriterionResult(
            key: 'level_approach',
            name: 'Подход к уровню сопротивления',
            passed: $passedApproach,
            expected: sprintf('В зоне [%.4f, %.4f]', $level - $atr * $this->levelApproachAtr, $level + $atr * $this->levelApproachAtr),
            actual: sprintf('%.4f', $maxHigh),
            actualValue: $maxHigh,
            thresholdValue: $level,
        );
        if (! $passedApproach) {
            $missing[] = 'Цена не подходила к зоне сопротивления или пробила слишком глубоко';
        }

        // 2. Отбой на 10% от ATR вниз от максимальной цены
        $reqBounce = $maxHigh - $atr * $this->bounceReversalAtr;
        $passedBounce = $last->close <= $reqBounce;
        
        $criteria['atr_bounce'] = new CriterionResult(
            key: 'atr_bounce',
            name: 'Отбой от максимума на 10% ATR',
            passed: $passedBounce,
            expected: sprintf('<= %.4f', $reqBounce),
            actual: sprintf('Close = %.4f', $last->close),
            actualValue: $last->close,
            thresholdValue: $reqBounce,
        );
        if (! $passedBounce) {
            $missing[] = 'Нет явного отбоя вниз от пиков';
        }

        // 3. Нормальный уровень ATR
        $minAtr = $last->close * ($this->minAtrPercent / 100.0);
        $passedNormalAtr = $atr > $minAtr;
        
        $criteria['normal_atr'] = new CriterionResult(
            key: 'normal_atr',
            name: 'Нормальный уровень ATR',
            passed: $passedNormalAtr,
            expected: sprintf('ATR > %.4f (%.2f%%%% от цены)', $minAtr, $this->minAtrPercent),
            actual: sprintf('ATR = %.4f', $atr),
            actualValue: $atr,
            thresholdValue: $minAtr,
        );
        if (! $passedNormalAtr) {
            $missing[] = 'Слишком маленький ATR';
        }

        // 4. Строгий фильтр тренда для SHORT (EMA8 должна падать, цена ниже EMA50)
        $passedTrend = $ctx->ema8Falling() && $last->close < $ctx->ema50At($ctx->i);
        
        $criteria['strict_trend'] = new CriterionResult(
            key: 'strict_trend',
            name: 'Тренд вниз (EMA8 падает, цена < EMA50)',
            passed: $passedTrend,
            expected: 'EMA8 падает, цена < EMA50',
            actual: sprintf('EMA8 Falling: %s, Price %.4f vs EMA50 %.4f', 
                $ctx->ema8Falling() ? 'Yes' : 'No', 
                $last->close, 
                $ctx->ema50At($ctx->i)),
            actualValue: $last->close,
            thresholdValue: $ctx->ema50At($ctx->i),
        );
        if (! $passedTrend) {
            $missing[] = 'Против тренда (EMA не подтверждает падение)';
        }

        // 5. Цена входа в допустимой зоне уровня (не перепродана / не на дне)
        $passedEntryZone = $last->close >= $level - $atr * $this->levelApproachAtr && $last->close <= $level + $atr * $this->levelApproachAtr;

        $criteria['entry_zone'] = new CriterionResult(
            key: 'entry_zone',
            name: 'Цена в зоне входа от уровня',
            passed: $passedEntryZone,
            expected: sprintf('В зоне [%.4f, %.4f]', $level - $atr * $this->levelApproachAtr, $level + $atr * $this->levelApproachAtr),
            actual: sprintf('Close = %.4f', $last->close),
            actualValue: $last->close,
            thresholdValue: $level,
        );
        if (! $passedEntryZone) {
            $missing[] = 'Цена ушла слишком далеко от уровня сопротивления (вход на дне)';
        }

        // 6. Подтверждение медвежьей свечой (закрытие ниже открытия или EMA8 падает)
        $passedBearish = $last->close <= $last->open || $ctx->ema8Falling();

        $criteria['bearish_confirmation'] = new CriterionResult(
            key: 'bearish_confirmation',
            name: 'Подтверждение отскока (красная свеча или EMA8 падает)',
            passed: $passedBearish,
            expected: 'Close <= Open или EMA8 падает',
            actual: sprintf('Close %.4f vs Open %.4f, EMA8 Falling: %s', $last->close, $last->open, $ctx->ema8Falling() ? 'Yes' : 'No'),
            actualValue: $last->close,
            thresholdValue: $last->open,
        );
        if (! $passedBearish) {
            $missing[] = 'Нет подтверждения разворота вниз (бычья свеча)';
        }

        $total = count($criteria);
        $passed = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passed / $total) * 100, 2);
        $isFull = ($passed === $total);

        $technicalStop = max($maxHigh, $level) + ($atr * $this->stopBufferAtr);
        $plan = $isFull ? $planner->plan($ctx, SignalType::Bounce, Direction::Short, stopPrice: $technicalStop) : null;

        return new StrategyEvaluationResult(
            strategy: 'BounceStrategy',
            direction: Direction::Short,
            score: $score,
            passedCount: $passed,
            totalCount: $total,
            isFullSignal: $isFull,
            entrySignal: $plan,
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
}
