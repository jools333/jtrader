<?php

declare(strict_types=1);

namespace App\Trading\Agent;

use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;

/**
 * Глобальные фильтры защиты: предотвращают открытие сделок в неблагоприятных рыночных фазах.
 */
final class EntryGuard
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Базовый фильтр рыночного режима (проверка на мертвый флет).
     */
    public function allowsMarket(RuleContext $ctx): bool
    {
        $minFlatWidth = (float) ($this->config['min_flat_width'] ?? 0.30);

        return $ctx->recentWidth(5) >= $ctx->atr * $minFlatWidth;
    }

    /**
     * Проверка, разрешено ли текущее состояние рынка для поиска точек входа.
     */
    public function allows(RuleContext $ctx, ?Direction $direction = null, ?SignalType $signalType = null): bool
    {
        // 1. Порог минимальной ширины диапазона последних свечей (по умолчанию 30% от ATR)
        if (! $this->allowsMarket($ctx)) {
            return false;
        }

        // 2. Порог максимального удаления цены от уровня (только для стратегий от уровней)
        if ($signalType !== SignalType::BtcLeadLag) {
            $maxAtrTravel = (float) ($this->config['max_atr_travel'] ?? 0.60);
            // Если цена уже ушла дальше 60% ATR от уровня — вход блокируется
            if ($ctx->atrTravelFraction() > $maxAtrTravel) {
                return false;
            }
        }

        // 3. Межрыночный фильтр BTC (BTC Market Regime / BTC Anchor)
        if ($direction !== null && $ctx->symbol !== 'BTC-USDT' && $ctx->hasBtcData()) {
            $btcFilterEnabled = (bool) ($this->config['btc_filter_enabled'] ?? true);
            if ($btcFilterEnabled) {
                $btcRet3 = $ctx->btcReturnPct(3);
                $maxDump = (float) ($this->config['btc_max_dump_percent'] ?? 0.20);
                $maxPump = (float) ($this->config['btc_max_pump_percent'] ?? 0.20);

                // Для импульсной стратегии BTC Lead-Lag: проверяем только прямое противоречие движению BTC
                if ($signalType === SignalType::BtcLeadLag) {
                    if ($direction === Direction::Long && $btcRet3 !== null && $btcRet3 < -$maxDump) {
                        return false;
                    }
                    if ($direction === Direction::Short && $btcRet3 !== null && $btcRet3 > $maxPump) {
                        return false;
                    }
                } else {
                    // Стандартный фильтр тренда BTC для позиционных стратегий (Bounce)
                    if ($direction === Direction::Long) {
                        // Для LONG: блокируем вход, если BTC падает более чем на maxDump % за 3 свечи
                        if ($btcRet3 !== null && $btcRet3 < -$maxDump) {
                            return false;
                        }
                        // Или если краткосрочный тренд BTC падает и находится ниже EMA50
                        if ($ctx->btcEma8Falling() === true && $ctx->btcLastPrice() !== null && $ctx->btcEma50() !== null && $ctx->btcLastPrice() < $ctx->btcEma50()) {
                            return false;
                        }
                    } elseif ($direction === Direction::Short) {
                        // Для SHORT: блокируем вход, если BTC растет более чем на maxPump % за 3 свечи
                        if ($btcRet3 !== null && $btcRet3 > $maxPump) {
                            return false;
                        }
                        // Или если краткосрочный тренд BTC растет и находится выше EMA50
                        if ($ctx->btcEma8Rising() === true && $ctx->btcLastPrice() !== null && $ctx->btcEma50() !== null && $ctx->btcLastPrice() > $ctx->btcEma50()) {
                            return false;
                        }
                    }
                }
            }
        }

        // Все защитные фильтры пройдены успешно
        return true;
    }
}
