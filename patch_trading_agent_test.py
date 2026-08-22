import re

with open('tests/Unit/TradingAgentTest.php', 'r') as f:
    content = f.read()

# Update bounce short test to include volume
content = content.replace(
    "$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2);   // bearish impulse (last 3)",
    "$candles[] = $this->candle(103.5, 103.7, 98.0, 98.2, 2000);   // bearish impulse (last 3)"
)

# Update bounce long test to include volume
content = content.replace(
    "$candles[] = $this->candle(100.1, 104.0, 99.9, 103.8);",
    "$candles[] = $this->candle(100.1, 104.0, 99.9, 103.8, 2000);"
)

with open('tests/Unit/TradingAgentTest.php', 'w') as f:
    f.write(content)
