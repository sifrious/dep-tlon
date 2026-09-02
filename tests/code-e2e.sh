#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer dump-autoload --no-interaction --quiet
state="$(mktemp)"
rm -f "$state"
trap 'rm -f "$state"' EXIT
output="$(bin/tlon-code repo-fixture inspection-1 "$state" tests/fixtures/Sample.php tests/fixtures/sample.ts)"
grep -q '"files": 2' <<<"$output"
grep -q '"symbols"' "$state"
grep -q '"references"' "$state"
php -r '$d=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); if (count($d["symbols"]) < 4 || count($d["references"]) < 2) exit(1);' "$state"
echo '4 code inspection CLI assertions passed'
