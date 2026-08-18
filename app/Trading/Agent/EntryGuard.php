<?php

declare(strict_types=1);

namespace App\Trading\Agent;

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
    public function allows(RuleContext $ctx): bool
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

        // Все защитные фильтры пройдены успешно
        return true;
    }
}
