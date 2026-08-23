import re

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'r') as f:
    content = f.read()

# 1. Update evaluate()
content = content.replace("return $eval->isFullSignal ? $eval->entrySignal : null;", "return $eval->score >= 70.0 ? $eval->entrySignal : null;")

# 2. Update diagnoseLong and diagnoseShort to pass $plan if score >= 70
content = content.replace("entrySignal: $isFull ? $plan : null,", "entrySignal: $score >= 70.0 ? $plan : null,")

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'w') as f:
    f.write(content)
