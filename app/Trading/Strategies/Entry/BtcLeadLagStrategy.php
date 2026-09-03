<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

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
 * Стратегия входа: BTC Lead-Lag (опережающе-запаздывающий арбитраж).
 *
 * Отслеживает резкий импульс BTC (дамп или памп) и находит монету, которая
 * еще не успела среагировать на это движение (имеет лаг/запаздывание),
 * входя в направлении импульса BTC с соблюдением фильтров рыночной структуры.
 */
final class BtcLeadLagStrategy implements EntryStrategyInterface
{
    public function __construct(
        private readonly ?StrategyLoggerInterface $logger = null,
        private readonly float $minEntryScore = 71.4,
        private readonly float $btcImpulsePct = 0.40,
        private readonly float $minGapPct = 0.25,
        private readonly int $btcLookbackBars = 2,
        private readonly int $altLookbackBars = 2,
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

        return $eval->isFullSignal ? $eval->entrySignal : null;
    }

    public function diagnose(RuleContext $ctx, TradePlanner $planner): ?StrategyEvaluationResult
    {
        // Не торгуем стратегию на самом BTC и требуем данные BTC и минимум 10 свечей
        if ($ctx->symbol === 'BTC-USDT' || ! $ctx->hasBtcData() || $ctx->n < 10 || $ctx->atr <= 0.0) {
            return null;
        }

        $btcRet = $ctx->btcReturnPct($this->btcLookbackBars);
        if ($btcRet === null) {
            return null;
        }

        $altRet = $ctx->returnPct($this->altLookbackBars);

        $shortEval = $this->diagnoseShort($ctx, $planner, $btcRet, $altRet);
        $longEval = $this->diagnoseLong($ctx, $planner, $btcRet, $altRet);

        if ($shortEval->isFullSignal && ! $longEval->isFullSignal) {
            return $shortEval;
        }
        if ($longEval->isFullSignal && ! $shortEval->isFullSignal) {
            return $longEval;
        }

        return $shortEval->score >= $longEval->score ? $shortEval : $longEval;
    }

    private function diagnoseShort(
        RuleContext $ctx,
        TradePlanner $planner,
        float $btcRet,
        float $altRet,
    ): StrategyEvaluationResult {
        $criteria = [];
        $missing = [];

        // 1. [HARD] Импульс BTC вниз
        $passedBtcImpulse = $btcRet <= -$this->btcImpulsePct;
        $criteria['btc_impulse'] = new CriterionResult(
            key: 'btc_impulse',
            name: 'Импульсный дамп BTC',
            passed: $passedBtcImpulse,
            expected: sprintf('BTC доходность <= -%.2f%%', $this->btcImpulsePct),
            actual: sprintf('%.2f%%', $btcRet),
            actualValue: $btcRet,
            thresholdValue: -$this->btcImpulsePct,
        );
        if (! $passedBtcImpulse) {
            $missing[] = sprintf('BTC не показал импульсного дампа (доходность %.2f%%, требуется <= -%.2f%%)', $btcRet, $this->btcImpulsePct);
        }

        // 2. [HARD] Отставание альта (лаг)
        $gap = $altRet - $btcRet;
        $passedLag = $gap >= $this->minGapPct;
        $criteria['lag_gap'] = new CriterionResult(
            key: 'lag_gap',
            name: 'Запаздывание альта относительно BTC',
            passed: $passedLag,
            expected: sprintf('Разрыв >= +%.2f%%', $this->minGapPct),
            actual: sprintf('Альт %.2f%% vs BTC %.2f%% (разрыв %.2f%%)', $altRet, $btcRet, $gap),
            actualValue: $gap,
            thresholdValue: $this->minGapPct,
        );
        if (! $passedLag) {
            $missing[] = sprintf('Альт уже отработал движение или опережает BTC (разрыв %.2f%%, требуется >= %.2f%%)', $gap, $this->minGapPct);
        }

        // 3. [HARD] Защита от шорта сильной монеты (Relative Strength Guard)
        // Не шортим, если монета находится в сильном пампе выше EMA50 с растущим MACD
        $ema50 = $ctx->ema50At($ctx->i);
        $macdHist = $ctx->macdHistAt($ctx->i);
        $isStrongBullPump = ($ctx->price() > $ema50 + $ctx->atr * 0.30) && ($macdHist > 0.0);
        $passedStructure = ! $isStrongBullPump;
        $criteria['structure_guard'] = new CriterionResult(
            key: 'structure_guard',
            name: 'Отсутствие аномальной силы у альта',
            passed: $passedStructure,
            expected: 'Цена не находится в сильном бычьем пампе выше EMA50+0.3ATR',
            actual: $isStrongBullPump ? 'Монета в сильном бычьем импульсе' : 'Структура нейтральная или слабая',
        );
        if (! $passedStructure) {
            $missing[] = 'Монета демонстрирует аномальную относительную силу (выше EMA50 с положительным MACD)';
        }

        // 4. [SOFT] Подтверждение тренда: цена под EMA50 или EMA21
        $ema21 = $ctx->ema21At($ctx->i);
        $passedTrend = $ctx->price() <= $ema50 || $ctx->price() <= $ema21;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Подтверждение слабости (цена под EMA21/EMA50)',
            passed: $passedTrend,
            expected: 'Цена <= EMA21 или EMA50',
            actual: sprintf('Цена %.4f (EMA21: %.4f, EMA50: %.4f)', $ctx->price(), $ema21, $ema50),
        );
        if (! $passedTrend) {
            $missing[] = 'Цена находится выше краткосрочных скользящих средних';
        }

