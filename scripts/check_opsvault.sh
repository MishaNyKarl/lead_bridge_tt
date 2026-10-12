#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 central/build.py
suite=$(mktemp -d)
trap 'rm -rf -- "$suite"' EXIT
cp central/dist/action.php central/opsvault_test.php central/opsvault_http_test.py "$suite/"
php "$suite/opsvault_test.php"
python3 "$suite/opsvault_http_test.py"
