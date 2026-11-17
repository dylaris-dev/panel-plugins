#!/usr/bin/env bash
# Runs the Pelican plugin tests inside a Pelican v1.0.0-rc.3 checkout, in Docker. On the Windows dev machine Docker lives in WSL.
# The checkout and its vendor/ live in the docker volume dylaris-pelican-rc3 and are reused between runs.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=dylaris-panel-test:8.4
PELICAN_REF=v1.0.0-rc.3

bash "$ROOT/tools/build-plugins.sh"

docker_() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e docker "$@"; else docker "$@"; fi
}
host_path() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e wslpath -a "$(cygpath -m "$1")" | tr -d '\r'; else echo "$1"; fi
}

docker_ build -q -t "$IMAGE" -f "$(host_path "$ROOT/tools/pelican-test.Dockerfile")" "$(host_path "$ROOT/tools")" >/dev/null
docker_ run --rm -v dylaris-pelican-rc3:/app -v "$(host_path "$ROOT/pelican/dylaris-gateway"):/plugin:ro" -w /app "$IMAGE" sh -c "
  set -e
  [ -f artisan ] || git clone -q --depth 1 --branch $PELICAN_REF https://github.com/pelican-dev/panel.git . 2>/dev/null
  [ -d vendor ] || composer install --no-interaction --no-progress --quiet
  rm -rf plugins/dylaris-gateway && cp -r /plugin plugins/dylaris-gateway
  touch database/testing.sqlite
  php -d memory_limit=1G vendor/bin/phpunit -c plugins/dylaris-gateway/tests/phpunit.xml \"\$@\"
" sh "$@"
