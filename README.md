# Drupal 11 hybrid vector search

Dockerized Drupal 11 demo: **Search API** keyword + **AI Search** (Ollama embeddings, **pgvector**), custom **`hybrid_search`** module, and MCP **`hybrid_content_search`** tool.

## Quick start

Teammates: follow **[SETUP-TEAMMATE.md](./SETUP-TEAMMATE.md)**.

```bash
docker run --rm -v "$(pwd)/drupal:/opt/drupal" -w /opt/drupal composer:2 install
docker compose up -d --build
./drush.sh site:install minimal --db-url=pgsql://drupal:drupal_pass@postgres:5432/drupal \
  --account-name=admin --account-pass=admin -y
docker compose exec drupal vendor/bin/drush php:script scripts/setup-vector-search.php
```

Hybrid search UI: http://localhost:8088/hybrid-search

## What is in git

| Tracked | Not tracked (see `.gitignore`) |
|---------|--------------------------------|
| `drupal/composer.json` + `composer.lock` | `drupal/vendor/`, core, contrib |
| `drupal/web/modules/custom/hybrid_search/` | `sites/default/files/`, live `settings.php` |
| `drupal/scripts/`, scaffold `drupal/web/*` | `.env`, `.credentials`, `backups/` |
| Docker, MCP examples, docs | |

After clone: `composer install` inside `drupal/`, copy or merge `settings.php.example` → `settings.php`.

## Docs

- [SETUP-TEAMMATE.md](./SETUP-TEAMMATE.md) — full local setup
- [DEMO-MCP-RUNBOOK.md](./DEMO-MCP-RUNBOOK.md) — MCP / agent demo