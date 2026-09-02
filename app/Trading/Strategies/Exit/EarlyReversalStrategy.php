<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Exit;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт сигналов свечного анализа
use App\Trading\Analysis\CandleSignals;
// Импорт интерфейса стратегии выхода
use App\Trading\Contracts\ExitStrategyInterface;
// Импорт DTO сигнала выхода
use App\Trading\DTO\ExitSignal;
// Импорт состояния позиции
use App\Trading\DTO\PositionState;
// Импорт перечисления направления
use App\Trading\Enums\Direction;
// Импорт перечисления причин разворота
use App\Trading\Enums\ExitReason;
// Импорт типа выхода
use App\Trading\Enums\ExitType;

/**
 * Стратегия выхода №3 (Приоритет 4) — Досрочный выход при формировании разворотного паттерна против позиции.
 */
final class EarlyReversalStrategy implements ExitStrategyInterface
{
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Оценка разворотных паттернов против открытой позиции.
     */
    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // Флаг лонговой позиции
        $isLong = $position->direction === Direction::Long;

        // 0. Опережающий выход по резкому импульсу BTC (BTC Lead-Lag Fast Exit)
        if ($this->hasBtcReversal($ctx, $isLong)) {
            return new ExitSignal(ExitType::EarlyReversal, 100, reason: ExitReason::BtcReversal);
        }

        // Проверяем наличие минимум 3 свечей в истории для формирования свечного паттерна
        if ($ctx->n < 3) {
            return null;
        }

        // Текущее значение ATR
        $atr = $ctx->atr;
        // Индекс последней свечи
        $i = $ctx->i;

        // 1. Проверяем, что две предыдущие свечи были свечами сжатия (компрессии)
        if (! $this->hasPrecedingCompression($ctx, $atr, $i)) {
            return null;
        }

        // 2. Проверяем, что последняя свеча сформировала контр-импульс против нашей позиции
        if (! $this->hasCounterImpulse($ctx, $isLong, $atr)) {
            return null;
        }

        // 3. Определяем подтверждающую причину разворота (дивергенция, аномальный объем или разворот EMA)
        $reason = $this->determineReversalReason($ctx, $isLong);
        // Если ни одна из подтверждающих причин не обнаружена
        if ($reason === null) {
            return null;
        }

        // Формируем сигнал на закрытие 100% позиции с указанием причины раннего разворота
        return new ExitSignal(ExitType::EarlyReversal, 100, reason: $reason);
    }

    /**
     * Проверка: были ли две предыдущие свечи подряд свечами компрессии.
     */
    private function hasPrecedingCompression(RuleContext $ctx, float $atr, int $i): bool
    {
        // Проверяем свечу i-1 на компрессию
        return CandleSignals::isCompression($ctx->candles[$i - 1], $atr)
            // И свечу i-2 на компрессию
            && CandleSignals::isCompression($ctx->candles[$i - 2], $atr);
    }

    /**
     * Проверка: является ли последняя свеча импульсом в направлении против нашей позиции.
     */
    private function hasCounterImpulse(RuleContext $ctx, bool $isLong, float $atr): bool
    {
        // Для лонга контр-импульс — это медвежья свеча, для шорта — бычья
        return $isLong
            ? CandleSignals::isBearishImpulse($ctx->last(), $atr)
            : CandleSignals::isBullishImpulse($ctx->last(), $atr);
    }

    /**
     * Поиск первой совпавшей причины разворота: дивергенция, поглощение объема или разворот скользящей EMA8.
     */
    private function determineReversalReason(RuleContext $ctx, bool $isLong): ?ExitReason
    {
        // 1. Проверяем дивергенцию по MACD
        if ($this->hasDivergence($ctx, $isLong)) {
            return ExitReason::Divergence;
        }

        // 2. Проверяем поглощение (усилие без результата: высокий объем при крошечном теле)
        if ($this->hasAbsorption($ctx)) {
            return ExitReason::Absorption;
        }

        // 3. Проверяем разворот скользящей средней EMA8 против позиции
        if ($this->hasEmaTurn($ctx, $isLong)) {
            return ExitReason::EmaTurn;
        }

        // Причин не найдено
        return null;
    }

    /**
     * Проверка дивергенции MACD против текущей позиции.
     */
    private function hasDivergence(RuleContext $ctx, bool $isLong): bool
    {
        // Для лонга ищем медвежью дивергенцию, для шорта — бычью
        return $isLong ? $ctx->bearishDivergence() : $ctx->bullishDivergence();
    }

    /**
     * Проверка поглощения объема: объем выше среднего в 1.5 раза, но тело свечи меньше 0.2 ATR.
     */
    private function hasAbsorption(RuleContext $ctx): bool
    {
        // Последняя свеча
        $last = $ctx->last();

        // Объем свечи превышает средний объем за 20 периодов более чем в 1.5 раза
        return $last->volume > CandleSignals::avgVolume($ctx->candles) * 1.5
            // При этом тело свечи очень маленькое (< 20% ATR)
            && CandleSignals::body($last) < $ctx->atr * 0.20;
    }

    /**
     * Проверка разворота скользящей средней EMA8 против направления сделки на 3 последних барах.
     */
    private function hasEmaTurn(RuleContext $ctx, bool $isLong): bool
    {
        // Индекс бара
        $i = $ctx->i;

        // Для лонга: EMA8 последовательно падает (i < i-1 < i-2)
        // Для шорта: EMA8 последовательно растет (i > i-1 > i-2)
        return $isLong
            ? ($ctx->ema8At($i) < $ctx->ema8At($i - 1) && $ctx->ema8At($i - 1) < $ctx->ema8At($i - 2))
            : ($ctx->ema8At($i) > $ctx->ema8At($i - 1) && $ctx->ema8At($i - 1) > $ctx->ema8At($i - 2));
    }

    /**
     * Опережающий разворот по импульсу BTC (BTC Lead-Lag Fast Exit).
     */
    private function hasBtcReversal(RuleContext $ctx, bool $isLong): bool
    {
        if ($ctx->symbol === 'BTC-USDT' || ! $ctx->hasBtcData()) {
            return false;
        }

        $fastExitDump = (float) ($this->config['btc_fast_exit_dump_percent'] ?? 0.35);
        $fastExitPump = (float) ($this->config['btc_fast_exit_pump_percent'] ?? 0.35);

        // Импульсное изменение цены BTC за последние 2 свечи
        $btcRet2 = $ctx->btcReturnPct(2);
        if ($btcRet2 === null) {
            return false;
        }

        // Для лонга: если BTC резко летит вниз
        if ($isLong && $btcRet2 <= -$fastExitDump) {
            return true;
        }

        // Для шорта: если BTC резко летит вверх
        if (! $isLong && $btcRet2 >= $fastExitPump) {
            return true;
        }

        return false;
    }
}
