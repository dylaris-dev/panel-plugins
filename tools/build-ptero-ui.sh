#!/usr/bin/env bash
# Builds the Pterodactyl extension's UI (ui/ -> dist/) with the SDK of the panel checkout in the docker volume
# dylaris-ptero-b2, which tools/ptero-checkout.sh creates. The SDK is not on npm yet, so the build runs inside the checkout,
# where @pterodactyl/sdk is linked to packages/sdk and React, Vite and TypeScript come from the panel's node_modules.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NODE_IMAGE=node:22-bookworm-slim

docker_() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e docker "$@"; else docker "$@"; fi
}
host_path() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e wslpath -a "$(cygpath -m "$1")" | tr -d '\r'; else echo "$1"; fi
}

docker_ run --rm -v dylaris-ptero-b2:/app -v "$(host_path "$ROOT/pterodactyl/dylaris-gateway"):/extension" -w /app "$NODE_IMAGE" sh -c '
  set -e
  [ -f package.json ] || { echo "No panel checkout in the volume yet: run tools/ptero-checkout.sh first." >&2; exit 1; }
  [ -x node_modules/.bin/vite ] || npm ci --no-audit --no-fund --loglevel=error
  mkdir -p node_modules/@pterodactyl && ln -sfn ../../packages/sdk node_modules/@pterodactyl/sdk
  build=/app/.dylaris-gateway-ui # outside extensions/, so the panel never discovers it
  rm -rf "$build" && mkdir -p "$build"
  cp -r /extension/ui /extension/package.json /extension/tsconfig.json /extension/vite.config.mjs "$build"/
  cd "$build"
  /app/node_modules/.bin/tsc -p tsconfig.json
  /app/node_modules/.bin/vite build --logLevel warn
  rm -rf /extension/dist && cp -r dist /extension/dist
  echo "ui built: $(ls dist | tr "\n" " ")"
'
