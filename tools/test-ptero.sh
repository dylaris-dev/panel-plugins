#!/usr/bin/env bash
# Runs the Pterodactyl extension tests inside a Pterodactyl v2.0.0-beta.2 checkout, in Docker, against MariaDB
# (Pterodactyl's integration tests need MySQL or MariaDB). On the Windows dev machine Docker lives in WSL.
# The checkout lives in the docker volume dylaris-ptero-b2 (tools/ptero-checkout.sh); the database is fresh each run.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash would rewrite /app paths passed to wsl.exe
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=dylaris-panel-test:8.4
NET=dylaris-ptero-test
DB=dylaris-ptero-db

bash "$ROOT/tools/build-plugins.sh"

docker_() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e docker "$@"; else docker "$@"; fi
}
host_path() {
  if command -v wsl.exe >/dev/null 2>&1; then wsl.exe -e wslpath -a "$(cygpath -m "$1")" | tr -d '\r'; else echo "$1"; fi
}

# The checkout first, so the UI can be built against its SDK; installing the extension refuses a manifest whose build is missing.
bash "$ROOT/tools/ptero-checkout.sh"
bash "$ROOT/tools/build-ptero-ui.sh"
docker_ network create "$NET" >/dev/null 2>&1 || true
docker_ rm -f "$DB" >/dev/null 2>&1 || true
docker_ run -d --rm --name "$DB" --network "$NET" -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=testing mariadb:11 >/dev/null
trap 'docker_ rm -f "$DB" >/dev/null 2>&1 || true' EXIT

docker_ run --rm --network "$NET" -e DB_HOST="$DB" -v dylaris-ptero-b2:/app -v "$(host_path "$ROOT/pterodactyl/dylaris-gateway"):/extension:ro" -w /app "$IMAGE" sh -c "
  set -e
  rm -rf extensions/dylaris-gateway && mkdir -p extensions && cp -r /extension extensions/dylaris-gateway
  tries=0
  until php -r 'try { new PDO(\"mysql:host=$DB;dbname=testing\", \"root\", \"\", [PDO::ATTR_TIMEOUT => 2]); } catch (Throwable) { exit(1); }'; do
    tries=\$((tries + 1)); [ \$tries -lt 60 ] || { echo 'MariaDB did not come up.' >&2; exit 1; }; sleep 1
  done
  php -d memory_limit=1G vendor/bin/phpunit -c extensions/dylaris-gateway/tests/phpunit.xml \"\$@\"
" sh "$@"
