import re
import glob

# 1. TradingAgentTest
with open('tests/Unit/TradingAgentTest.php', 'r') as f:
    content = f.read()

# Replace baseline for bounce short
content = content.replace("$candles = $this->baseline(50, 88, 97);", "$candles = $this->baseline(50, 120, 90);")
with open('tests/Unit/TradingAgentTest.php', 'w') as f:
    f.write(content)

# 2. PositionManagerTest
with open('tests/Feature/PositionManagerTest.php', 'r') as f:
    content = f.read()

content = content.replace("$start = 88.0;\n        $end = 97.0;", "$start = 120.0;\n        $end = 90.0;")
content = content.replace("$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2);", "$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2, 2000);")

with open('tests/Feature/PositionManagerTest.php', 'w') as f:
    f.write(content)

# 3. StrategyEvaluationTest
with open('tests/Feature/StrategyEvaluationTest.php', 'r') as f:
    content = f.read()

# StrategyEvaluationTest uses `bounceShortCandles()` method for testing agent
content = content.replace("$start = 88.0;\n        $end = 97.0;", "$start = 120.0;\n        $end = 90.0;")
content = content.replace("$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2);", "$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2, 2000);")
# Also it uses baseline for short:
content = content.replace("$candles = $this->baseline(50, 88, 97);", "$candles = $this->baseline(50, 120, 90);")

with open('tests/Feature/StrategyEvaluationTest.php', 'w') as f:
    f.write(content)

