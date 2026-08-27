# Arra Oracle V3 — Architecture Overview

**Documentation Date:** 2026-08-27  
**Source:** `D:/Dev/BPM/ψ/learn/Soul-Brews-Studio/oracle-v2/origin`  
**Project Name:** Arra Oracle V3  
**Status:** Docker-first MCP Memory + Search Layer  
**License:** BUSL-1.1

---

## Executive Summary

**Arra Oracle V3** is the Oracle family's local memory and search layer. It stores notes in SQLite, searches them with FTS/vector-capable APIs, and exposes the same memory through HTTP, MCP, the `arra` CLI, plugins, and the Studio UI.

The design goal is **one memory core with thin adapters**: CLI, HTTP, MCP, plugins, canvas, and web/desktop surfaces reuse shared contracts instead of duplicating business logic.

### Key Capabilities
- Docker-first HTTP server on port 47778 with SQLite data persistence
- `arra mine` ingestion for folders of `.md`, `.mdx`, `.txt` notes
- Full-text search (FTS5) + optional semantic vector search (bge-m3, nomic, qwen3)
- MCP server for Claude and agent integration
- React/Vite web UI (Studio) and Simple Mode dashboard
- Plugin architecture with unified manifests for CLI/API/MCP/sidecars
- Federation and peer-to-peer knowledge networks

---

## High-Level Architecture

```
Notes / Agents / Browsers / MCP Clients
        │
        ├── Docker HTTP: ghcr.io/...:http on :47778
        ├── Docker stdio MCP: ghcr.io/...:stdio
        ├── CLI: arra mine/search/learn/export
        └── Studio UI and Simple Mode
                  │
        Elysia routes + MCP tools + plugin registry
                  │
        SQLite + FTS + optional vector stores + local vault files
```

**Design Principle:** Live system state visible before details; dense but readable operational cards; prefer lightweight route components.

---

## Top-Level Directory Structure

| Directory | Purpose |
|-----------|---------|
| `src/` | **Core application code** — Elysia routes, database layer, indexing, vector search, MCP tools |
| `frontend/` | **React/Vite web UI** — Studio dashboard, Simple Mode, responsive UI for menu/plugins/vector/MCP/settings discovery |
| `cli/` | **CLI tool** — `arra` command-line interface for mining, searching, learning, and exporting notes |
| `api/` | **API proxy** — Thin Vercel Studio proxy layer |
| `packages/` | **Shared modules** — Canvas plugins and other reusable packages |
| `bin/` | **Entry points** — `arra.ts` and `mcp.ts` executables |
| `docs/` | **Documentation** — API reference, deployment guides, CLI guides, plugin quickstart, and feature documentation |
| `catalog/` | **Plugin catalog** — `arra-oracle.yaml` plugin manifest |
| `maw-plugin/` | **maw-js integration** — Integration layer for maw (Oracle federation) |
| `workers/` | **Cloudflare Workers** — Edge-deployed MCP server and Studio proxy for Workers/Vercel |
| `services/` | **External services** — Sidecar services for specialized functions |
| `sidecar/` | **Sidecar utilities** — Ancillary service helpers |
| `scripts/` | **Utility scripts** — Database setup, seed data, exports, hooks |
| `tests/` | **Test suites** — HTTP contract tests, E2E, UI audits, build/scope verification |
| `benchmarks/` | **Performance benchmarks** — Embedding drift, throughput, latency testing |
| `specs/` | **Specifications** — OpenAPI, MCP schemas, plugin specs |
| `tools/` | **Build/utility tools** — Helper scripts and CLI tools |
| `e2e/` | **End-to-end scenarios** — Real-world workflow testing |
| `.claude/` | **Claude Code integration** — AGENTS.md, CLAUDE.md, hooks, skills, local MCP definitions |
| `.github/` | **CI/CD workflows** — GitHub Actions for testing, building, releasing (calver) |

---

## Core Source Structure (`src/`)

The core application is organized into logical subsystems:

### Route Clusters (21 total — HTTP API endpoints)

Composed as Elysia sub-apps in `src/server.ts`:

```
auth, dashboard, feed, files, forum, health, indexer, indexer-daemon,
knowledge, menu, oraclenet, peer, plugins, schedule, search, sessions,
settings, supersede, traces, vault, vector
```

