#!/usr/bin/env bash
# Copies the shared core into each panel plugin (core is not on Packagist; the plugin autoloads core-src/ itself).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for plugin in pelican/dylaris-gateway pterodactyl/dylaris-gateway; do
  dest="$ROOT/$plugin/core-src"
  rm -rf "$dest"
  cp -r "$ROOT/core/src" "$dest"
  rm -rf "$dest/Testing" # test doubles, not shipped
  echo "core copied to ${dest#"$ROOT"/}"
done
