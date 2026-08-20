<?php

declare(strict_types=1);

namespace App\Trading\Agent;

// Импорт математических утилит для расчета EMA/MACD/ATR
use App\Market\Analysis\Support\SeriesMath;
// Импорт DTO свечи
use App\Market\DTO\Candle;
// Импорт интерфейса стратегии входа
use App\Trading\Contracts\EntryStrategyInterface;
// Импорт интерфейса стратегии выхода
use App\Trading\Contracts\ExitStrategyInterface;
// Импорт основного интерфейса торгового агента
use App\Trading\Contracts\TradingAgentInterface;
// Импорт DTO общего результата работы агента
use App\Trading\DTO\AgentResult;
// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт DTO сигнала на выход
use App\Trading\DTO\ExitSignal;
// Импорт DTO снимка индикаторов
use App\Trading\DTO\IndicatorSnapshot;
// Импорт DTO состояния открытой позиции
use App\Trading\DTO\PositionState;
// Импорт стратегии отскока
use App\Trading\Strategies\Entry\BounceStrategy;
// Импорт стратегии ложного пробоя
use App\Trading\Strategies\Entry\FalseBreakoutStrategy;
// Импорт стратегии пробоя и ретеста
use App\Trading\Strategies\Entry\RetestStrategy;
// Импорт стратегии отката по тренду
use App\Trading\Strategies\Entry\TrendPullbackStrategy;
// Импорт стратегии раннего разворота
use App\Trading\Strategies\Exit\EarlyReversalStrategy;
// Импорт стратегии стоп-лосса
use App\Trading\Strategies\Exit\StopLossStrategy;
// Импорт стратегии фиксации цели 1
use App\Trading\Strategies\Exit\Target1Strategy;
// Импорт стратегии фиксации цели 2
use App\Trading\Strategies\Exit\Target2Strategy;

/**
 * Торговый агент — оркестратор процесса анализа и принятия решений.
 */
final class TradingAgent implements TradingAgentInterface
{
    // Сервис расчета параметров ордера (стоп, тейки)
    private readonly TradePlanner $planner;
    // Сервис глобальных фильтров рынка
    private readonly EntryGuard $guard;

    /** @var list<EntryStrategyInterface> Список активных стратегий входа */
    private readonly array $entryStrategies;

    /** @var list<ExitStrategyInterface> Список активных стратегий выхода */
    private readonly array $exitStrategies;

    /**
     * @param array<string, mixed> $config
     * @param list<EntryStrategyInterface>|null $entryStrategies
     * @param list<ExitStrategyInterface>|null $exitStrategies
     */
    public function __construct(
        private readonly array $config = [],
        ?TradePlanner $planner = null,
        ?EntryGuard $guard = null,
        ?array $entryStrategies = null,
        ?array $exitStrategies = null,
    ) {
        // Инициализация планировщика сделок
        $this->planner = $planner ?? new TradePlanner($this->config);
        // Инициализация фильтров защиты
        $this->guard = $guard ?? new EntryGuard($this->config);

        // Регистрация набора стратегий входа по умолчанию (активна только BounceStrategy)
        $this->entryStrategies = $entryStrategies ?? [
            new BounceStrategy(),
        ];

        // Регистрация набора стратегий выхода в порядке строгого приоритета
        $this->exitStrategies = $exitStrategies ?? [
            new StopLossStrategy(),
            new Target2Strategy(),
            new Target1Strategy(),
            new EarlyReversalStrategy(),
        ];
    }