**Reference Module:** `src/routes/health/` — cleanest example of a new cluster.

### Core Subsystems

| Subsystem | Location | Purpose |
|-----------|----------|---------|
| **Database** | `src/db/` | Drizzle ORM schema, migrations, SQLite backend |
| **Vector Search** | `src/vector/` | Embedding models, vector store adapters (lancedb, qdrant, cloudflare-vectorize), FTS5 indexing |
| **Indexing Pipeline** | `src/indexer/` | Batch ingestion, document parsing, vector generation, index updates |
| **MCP Tools** | `src/tools/` | Model Context Protocol server; `muninn_search` and related tool definitions |
| **Federation** | `src/federation/` | Peer discovery, oracle network routing, document federation |
| **Knowledge Graph** | `src/knowledge/`, `src/learn/`, `src/vault/` | Document storage, learning management, vault synchronization |
| **Process Manager** | `src/process-manager/` | Background job scheduling, worker lifecycle |
| **Plugin System** | `src/plugins/` | Plugin manifest parsing, hook lifecycle, plugin registry |
| **Middleware** | `src/middleware/` | SPA content negotiation, auth guards, CORS, error handling |
| **CLI** | `cli/src/` | Command-line interface (`arra mine`, `arra search`, `arra learn`) |
| **Configuration** | `src/config.ts` | Environment variable parsing, runtime settings, vector backend selection |
| **Utilities** | `src/util/`, `src/lib/` | Shared helpers, type definitions, constants |

### Key Files at `src/` Root

- `index.ts` — Main entry point; CLI runner
- `server.ts` — Elysia app initialization, route composition, middleware setup
- `vector-server.ts` — Optional vector-only server (read-only or proxy mode)
- `config.ts` — Configuration loader
- `const.ts` — Constants and defaults
- `ensure-server.ts` — Server health check and startup helper
- `chroma-mcp.ts` — Chroma vector service integration (optional)

---

## Frontend Architecture (`frontend/`)

**Framework:** React + React Router + Vite + Tailwind CSS

### Structure

```
frontend/
├── src/
│   ├── components/        # React components (VectorSearchWidget, McpToolBrowser, etc.)
│   ├── routes/            # Route pages (/menu, /plugins, /vector, /mcp, /settings)
│   ├── styles.css         # Tailwind utilities, design tokens, theme variables
│   ├── App.tsx            # Router and app shell
│   └── index.html         # Entry point
├── src-tauri/             # Tauri desktop app shell (optional)
├── public/                # Static assets (favicons, etc.)
├── index.html             # HTML skeleton
├── vite.config.ts         # Vite build config (proxies /api/* to :47778)
├── tsconfig.json          # TypeScript configuration
├── package.json           # Frontend dependencies
└── PRODUCT.md             # Product requirements and design goals
```

### Key Features

- **Responsive design:** Mobile single-column, desktop two-column layout
- **Route-scoped content:** Shell carries chrome; each route owns its h1 and content
- **Live API data:** Parallel fetches to `/api/menu`, `/api/plugins`, `/api/vector/status`, `/api/mcp/tools`
- **Offline capability:** Service worker caches static shell; API data is live-only
- **Design tokens:** Dark theme with teal/cyan Oracle accent, purple secondary
- **Accessibility:** Semantic HTML, keyboard-visible controls, high contrast, screen-reader friendly

### Entry Points for Users

- `/simple` — Simple Mode: health, save, search actions, and links to advanced surfaces
- `/menu` — Menu items and navigation discovery
- `/plugins` — Registered plugins and their capabilities
- `/vector` — Vector search UI and status
- `/mcp` — MCP tools browser
- `/settings` — Frontend runtime configuration

---

## CLI Architecture (`cli/` and `bin/`)

### Entry Points

```
bin/
├── arra.ts        # Main CLI executable (npm binary: `arra` or `arra-oracle`)
└── mcp.ts         # MCP server entry point

cli/src/
├── cli.ts         # CLI routing
└── commands/      # Command implementations (mine, search, learn, export, etc.)
```

### Commands

