import re

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'r') as f:
    content = f.read()

# Update header comment
content = content.replace("9 критериям", "11 критериям")
content = content.replace("9. Коэффициент риск/прибыль", "9. Отсутствие агрессивного подхода (Momentum Exhaustion);\n * 10. Прокол уровня / Пин-бар на отбое;\n * 11. Коэффициент риск/прибыль")

long_adv = """        // 9. Отсутствие агрессивного подхода (Momentum Thrust Filter)
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

        // 11. Торговый план и R:R"""
content = content.replace("// 9. Торговый план и R:R", long_adv, 1)

short_adv = """        // 9. Отсутствие агрессивного подхода (Momentum Thrust Filter)
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

        // 11. Торговый план и R:R"""
content = content.replace("// 9. Торговый план и R:R", short_adv, 1)

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'w') as f:
    f.write(content)
