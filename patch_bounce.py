import re

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'r') as f:
    content = f.read()

# Update header comment
content = content.replace("7 критериям", "9 критериям")
content = content.replace("7. Коэффициент риск/прибыль", "7. Фильтр по тренду (EMA 50);\n * 8. Подтверждение объемом на триггерной свече;\n * 9. Коэффициент риск/прибыль")

# Update diagnoseLong
long_trend = """        // 7. Фильтр по тренду
        $ema50 = $ctx->ema50At($ctx->i);
        $passedTrend = $ema50 > 0.0 ? ($last->close > $ema50) : true;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Совпадение с глобальным трендом (EMA 50)',
            passed: $passedTrend,
            expected: 'Цена > EMA 50',
            actual: sprintf('Close = %.4f, EMA 50 = %.4f', $last->close, $ema50),
            actualValue: $last->close,
            thresholdValue: $ema50,
        );
        if (! $passedTrend) {
            $missing[] = 'Вход против тренда (Цена <= EMA 50)';
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

        // 9. Торговый план и R:R"""
content = content.replace("// 7. Торговый план и R:R", long_trend, 1)

# Update diagnoseShort
short_trend = """        // 7. Фильтр по тренду
        $ema50 = $ctx->ema50At($ctx->i);
        $passedTrend = $ema50 > 0.0 ? ($last->close < $ema50) : true;
        $criteria['trend_alignment'] = new CriterionResult(
            key: 'trend_alignment',
            name: 'Совпадение с глобальным трендом (EMA 50)',
            passed: $passedTrend,
            expected: 'Цена < EMA 50',
            actual: sprintf('Close = %.4f, EMA 50 = %.4f', $last->close, $ema50),
            actualValue: $last->close,
            thresholdValue: $ema50,
        );
        if (! $passedTrend) {
            $missing[] = 'Вход против тренда (Цена >= EMA 50)';
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

        // 9. Торговый план и R:R"""
content = content.replace("// 7. Торговый план и R:R", short_trend, 1)

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'w') as f:
    f.write(content)