- `arra mine <path>` — Ingest notes from a folder
- `arra search <query>` — Full-text or vector search
- `arra learn <command>` — Knowledge base management
- `arra export` — Export documents and indexes

### Runtime

- **Package Manager:** Bun ≥ 1.2
- **Executor:** `bun bin/arra.ts` or `bun cli/src/cli.ts`
- **Docker:** CLI bundled inside container at `dist-cli/index.js`

---

## Database & Persistence

### Storage Backend

**Default:** SQLite + FTS5 (local development)

**Deployment Options:**
- **Local:** Better SQLite3 with `.db` file
- **Cloudflare Workers:** D1 database + Vectorize for semantic search
- **Cloud:** Qdrant, LanceDB, or other vector backends via adapters

### Schema Management

- **ORM:** Drizzle (`src/db/schema.ts`)
- **Migrations:** Drizzle Kit (`bun db:push`, `bun db:generate`)
- **File size constraint:** ≤ 250 lines per file (enforced by test: `tests/build/`)

### Key Tables

- `documents` — Indexed notes with FTS5 vector, metadata
- `embeddings` — Vector representations (optional, backend-dependent)
- `vault_items` — Knowledge graph nodes
- `plugins` — Plugin registry and manifest cache
- `jobs` — Background job queue (indexer, federation sync)
- `sessions` — User/agent session tracking
- `traces` — Audit logs and federation events

---

## Plugin & Hook System

### Plugin Manifest (`catalog/arra-oracle.yaml`)

Unified manifest for CLI commands, API routes, MCP tools, sidecars, exports, and lifecycle hooks.

### Plugin Lifecycle

1. **Discovery** — `/api/plugins` lists registered plugins
2. **Manifest parsing** — Hook metadata extracted
3. **Hook execution** — Lifecycle hooks called at startup, search, learn, etc.
4. **Tool registration** — CLI commands, MCP tools, API routes dynamically added

### Hook Points

- `on:startup` — Plugin initialization
- `on:search` — Post-search enrichment
- `on:learn` — Knowledge base updates
- `on:shutdown` — Cleanup

---

## Vector Search & Embedding

### Supported Models

- **Default:** `bge-m3` (BGE v3, 1024 dims)
- **Alternatives:** `nomic`, `qwen3`, Ollama, Cloudflare Workers AI
- **Customization:** `ORACLE_EMBEDDING_MODEL` env var

### Backends

| Backend | Location | Use Case |
|---------|----------|----------|
| **SQLite + sqlite-vec** | `src/vector/sqlite-adapter.ts` | Local, lightweight (default) |
| **LanceDB** | `src/vector/lancedb-adapter.ts` | Hybrid SQL + vector, local or cloud |
| **Qdrant** | `src/vector/qdrant-adapter.ts` | Managed vector service |
| **Cloudflare Vectorize** | `src/vector/cloudflare-adapter.ts` | Workers edge deployment |

### Search Modes

- **FTS5** — Full-text keyword search
- **Vector** — Semantic similarity (embeddings)
- **Hybrid** — FTS5 + vector reranking

---

## MCP (Model Context Protocol) Integration

### Server Entry Point

`bin/mcp.ts` or `workers/mcp/` (Cloudflare Worker)

### Tools Exposed

- `muninn_search` — Search across memory
- `oracle:read` — Fetch document by ID
- `oracle:learn` — Add/update documents
- `oracle:recap` — Summarize topics
- `oracle:profile` — User/agent memory profile
- `oracle:research` — Research tool
- `oracle:trace` — Follow federation traces
- `oracle:tool-catalog` — Tool discovery

### Protocol

- **Stdio mode** — Direct process communication (ideal for desktop Claude, agents)
- **HTTP mode** — REST-like wrapper for remote MCP clients
- **Docker:** `ghcr.io/.../arra-oracle-v3:stdio` for MCP; `ghcr.io/.../arra-oracle-v3:http` for HTTP

---

## Deployment Modes

### Local Development

```bash
# Terminal 1: Backend
bun run server

# Terminal 2: Frontend (Vite HMR)
cd frontend && bun run dev

# Browser: http://localhost:3000 (proxies /api/* to :47778)
```

### Docker (Recommended for Production)

