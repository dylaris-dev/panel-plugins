#!/usr/bin/env bash
# Runs the core test suite in Docker. On the Windows dev machine Docker lives in WSL.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Install with composer (platform pinned to 8.3.0 in composer.json), run phpunit on the PHP floor.
run() { # run <image> <command>
  if command -v wsl.exe >/dev/null 2>&1; then
    local root; root="$(wsl.exe -e wslpath -a "$(cygpath -m "$ROOT")" | tr -d '\r')"
    wsl.exe -e docker run --rm -v "$root:/app" -w /app/core "$1" sh -c "$2"
  else
    docker run --rm -v "$ROOT:/app" -w /app/core "$1" sh -c "$2"
  fi
}
run composer:2 'composer install --no-interaction --no-progress --quiet'
run php:8.3-cli 'php -v | head -1 && php vendor/bin/phpunit'
