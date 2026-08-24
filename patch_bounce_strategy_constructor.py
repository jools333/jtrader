import re

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'r') as f:
    content = f.read()

# Replace constructor
content = content.replace(
    "public function __construct(private readonly ?StrategyLoggerInterface $logger = null)",
    "public function __construct(private readonly ?StrategyLoggerInterface $logger = null, private readonly float $minEntryScore = 90.0)"
)

# Replace 70.0 in evaluate() with $this->minEntryScore
content = content.replace(
    "return $eval->score >= 70.0 ? $eval->entrySignal : null;",
    "return $eval->score >= $this->minEntryScore ? $eval->entrySignal : null;"
)

# Replace 70.0 in diagnoseShort with $this->minEntryScore
content = content.replace(
    "entrySignal: $score >= 70.0 ? $plan : null,",
    "entrySignal: $score >= $this->minEntryScore ? $plan : null,"
)

with open('app/Trading/Strategies/Entry/BounceStrategy.php', 'w') as f:
    f.write(content)
