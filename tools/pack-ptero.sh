#!/usr/bin/env bash
# Packs the Pterodactyl extension into dist/dylaris-gateway-<version>.pteroext with the panel's own p:extension:pack, in the
# checkout volume dylaris-ptero-b2 (tools/ptero-checkout.sh). Builds core-src and the UI first.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=dylaris-panel-test:8.4

bash "$ROOT/tools/build-plugins.sh"
bash "$ROOT/tools/ptero-checkout.sh"
bash "$ROOT/tools/build-ptero-ui.sh"
mkdir -p "$ROOT/dist"

docker_() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e docker "$@"; else docker "$@"; fi
}
host_path() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e wslpath -a "$(cygpath -m "$1")" | tr -d '\r'; else echo "$1"; fi
}

# The old copy is moved aside, not deleted, so a stale file can never end up in the archive.
docker_ run --rm -v dylaris-ptero-b2:/app -v "$(host_path "$ROOT/pterodactyl/dylaris-gateway"):/extension:ro" -v "$(host_path "$ROOT/dist"):/out" -w /app "$IMAGE" sh -c '
  set -e
  mkdir -p extensions
  [ ! -d extensions/dylaris-gateway ] || mv extensions/dylaris-gateway "/tmp/old-ext-$$"
  cp -r /extension extensions/dylaris-gateway
  version=$(php -r "echo json_decode(file_get_contents(\"extensions/dylaris-gateway/extension.json\"), true)[\"version\"];")
  php artisan p:extension:pack extensions/dylaris-gateway --output="/out/dylaris-gateway-$version.pteroext" --force
'