        // 5. [SOFT] Свободное пространство до поддержки (Clear Path)
        // Если уровень ниже цены — проверяем дистанцию, если выше — уровень уже пробит
        $level = $ctx->level;
        $passedClearPath = true;
        if ($level < $ctx->price()) {
            $distAtr = ($ctx->price() - $level) / $ctx->atr;
            $passedClearPath = $distAtr >= 0.40;
        }
        $criteria['clear_path'] = new CriterionResult(
            key: 'clear_path',
            name: 'Свободное пространство до уровня поддержки',
            passed: $passedClearPath,
            expected: 'Дистанция до поддержки >= 0.40 ATR',
            actual: $level < $ctx->price() ? sprintf('%.2f ATR до уровня', ($ctx->price() - $level) / $ctx->atr) : 'Уровень выше текущей цены (пробит)',
        );
        if (! $passedClearPath) {
            $missing[] = 'Цена находится слишком близко к поддержке (< 0.40 ATR)';
        }

        // 6. [SOFT] Всплеск объема BTC на импульсе
        $passedBtcVol = $ctx->btcVolumeSurge(1.2) === true;
        $criteria['btc_volume_surge'] = new CriterionResult(
            key: 'btc_volume_surge',
            name: 'Всплеск объема BTC на импульсе',
            passed: $passedBtcVol,
            expected: 'Объем последней свечи BTC >= 1.2x от среднего',
            actual: $passedBtcVol ? 'Объем BTC повышен' : 'Объем BTC стандартный',
        );
        if (! $passedBtcVol) {
            $missing[] = 'Импульс BTC не подтвержден всплеском объема';
        }

        // 7. [SOFT] Спокойный объем у альта (монета еще не разбужена)
        $passedAltQuiet = ! $ctx->volumeSurge(1.5);
        $criteria['alt_volume_quiet'] = new CriterionResult(
            key: 'alt_volume_quiet',
            name: 'Отсутствие всплеска объема у альта',
            passed: $passedAltQuiet,
            expected: 'Объем альта < 1.5x от среднего (рынок еще не среагировал)',
            actual: $passedAltQuiet ? 'Объем альта спокойный' : 'Объем альта уже аномально вырос',
        );
        if (! $passedAltQuiet) {
            $missing[] = 'На альте уже зафиксирован всплеск объема (возможно, реакция уже началась)';
        }

