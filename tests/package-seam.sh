#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../demo-app"
composer update --no-interaction --no-progress --no-scripts >/dev/null
test -f vendor/autoload.php
test -e vendor/sifrious/tlon/composer.json
output="$(REQUEST_URI=/ php public/index.php)"
grep -qF 'tlon package ready' <<<"$output"
echo '4 package seam assertions passed'
