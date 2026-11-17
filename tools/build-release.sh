#!/usr/bin/env bash
# Builds both release packages into dist/ and writes dist/SHA256SUMS. Used by CI and the release workflow.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PY="$(command -v python3 || command -v python)"

rm -f "$ROOT"/dist/dylaris-gateway-*.zip "$ROOT"/dist/dylaris-gateway-*.pteroext "$ROOT/dist/SHA256SUMS" 2>/dev/null || true
bash "$ROOT/tools/build-plugins.sh"
"$PY" "$ROOT/tools/pack-pelican.py"
bash "$ROOT/tools/pack-ptero.sh"
cd "$ROOT/dist"
sha256sum dylaris-gateway-*.zip dylaris-gateway-*.pteroext > SHA256SUMS
cat SHA256SUMS
