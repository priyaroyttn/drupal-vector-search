# Drupal 11 hybrid vector search — teammate setup (macOS + Docker)

## What to send

Share the whole `drupal-vector-search` folder **except** (rebuilt locally):

| Exclude | Why |
|---------|-----|
| `drupal/vendor/` | `composer install` |
| `drupal/web/sites/default/files/` | cache/uploads (optional: share a DB dump instead) |
| `.env`, `.credentials` | secrets |

**Include:** `docker-compose.yml`, `Dockerfile`, `docker/`, `drush.sh`, `drupal/composer.json` + `composer.lock`, `drupal/web/` (core/contrib/custom), `drupal/scripts/`, and **`drupal/web/sites/default/settings.php`** (gitignored on your machine — copy it into the archive or use `settings.php.example` below).

---

## Prerequisites (their Mac)

- Docker Desktop (running)
- [Ollama](https://ollama.com/download) installed and running (menu bar icon)

```bash
ollama pull nomic-embed-text
ollama pull llama3.2          # optional; used for chunking defaults in setup script
curl -s http://127.0.0.1:11434/api/tags | head   # must return JSON
```

---

## Setup (from project root)

```bash
cd drupal-vector-search
```

**1. PHP dependencies**

```bash
docker run --rm -v "$(pwd)/drupal:/opt/drupal" -w /opt/drupal composer:2 install
```

**2. Database settings**

If you did not receive `settings.php`, copy `drupal/web/sites/default/default.settings.php` → `settings.php` and append the block from [settings.php.example](./drupal/web/sites/default/settings.php.example) (or the sender’s `settings.php`).

**2.1 Backup existing DB**

restore (instead of site:install + setup script)
  docker compose up -d postgres
# wait until postgres is healthy

gunzip -c backups/drupal-vector-search-YYYYMMDD.sql.gz | \
  docker compose exec -T postgres psql -U drupal -d drupal

**3. Start stack**

```bash
docker compose up -d --build
```

Wait until healthy: Postgres `5433`, Drupal http://localhost:8088

**4. Install Drupal (fresh DB only)**

```bash
chmod +x drush.sh
./drush.sh site:install minimal \
  --db-url=pgsql://drupal:drupal_pass@postgres:5432/drupal \
  --site-name="Vector Search Demo" \
  --account-name=admin --account-pass=admin -y
```

**5. Provision hybrid search, demo content, MCP**

```bash
docker compose exec drupal vendor/bin/drush php:script scripts/setup-vector-search.php
```

Takes a few minutes (Ollama embeddings for seed nodes).

Optional OpenAI (moderation / automators): `export OPENAI_API_KEY=...` before step 5.

---

## Verify

| Check | URL / command |
|-------|----------------|
| Hybrid search UI | http://localhost:8088/hybrid-search |
| Ollama from container | `docker compose exec drupal curl -s http://host.docker.internal:11434/api/tags` |
| Smoke test | `./drush.sh php:script scripts/verify-hybrid-search.php` |

Login: `admin` / `admin`

---

## MCP / agent demo

**Presenter steps (no STDERR noise):** see **[DEMO-MCP-RUNBOOK.md](./DEMO-MCP-RUNBOOK.md)** — use **Track A** (`tool:run` + browser) live unless Cursor MCP is pre-tested.

## MCP demo (agents) — what works in Docker

### Why `docker` as the MCP `command` breaks

`drush mcp:server` speaks **JSON-RPC on stdin**. Putting `"command": "docker"` with many `args` in Cursor often forwards the wrong bytes (you see `-32700 Syntax error` and fragments like `"compose"`, `"cwd"` in logs). Use a **single wrapper script** instead.

### Option A — Cursor / Claude (STDIO, recommended)

1. From the project root:
   ```bash
   chmod +x mcp-drush.sh
   ```
2. In Cursor: **Settings → MCP → Edit config** (or merge into `~/.cursor/mcp.json`):
   ```json
   {
     "mcpServers": {
       "drupal-hybrid-search": {
         "command": "/absolute/path/to/drupal-vector-search/mcp-drush.sh"
       }
     }
   }
   ```
   Copy `mcp-cursor.example.json` and fix the path.
3. Restart Cursor. Enable the server. Tool on the wire: **`tool_api__hybrid_content_search`** (bridged name).
4. Do **not** leave a manual `docker compose exec … drush mcp:server` running in a terminal while Cursor uses the same server (one STDIO process only).

**Inspector (optional):**
```bash
npx @modelcontextprotocol/inspector /path/to/drupal-vector-search/mcp-drush.sh
```

### Option B — Live session without any MCP client (terminal “agent payload”)

Same tool the MCP bridge calls; good for slides:

```bash
./drush.sh tool:run hybrid_content_search --uid=1 \
  --input='{"query":"how local embeddings work","limit":5}' --json
```

Compare with http://localhost:8088/hybrid-search using the same query.

### Option C — Drupal admin UI (no agent)

| What | URL |
|------|-----|
| MCP tool enabled | http://localhost:8088/admin/config/services/mcp-server/tools |
| Server instructions | http://localhost:8088/admin/config/services/mcp-server/settings |
| Tool code | `web/modules/custom/hybrid_search/src/Plugin/tool/Tool/HybridContentSearchTool.php` |

HTTP transport exists at `POST http://localhost:8088/mcp` (needs a logged-in user with **access mcp server**; STDIO-as-`admin` is easier for workshops).

---

## Troubleshooting

- **Vector search dead / no embeddings:** Ollama not running or model missing → `ollama pull nomic-embed-text`
- **Drupal can’t reach Ollama:** In UI `/admin/config/ai/providers/ollama` host must be `http://host.docker.internal`, port `11434` (setup script sets this by default)
- **Port conflict:** Change `8088:80` or `5433:5432` in `docker-compose.yml`
- **Exact copy of sender’s DB:** ask for a `pg_dump` restore into fresh `postgres` volume instead of steps 4–5

---

## Ports & credentials

| Service | Host | User / pass |
|---------|------|-------------|
| Drupal | http://localhost:8088 | admin / admin (after install) |
| PostgreSQL | localhost:5433 | drupal / drupal_pass, DB `drupal` |
| Ollama | http://127.0.0.1:11434 | (none) |
