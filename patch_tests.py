import re

with open('tests/Feature/StrategyEvaluationTest.php', 'r') as f:
    content = f.read()

content = content.replace("totalCount: 7,", "totalCount: 9,")
content = content.replace("'total_count' => 7,", "'total_count' => 9,")

# For complete setups, passed_count should also be 9
content = content.replace("'passed_count' => 7,", "'passed_count' => 9,")

with open('tests/Feature/StrategyEvaluationTest.php', 'w') as f:
    f.write(content)