        // Подсчет баллов
        $totalCount = count($criteria);
        $passedCount = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passedCount / $totalCount) * 100.0, 2);

        // Все Hard criteria обязаны пройти
        $hardPassed = $passedBtcImpulse && $passedLag && $passedStructure;
        $isFullSignal = $hardPassed && $score >= $this->minEntryScore;

        $entrySignal = null;
        if ($isFullSignal) {
            $entrySignal = $planner->plan($ctx, SignalType::BtcLeadLag, Direction::Short);
        }

        return new StrategyEvaluationResult(
            strategy: 'BtcLeadLagStrategy',
            direction: Direction::Short,
            score: $score,
            passedCount: $passedCount,
            totalCount: $totalCount,
            isFullSignal: $isFullSignal,
            entrySignal: $entrySignal,
            level: $ctx->level,
            atr: $ctx->atr,
            currentPrice: $ctx->price(),
            criteria: $criteria,
            missingCriteria: $missing,
            indicators: [
                'btc_return' => $btcRet,
                'alt_return' => $altRet,
                'gap' => $gap,
                'ema50' => $ema50,
                'ema21' => $ema21,
                'atr' => $ctx->atr,
            ],
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: $ctx->last()->openTime,
            candles: $ctx->candles,
        );
    }

    private function diagnoseLong(
        RuleContext $ctx,
        TradePlanner $planner,
        float $btcRet,
        float $altRet,
    ): StrategyEvaluationResult {
        $criteria = [];
        $missing = [];

        // 1. [HARD] Импульс BTC вверх
        $passedBtcImpulse = $btcRet >= $this->btcImpulsePct;
        $criteria['btc_impulse'] = new CriterionResult(
            key: 'btc_impulse',
            name: 'Импульсный памп BTC',
            passed: $passedBtcImpulse,
            expected: sprintf('BTC доходность >= +%.2f%%', $this->btcImpulsePct),
            actual: sprintf('%.2f%%', $btcRet),
            actualValue: $btcRet,
            thresholdValue: $this->btcImpulsePct,
        );
        if (! $passedBtcImpulse) {
            $missing[] = sprintf('BTC не показал импульсного пампа (доходность %.2f%%, требуется >= +%.2f%%)', $btcRet, $this->btcImpulsePct);
        }

        // 2. [HARD] Отставание альта (лаг)
        $gap = $btcRet - $altRet;
        $passedLag = $gap >= $this->minGapPct;
        $criteria['lag_gap'] = new CriterionResult(
            key: 'lag_gap',
            name: 'Запаздывание альта относительно BTC',
            passed: $passedLag,
            expected: sprintf('Разрыв >= +%.2f%%', $this->minGapPct),
            actual: sprintf('Альт %.2f%% vs BTC %.2f%% (разрыв %.2f%%)', $altRet, $btcRet, $gap),
            actualValue: $gap,
            thresholdValue: $this->minGapPct,
        );
        if (! $passedLag) {
            $missing[] = sprintf('Альт уже отработал движение или опережает BTC (разрыв %.2f%%, требуется >= %.2f%%)', $gap, $this->minGapPct);
        }

        // 3. [HARD] Защита от лонга слабой монеты (Relative Strength Guard)
        // Не лонгуем, если монета находится в сильном даме ниже EMA50 с падающим MACD
        $ema50 = $ctx->ema50At($ctx->i);
        $macdHist = $ctx->macdHistAt($ctx->i);
        $isStrongBearDump = ($ctx->price() < $ema50 - $ctx->atr * 0.30) && ($macdHist < 0.0);
        $passedStructure = ! $isStrongBearDump;
        $criteria['structure_guard'] = new CriterionResult(
            key: 'structure_guard',
            name: 'Отсутствие аномальной слабости у альта',
            passed: $passedStructure,
            expected: 'Цена не находится в сильном медвежьем дампе ниже EMA50-0.3ATR',
            actual: $isStrongBearDump ? 'Монета в сильном медвежьем импульсе' : 'Структура нейтральная или сильная',
        );
        if (! $passedStructure) {
            $missing[] = 'Монета демонстрирует аномальную относительную слабость (ниже EMA50 с отрицательным MACD)';
        }

        // 4. [SOFT] Подтверждение тренда: цена над EMA50 или EMA21
        $ema21 = $ctx->ema21At($ctx->i);
        $passedTrend = $ctx->price() >= $ema50 || $ctx->price() >= $ema21;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Подтверждение силы (цена над EMA21/EMA50)',
            passed: $passedTrend,
            expected: 'Цена >= EMA21 или EMA50',
            actual: sprintf('Цена %.4f (EMA21: %.4f, EMA50: %.4f)', $ctx->price(), $ema21, $ema50),
        );
        if (! $passedTrend) {
            $missing[] = 'Цена находится ниже краткосрочных скользящих средних';
        }

        // 5. [SOFT] Свободное пространство до сопротивления (Clear Path)
        $level = $ctx->level;
        $passedClearPath = true;
        if ($level > $ctx->price()) {
            $distAtr = ($level - $ctx->price()) / $ctx->atr;
            $passedClearPath = $distAtr >= 0.40;
        }
        $criteria['clear_path'] = new CriterionResult(
            key: 'clear_path',
            name: 'Свободное пространство до уровня сопротивления',
            passed: $passedClearPath,
            expected: 'Дистанция до сопротивления >= 0.40 ATR',
            actual: $level > $ctx->price() ? sprintf('%.2f ATR до уровня', ($level - $ctx->price()) / $ctx->atr) : 'Уровень ниже текущей цены (пробит)',
        );
        if (! $passedClearPath) {
            $missing[] = 'Цена находится слишком близко к сопротивлению (< 0.40 ATR)';
        }

        // 6. [SOFT] Всплеск объема BTC на импульсе
        $passedBtcVol = $ctx->btcVolumeSurge(1.2) === true;
        $criteria['btc_volume_surge'] = new CriterionResult(
            key: 'btc_volume_surge',
            name: 'Всплеск объема BTC на импульсе',
            passed: $passedBtcVol,
            expected: 'Объем последней свечи BTC >= 1.2x от среднего',
            actual: $passedBtcVol ? 'Объем BTC повышен' : 'Объем BTC стандартный',
        );
        if (! $passedBtcVol) {
            $missing[] = 'Импульс BTC не подтвержден всплеском объема';
        }

        // 7. [SOFT] Спокойный объем у альта (монета еще не разбужена)
        $passedAltQuiet = ! $ctx->volumeSurge(1.5);
        $criteria['alt_volume_quiet'] = new CriterionResult(
            key: 'alt_volume_quiet',
            name: 'Отсутствие всплеска объема у альта',
            passed: $passedAltQuiet,
            expected: 'Объем альта < 1.5x от среднего (рынок еще не среагировал)',
            actual: $passedAltQuiet ? 'Объем альта спокойный' : 'Объем альта уже аномально вырос',
        );
        if (! $passedAltQuiet) {
            $missing[] = 'На альте уже зафиксирован всплеск объема (возможно, реакция уже началась)';
        }

        // Подсчет баллов
        $totalCount = count($criteria);
        $passedCount = count(array_filter($criteria, static fn (CriterionResult $c) => $c->passed));
        $score = round(($passedCount / $totalCount) * 100.0, 2);

        // Все Hard criteria обязаны пройти
        $hardPassed = $passedBtcImpulse && $passedLag && $passedStructure;
        $isFullSignal = $hardPassed && $score >= $this->minEntryScore;

        $entrySignal = null;
        if ($isFullSignal) {
            $entrySignal = $planner->plan($ctx, SignalType::BtcLeadLag, Direction::Long);
        }

        return new StrategyEvaluationResult(
            strategy: 'BtcLeadLagStrategy',
            direction: Direction::Long,
            score: $score,
            passedCount: $passedCount,
            totalCount: $totalCount,
            isFullSignal: $isFullSignal,
            entrySignal: $entrySignal,
            level: $ctx->level,
            atr: $ctx->atr,
            currentPrice: $ctx->price(),
            criteria: $criteria,
            missingCriteria: $missing,
            indicators: [
                'btc_return' => $btcRet,
                'alt_return' => $altRet,
                'gap' => $gap,
                'ema50' => $ema50,
                'ema21' => $ema21,
                'atr' => $ctx->atr,
            ],
            symbol: $ctx->symbol,
            interval: $ctx->interval,
            candleOpenTime: $ctx->last()->openTime,
            candles: $ctx->candles,
        );
    }
}