```bash
docker run -d --name arra-oracle \
  -p 47778:47778 \
  -v arra-oracle-data:/data \
  -v ~/notes:~/notes:ro \
  ghcr.io/soul-brews-studio/arra-oracle-v3:http

arra mine ~/notes
open http://localhost:47778/simple
```

### Cloudflare Workers

- **Studio UI:** `workers/studio/` — Vercel proxy for web UI
- **MCP Server:** `workers/mcp/` — Stdio MCP on Workers
- **Federation Worker:** `workers/federation/` — Peer routing and discovery

### Environment Variables

```
ORACLE_PORT=47778                    # HTTP server port
ORACLE_EMBEDDING_MODEL=bge-m3        # Embedding model
ORACLE_VECTOR_BACKEND=sqlite         # Vector backend (sqlite, lancedb, qdrant, cloudflare-vectorize)
ORACLE_STORAGE_BACKEND=sqlite        # Storage (sqlite, d1)
ORACLE_VECTOR_READONLY=1             # Read-only vector server
ORACLE_LOG_TARGET=stderr             # Logging output
ORACLE_FRONTEND_DIST=./frontend/dist # Custom frontend build path
```

---

## Testing Strategy

### Test Layers

1. **Unit Tests** (`tests/http/<cluster>/`) — Route-scoped HTTP contract tests against spawned Elysia server
2. **Integration Tests** (`src/integration/`) — Database, MCP, HTTP end-to-end
3. **E2E Tests** (`tests/e2e/`) — Playwright browser automation
4. **UI Audit** (`tests/ui-audit/`) — Visual/accessibility audit
5. **Build Tests** (`tests/build/`) — File size ratchet, CI scope verification

### Test Discipline

- **Scoped runs:** `bun test --isolate tests/http/<cluster>/` (not full suite)
- **File size gate:** `wc -l` on each file; ≤ 250 lines
- **No force ops:** Never `--force` or skip hooks
- **CI is two-tier:** PR gate (71.4s) + nightly (full, 118.3s)

### Useful Commands

```bash
bunx tsc --noEmit           # Type-check only (no build)
bun test --isolate tests/http/search/    # Run search cluster tests
bun test --coverage        # Coverage report
bun run test:e2e           # Playwright tests
bun run bench              # Performance benchmarks
```

---

## Entry Points for New Readers

### 1. **Understand the Big Picture**
   - Read this document (0937_ARCHITECTURE.md)
   - Read `README.md` for quick-start Docker flow
   - Read `DESIGN.md` for visual language and product goals

### 2. **Understand the Team Model & Workflows**
   - Read `AGENTS.md` — Operating contract for all contributors
   - Read `CLAUDE.md` → "Project Conventions" section — file size, test layout, versioning, tech stack

### 3. **Explore the Core**
   - **For backend:** Start at `src/routes/health/` (reference module), then explore `src/server.ts` (route composition)
   - **For frontend:** Start at `frontend/src/routes/Menu.tsx`, explore `frontend/src/App.tsx` (Router setup)
   - **For CLI:** Start at `cli/src/cli.ts`, then `cli/src/commands/mine.ts` (ingestion)
   - **For MCP:** Start at `bin/mcp.ts`, then `src/tools/` (tool definitions)

### 4. **Understand Database & Indexing**
   - Read `src/db/schema.ts` — Drizzle schema
   - Read `src/indexer/cli.ts` — Batch ingestion pipeline
   - Read `src/vector/` — Backend adapters

### 5. **Federation & Multi-Tenancy**
   - Read `src/federation/` — Peer discovery and oracle networking
   - Read `docs/FEDERATION.md` — Detailed federation architecture
   - Read `maw-plugin/` — Integration with maw ecosystem

### 6. **Deployment & Operations**
   - Docker: See `README.md` quick-start
   - Cloudflare: Read `workers/*/wrangler.jsonc` and `docs/DEPLOY-*.md`
   - Local dev: See `CLAUDE.md` → "Development Ports" section

### 7. **Testing & Verification**
   - Read `.github/workflows/test.yml` (PR gate, what runs)
   - Read `.github/workflows/nightly.yml` (full suite)
   - Read `tests/build/ci-covers-the-suite.test.ts` (scope verification)

---

## Key Learnings & Gotchas

