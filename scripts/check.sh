#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 central/build.py
for file in central/*.php central/dist/action.php; do php -l "$file"; done
node central/sheets_test.cjs
# Run all integration tests against the artifact that will actually be deployed.
suite=$(mktemp -d)
trap 'rm -rf -- "$suite"' EXIT
cp central/dist/action.php "$suite/action.php"
cp central/*test*.php central/*test*.py "$suite/"
php "$suite/tests.php"
php "$suite/mapping_test.php"
php "$suite/postback_test.php"
php "$suite/ui_test.php"
python3 "$suite/http_test.py"
python3 "$suite/accounts_http_test.py"