    /**
     * Основной метод оценки одного барового состояния.
     */
    public function evaluate(
        array $candles,
        float $level,
        ?float $atr = null,
        ?PositionState $position = null,
        array $recentSignalTypes = [],
    ): AgentResult {
        // Сбрасываем ключи массива свечей
        $candles = array_values($candles);
        // Количество свечей в истории
        $n = count($candles);

        // Рассчитываем ATR (SMA 14 периодов), если он не был передан извне
        $atr = $atr ?? SeriesMath::atrSma($candles, 14);
        // Извлекаем цены закрытия
        $closes = array_map(static fn (Candle $c) => $c->close, $candles);

        // Вычисляем индикаторы
        $ema8 = SeriesMath::ema($closes, 8);
        $ema21 = SeriesMath::ema($closes, 21);
        $macd = SeriesMath::macd($closes, 12, 26, 9);

        // Создаем снимок текущих значений индикаторов
        $indicators = $this->createIndicatorSnapshot($candles, $atr, $ema8, $ema21, $macd);
        // Создаем объект контекста правил со всеми данными
        $ctx = new RuleContext($candles, $level, $atr, $ema8, $ema21, $macd);

        // Если есть открытая позиция — проверяем стратегии выхода
        $exit = $position !== null ? $this->evaluateExit($ctx, $position) : null;

        // Если позиции нет, свечей >= 50 и ATR > 0 — проверяем стратегии входа
        $entry = ($position === null && $n >= 50 && $atr > 0.0)
            ? $this->evaluateEntry($ctx, $recentSignalTypes)
            : null;

        // Возвращаем итоговый DTO результат
        return new AgentResult($entry, $exit, $indicators);
    }

    /**
     * Формирование снимка индикаторов на последнем баре.
     *
     * @param array<int, Candle> $candles
     * @param array<int, float> $ema8
     * @param array<int, float> $ema21
     * @param array{line: array<int, float>, signal: array<int, float>, histogram: array<int, float>} $macd
     */
    private function createIndicatorSnapshot(
        array $candles,
        float $atr,
        array $ema8,
        array $ema21,
        array $macd,
    ): IndicatorSnapshot {
        // Индекс последнего закрытого бара
        $i = count($candles) - 1;

        // Сохраняем значения индикаторов в неизменяемый DTO
        return new IndicatorSnapshot(
            ema8: $ema8[$i] ?? 0.0,
            ema21: $ema21[$i] ?? 0.0,
            macdLine: $macd['line'][$i] ?? 0.0,
            macdSignal: $macd['signal'][$i] ?? 0.0,
            macdHist: $macd['histogram'][$i] ?? 0.0,
            atr: $atr,
        );
    }

    /**
     * Последовательная проверка всех стратегий входа.
     *
     * @param list<string> $recentSignalTypes
     */
    private function evaluateEntry(RuleContext $ctx, array $recentSignalTypes): ?EntrySignal
    {
        // 1. Проверяем глобальные защитные фильтры
        if (! $this->guard->allows($ctx)) {
            return null;
        }

        // Минимальное соотношение прибыль/риск (по умолчанию 2.0)
        $minRr = (float) ($this->config['min_rr'] ?? 2.0);

        // 2. Итерируемся по всем зарегистрированным стратегиям входа
        foreach ($this->entryStrategies as $strategy) {
            // Вызываем стратегию для поиска сетапа
            $signal = $strategy->evaluate($ctx, $this->planner);
            // Если стратегия не нашла сигнал — переходим к следующей
            if ($signal === null) {
                continue;
            }

            // Фильтр дубликатов: пропускаем, если такой же тип сигнала уже открывался недавно
            if (in_array($signal->type->value, $recentSignalTypes, true)) {
                continue;
            }

            // Фильтр R:R: отбрасываем сделки с соотношением прибыль/риск меньше порога
            if ($signal->rrRatio < $minRr) {
                continue;
            }

            // Возвращаем первый валидный сигнал
            return $signal;
        }

        // Ни одна стратегия не дала сигнала
        return null;
    }

    /**
     * Последовательная проверка стратегий выхода по приоритету.
     */
    private function evaluateExit(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // Итерируемся по стратегиям выхода (StopLoss -> Target2 -> Target1 -> EarlyReversal)
        foreach ($this->exitStrategies as $strategy) {
            // Оцениваем условия выхода
            $exit = $strategy->evaluate($ctx, $position);
            // Если условие сработало — немедленно возвращаем сигнал на закрытие/частичное закрытие
            if ($exit !== null) {
                return $exit;
            }
        }

        // Условий для выхода нет
        return null;
    }
}