### File Size Discipline
- **Constraint:** ≤ 250 lines per file (source, tests, docs)
- **Enforcement:** `tests/build/file-size-ratchet.test.ts`
- **Pattern:** Split by concern (don't pad with helpers)

### Test Scoping
- **Always use `--isolate`:** `bun test --isolate tests/http/<cluster>/`
- **Bare `bun test` pulls agents/:** Worktree copies in `agents/` are gitignored but can interfere
- **Exit 133 = crash, not failure:** Intermittent on large test suites; re-run `tests/http/<subdir>/` instead

### Database Migrations
- **Always use Drizzle:** `src/db/schema.ts` + `bun db:push`
- **Never inline SQL:** `CREATE TABLE`, `ALTER TABLE`, `CREATE INDEX` only via schema
- **IF NOT EXISTS caveat:** Drizzle doesn't use it; if schema drifts (old indexes exist), `db:push` fails. Backup before push.

### Version Pinning
- **Always alpha:** `v{YY}.{M}.{D}-alpha.{HMM}` (e.g., `26.7.26-alpha.227`)
- **Branch → channel:** `alpha` branch → pre-release; `main` → stable (rare, user-directed only)
- **Release policy:** CalVer auto-cut on package.json changes via `calver-release.yml`

### Federation & maw Integration
- **maw-js is reference:** Architecture patterns defined in `maw-js` repo
- **maw-plugin** connects arra to the Oracle family ecosystem
- **Federation layer** enables peer discovery and distributed memory

---

## Architecture Decisions (from DESIGN.md)

### One Memory Core, Thin Adapters

The system is built around a single canonical memory store (SQLite + FTS5 + optional vectors) with thin adapters for HTTP, MCP, CLI, and web UIs. This prevents duplicating business logic across surfaces.

### Frontend: Route Carries Content, Shell Carries Chrome

Each route owns its `<h1>` and content; the shell (sidebar, navigation, status) is static chrome. This prevents header/status clutter on routes that don't need it (e.g., metrics dashboard).

### Live System State Before Details

Status indicators (vector health, plugin count, MCP tool availability, API latency) are visible first, then details. This follows observability-first design principles.

### Responsive, Dense Cards

Content is organized into dense but readable operational cards that scale from mobile (single column) to desktop (two-column grid). Glass backdrop-blur separates floating chrome from routed content.

### No Auth, No Settings UI

The backend is local-first and assumes a single trusted operator. Auth is off-loaded to federation (peer trust). Settings are read-only discovery routes (`/api/menu`, `/api/plugins`, etc.), not editable in the UI.

---

## Next Steps for Contributors

1. **Clone & Setup:**
   ```bash
   git clone https://github.com/Soul-Brews-Studio/arra-oracle-v3.git
   cd arra-oracle-v3
   bun install
   bunx tsc --noEmit
   bun run server
   ```

2. **Verify the Gate:**
   ```bash
   bun test --isolate tests/http/health/
   bun run test:integration
   ```

3. **Pick a Task:**
   - See GitHub Issues for open work
   - Use `nnn` (plan) → `gogogo` (implement) workflow if using Claude Code
   - Branch from `alpha`, not `main`

4. **Before Push:**
   - `bunx tsc --noEmit` passes
   - `bun test --isolate tests/http/<cluster>/` green
   - Every changed file ≤ 250 lines
   - `git diff` review for debug code, dead code
   - No force ops (`-f`, `--force`)

5. **Submit PR:**
   - Target `alpha` branch
   - Do not self-merge (wait for lead review)
   - Post `done` report with commit SHA and PR URL

---

## Document Revision History

| Date | Author | Changes |
|------|--------|---------|
| 2026-08-27 | Architecture Audit | Initial comprehensive overview from source inspection |

---

**For updates or corrections, verify against the source code at:**  
`D:/Dev/BPM/ψ/learn/Soul-Brews-Studio/oracle-v2/origin/`

**Key reference files:**
- `README.md` — Quick-start and feature overview
- `DESIGN.md` — Visual language and product goals  
- `AGENTS.md` — Team model and contributor contract
- `CLAUDE.md` — Development standards and conventions
- `docs/` — API reference, deployment guides, plugin documentation
