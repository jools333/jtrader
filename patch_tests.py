import re

with open('tests/Feature/StrategyEvaluationTest.php', 'r') as f:
    content = f.read()

content = content.replace("totalCount: 9,", "totalCount: 11,")
content = content.replace("'total_count' => 9,", "'total_count' => 11,")

# For complete setups, passed_count should also be 11
content = content.replace("'passed_count' => 9,", "'passed_count' => 11,")

with open('tests/Feature/StrategyEvaluationTest.php', 'w') as f:
    f.write(content)
