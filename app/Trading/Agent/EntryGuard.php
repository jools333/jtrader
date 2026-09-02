<?php

declare(strict_types=1);

namespace App\Trading\Agent;

use App\Trading\Enums\Direction;

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
     * Проверка, разрешено ли текущее состояние рынка для поиска точек входа.
     */
    public function allows(RuleContext $ctx, ?Direction $direction = null): bool
    {
        // 1. Порог максимального удаления цены от уровня (по умолчанию 60% от ATR)
        $maxAtrTravel = (float) ($this->config['max_atr_travel'] ?? 0.60);
        // Если цена уже ушла дальше 60% ATR от уровня — вход блокируется
        if ($ctx->atrTravelFraction() > $maxAtrTravel) {
            return false;
        }

        // 2. Порог минимальной ширины диапазона последних свечей (по умолчанию 30% от ATR)
        $minFlatWidth = (float) ($this->config['min_flat_width'] ?? 0.30);
        // Если последние 5 свечей зажаты в диапазоне уже 0.3 ATR (мёртвый флет) — вход блокируется
        if ($ctx->recentWidth(5) < $ctx->atr * $minFlatWidth) {
            return false;
        }

        // 3. Межрыночный фильтр BTC (BTC Market Regime / BTC Anchor)
        if ($direction !== null && $ctx->symbol !== 'BTC-USDT' && $ctx->hasBtcData()) {
            $btcFilterEnabled = (bool) ($this->config['btc_filter_enabled'] ?? true);
            if ($btcFilterEnabled) {
                $btcRet3 = $ctx->btcReturnPct(3);
                $maxDump = (float) ($this->config['btc_max_dump_percent'] ?? 0.20);
                $maxPump = (float) ($this->config['btc_max_pump_percent'] ?? 0.20);

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

        // Все защитные фильтры пройдены успешно
        return true;
    }
}
