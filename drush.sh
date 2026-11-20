#!/usr/bin/env bash
# Run Drush inside the Drupal container (DB host "postgres" works there).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
exec docker compose -f "$ROOT/docker-compose.yml" exec -w /opt/drupal drupal vendor/bin/drush "$@"
