#!/usr/bin/env bash
# MCP STDIO entrypoint for Cursor / Claude Desktop (Docker + Drush).
#
# Use this script as the MCP "command" — not bare "docker compose exec".
# Drush mcp:server expects JSON-RPC on stdin; a wrapper keeps stdio attached cleanly.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
exec docker compose -f "$ROOT/docker-compose.yml" exec -i drupal vendor/bin/drush mcp:server admin
