import re

with open('app/Trading/Agent/TradingAgent.php', 'r') as f:
    content = f.read()

content = content.replace(
    "new BounceStrategy($logger),",
    "new BounceStrategy($logger, (float) ($this->config['agent']['min_entry_score'] ?? 90.0)),"
)

with open('app/Trading/Agent/TradingAgent.php', 'w') as f:
    f.write(content)
