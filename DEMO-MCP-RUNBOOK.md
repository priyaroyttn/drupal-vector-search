# Hybrid search + MCP — presenter runbook (no errors)

Use **Demo track A** live unless you have already tested **track B** in Cursor the day before.

---

## What went wrong in the terminal (do not repeat)

| What you did | What Drush saw | Error |
|--------------|----------------|-------|
| Ran `drush mcp:server admin` and **pasted** `mcp.json` / `{ "mcpServers": ... }` | That text is **not** JSON-RPC | `-32600` — session required (never got `initialize`) |
| Ran `mcp:server` in Terminal **and** enabled the same server in **Cursor** | Two clients / wrong stdin | `-32700` syntax errors, config fragments in logs |
| Used `docker compose exec -i …` with literal `…` | Wrong service name | `service "…" is not running` |

**Rule:** `mcp:server` is **only** for MCP clients (Cursor, Inspector). Never paste JSON config into that terminal. For a shell demo, use **`tool:run`** (track A).

---

## Pre-flight (5 minutes before the session)

From **project root** `drupal-vector-search`:

```bash
docker compose ps                    # drupal + postgres = Up (healthy)
docker compose exec drupal curl -s http://host.docker.internal:11434/api/tags | head
chmod +x mcp-drush.sh drush.sh
./drush.sh status                      # Drupal bootstrap OK
```

Browser: http://localhost:8088 — login **admin** / **admin**  
Hybrid UI works: http://localhost:8088/hybrid-search?q=pgvector

Re-run setup before every major demo (fixes boost threshold + indexes):

```bash
docker compose exec drupal vendor/bin/drush php:script scripts/setup-vector-search.php
```

**Ollama must be running** with `nomic-embed-text` (`ollama pull nomic-embed-text`). If reindex logs `model not found`, fix the model name in `/admin/config/ai/providers` then re-run setup.

### Why “no results” on long conceptual queries?

If **Minimum relevance score** on index `content` → processor **Boost Database by AI Search** is **0.5**, vector hits scoring ~0.47 are dropped and keyword-less queries return **nothing**. The setup script forces **0.2** — re-run it if you see empty UI/`results:[]`.

```bash
./drush.sh php:script scripts/verify-hybrid-search.php -- "your query"
```

Check `min_score=` in output and `Vector index: N results`.

---

## Track A — Live demo (recommended): browser + terminal, **no MCP client**

Shows the same tool MCP uses (`hybrid_content_search` on index `content`).

### 1) Humans — browser

1. Open **http://localhost:8088/hybrid-search**
2. **Scenario A (exact):** **`pgvector`** → top hits include *Hybrid vector search in Drupal*; or **`nomic-embed-text`** → *Embeddings with Ollama*.
3. **Scenario B (conceptual):** use the **short** query (verified on this stack):

   **`how do local AI models convert text into numbers`**

   Expect *Embeddings with Ollama* in the top results (hybrid boost; exact rank depends on other indexed content).

   **Do not use** the longer phrase *“how does a local AI model turn sentences into numeric vectors for search”* on stage unless you re-tested it after setup — it often ranked below other nodes and used to return **zero** when `minimum_relevance_score` was 0.5.

### 2) “Agent payload” — terminal (same query)

```bash
cd /path/to/drupal-vector-search

./drush.sh tool:run hybrid_content_search --uid=1 \
  --input='{"query":"how do local AI models convert text into numbers","limit":5}' \
  --json
```

**Say:** *“MCP does not run a second search index—it calls this Tool API plugin; JSON is what agents ground answers on.”*

Compare **`title`** and **`url`** from JSON with the browser #1 result.

### 3) Governance — admin UI (optional 30s)

- Tools: http://localhost:8088/admin/config/services/mcp-server/tools → **hybrid_content_search** enabled  
- Settings: http://localhost:8088/admin/config/services/mcp-server/settings → server instructions  

### 4) Code pointer (optional)

`drupal/web/modules/custom/hybrid_search/src/Plugin/tool/Tool/HybridContentSearchTool.php` → `Index::load('content')`.

**Do not** start `drush mcp:server` in this track.

---

## Track B — Optional: Cursor MCP (test before the talk)

### Setup (once)

1. **Quit** any terminal running `mcp:server`.
2. Cursor → **Settings → MCP** → edit config:

```json
{
  "mcpServers": {
    "drupal-hybrid-search": {
      "command": "/Users/priyaroy/ttncode/drupal-vector-search/mcp-drush.sh"
    }
  }
}
```

Use the **real absolute path** (see `mcp-cursor.example.json`).

3. `chmod +x mcp-drush.sh`
4. Restart Cursor; enable **drupal-hybrid-search** (green).

### During the talk

1. Chat: *“Search our Drupal knowledge base for how local embeddings work.”*
2. Agent should call **`tool_api__hybrid_content_search`** (bridged MCP name).
3. Side-by-side with `/hybrid-search` as in track A.

**Do not** run `docker compose exec … mcp:server` in Terminal while Cursor MCP is on.

### Optional local test (not on stage)

```bash
npx @modelcontextprotocol/inspector /path/to/drupal-vector-search/mcp-drush.sh
```

Use Inspector’s UI to call **initialize**, then list tools, then call the hybrid search tool—never paste `mcp.json` into Drush.

---

## Track C — HTTP `/mcp` (skip in workshops)

Needs authenticated session + `access mcp server`. STDIO or `tool:run` is simpler.

---

## One-line narrative for Slide 11 / 13

*“Editors use `/hybrid-search`; agents use the same `content` index via `hybrid_content_search`—provisioned in `setup-vector-search.php` and exposed through MCP Tool Bridge.”*
