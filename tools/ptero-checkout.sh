#!/usr/bin/env bash
# Prepares the Pterodactyl v2.0.0-beta.2 checkout (with vendor/) in the docker volume dylaris-ptero-b2, which the extension
# tests, the UI build and the pack step run in. Reused between runs; safe to run again. On the Windows dev machine Docker
# lives in WSL.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=dylaris-panel-test:8.4
PTERO_REF=v2.0.0-beta.2

docker_() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e docker "$@"; else docker "$@"; fi
}
host_path() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e wslpath -a "$(cygpath -m "$1")" | tr -d '\r'; else echo "$1"; fi
}

docker_ build -q -t "$IMAGE" -f "$(host_path "$ROOT/tools/pelican-test.Dockerfile")" "$(host_path "$ROOT/tools")" >/dev/null
docker_ run --rm -v dylaris-ptero-b2:/app -w /app "$IMAGE" sh -c "
  set -e
  [ -f artisan ] || git clone -q --depth 1 --branch $PTERO_REF https://github.com/pterodactyl/panel.git . 2>/dev/null
  # What the release build does: a checkout reports itself as 2.0.0-dev, which no ^2.0.0-beta.2 extension accepts.
  sed -i \"s/'canary'/'${PTERO_REF#v}'/\" config/app.php
  [ -d vendor ] || composer install --no-interaction --no-progress --quiet
  [ -f .env ] || cp .env.ci .env
"
