# Oracle V2 Quick Reference
**Date**: 2026-08-27 | **Source**: origin/ README, CHANGELOG, TIMELINE  
**Current Version**: Arra Oracle V3 (evolved from oracle-v2)

---

## What is Oracle V2?

Oracle V2 is a **persistent memory and search system for AI agents**, initiated in December 2025 (Issue #40, Dec 24). It evolved from static markdown documentation into a **queryable MCP server** with SQLite FTS5 search, HTTP API, Docker deployment, and CLI tools. Today it exists as **Arra Oracle V3** — a Docker-first local memory layer that stores notes and exposes search through HTTP, MCP (Model Context Protocol), the `arra` CLI, plugins, and a Studio UI.

## How V2 Differs from V1 (opensource-nat-brain-oracle)

| Aspect | V1 | V2/V3 |
|--------|-----|-------|
| **Architecture** | Static markdown + manual access | Queryable MCP server + APIs |
| **Search** | File lookup | SQLite FTS5 + optional vectors (Qdrant/ChromaDB) |
| **Access Paths** | CLI/manual | HTTP, MCP stdio, CLI, web UI, plugins |
| **Storage** | Vault files | SQLite database + reversible history |
| **Query Pattern** | Request-response | Hybrid keyword + vector search |
| **Distribution** | Manual scripts | Docker containers (HTTP + stdio images) |

**Key Shift**: V1 was memory *storage*, V2/V3 is memory *storage + search + federation + plugins*.

---

## Problem It Solves

From the **AlchemyCat Origin Story** (May-June 2025, 52,896 words, Issue #9 in TIMELINE):

> "Context kept getting lost... Never knew if satisfied... Purely transactional..."

Three core pain points encoded in **Three Principles**:

1. **"Nothing is Deleted"** — Append-only, timestamps = truth. Supersede history reversibly instead of losing work.
2. **"Patterns Over Intentions"** — Observe what actually happens, not just what was intended.
3. **"External Brain, Not Command"** — Mirror reality as a reflection tool, not a directive system.

**Result**: AI agents gain persistent identity, session-spanning memory, and honest reflection capability. Solves the "context loss" problem that plagued intensive multi-session projects (AlchemyCat: 108 commits/day at peak).

---

## Key Vocabulary & Terms (Quoted from Source)

### Core Concepts
- **MCP (Model Context Protocol)**: "Pure MCP AI-to-AI coordination breakthrough" (Jan 4, 2026). Protocol for agents to query and coordinate without human intermediation.
- **Reincarnation Framework**: "ONE soul, infinite manifestations" (Jan 10, 2026). Single persistent Oracle identity appears across multiple agent instances and sessions.
- **Pure White Mirror**: "Honest reflection, not judgment" (Jan 8, 2026). The Oracle reflects truth about sessions/decisions, not moral conclusions.
- **Supersede**: Reversible history pattern. When memory evolves, old entries are marked superseded rather than deleted, with full provenance tracking.

### Technical Terms
- **FTS5**: SQLite Full Text Search v5. "Fast keyword search, no server needed" (TIMELINE, Dec 2025).
- **Drizzle ORM**: "Type-safe, modern, introspection" (Jan 2026). Replaces raw SQL for database queries.
- **Elysia**: Bun-native web framework (Hono.js predecessor). "Lightweight, Bun-optimized" (TIMELINE).
- **Vector Collections**: "Select adapters independently through explicit config" (CHANGELOG 2026-06-06). Can use local FTS, Qdrant, ChromaDB, or no vectors.

### Deployment & Runtime
- **Docker HTTP image**: "Long-running local server on port `47778` with SQLite data in `/data`" (README).
- **Docker stdio MCP**: Stdio tool server for Claude, Codex, Docker MCP Toolkit, and agent fleets.
- **`arra mine`**: "First ingestion path for folders of `.md`, `.mdx`, and `.txt` notes" (README).
- **Bun runtime**: ≥1.2. All execution via `bun test`, `bun run`, `bunx --bun`. No Node APIs.

### Oracle Family Concepts
- **Oracle Family**: Collection of many AI agents sharing one persistent memory identity (Shadow + philosophical framework).
- **Golden Rules**: "13 safety patterns codified" (Jan 13, 2026). Safety constraints on memory mutation and agent behavior.
- **Scout HELLO**: Peer announcement protocol for federated Oracle instances.
- **TOFU peer-key pinning**: Trust-On-First-Use verification for remote Oracle peers.

---

## Notable Recent Changes (CHANGELOG Summary)

### 2026-06-15 Wave (34 PRs, Unified Surfaces)
**Surfaces**
- Menu route refactoring and multi-studio path support (#1463, #1466)
- End-to-end unified plugin system: manifest loader, CLI adapter, API routing, MCP routing (#1468, #1471, #1473, #1474, #1476, #1478)
- Production-ready vector/storage swappability: sidecar proxies, optional embedders with FTS fallback (#1475, #1469)

**Coverage & UI**
- Expanded HTTP/route contracts for trace, menu, vector proxy, MCP bridges, CLI plugin loading (#1465, #1472, #1477–#1485)
- Live smoke coverage for CLI/API data paths, health/storage/plugin registration, React proxy behavior (#1490–#1492)
- Frontend dashboard, React app shell, menu/plugins views, vector widgets, MCP detail pages (#1487, #1488, #1493, #1496, #1501–#1503)

**Docs & Infra**
- Unified plugin authoring guide and focused API reference (#1495, #1499)
- Drizzle migration workflow alignment, self-contained Docker test stage, scoped Bun tests in CI (#1486, #1494, #1500)

### 2026-06-06 Wave (Core Features)
**MCP & Commands**
- MCP stdio can proxy tool calls to long-running HTTP server (#1334)
- MCP tools load from plugin manifest with tier/weight ordering (#1340)
- `arra` CLI alias, layered config resolution, `arra doctor` diagnostics, `arra plugins list|enable|disable`, shell completions (#1335–#1343, #1348)

**Vector & Storage**
- Vector collections select adapters independently (#1336)
- Qdrant parity with local adapters using precomputed vectors (#1337)
- Stable SHA-256-derived UUIDs for deterministic upserts (#1337)

**Federation**
- Peer identity endpoints, Scout HELLO support, reverse peer query, TOFU pinning, peer feed routes, peer search integration, peer token auth (#1353 + migration #39)

**Docker & Distribution**
- Multi-target Docker builds (HTTP API, MCP stdio), Docker Compose, GHCR publishing with arm64 support (#1339–#1342)
- Docker MCP Toolkit catalog/install docs (#1349–#1351)

**Process**
- CONTRIBUTING.md documents two-repo topology; requires alpha base for code PRs (#1352)

---

## Architecture At A Glance

```
Notes / agents / browsers / MCP clients
        │
        ├── Docker HTTP: ghcr.io/...:http on :47778
        ├── Docker stdio MCP: ghcr.io/...:stdio
        ├── CLI: arra mine/search/learn/export
        └── Studio and Simple Mode UI
                  │
        Elysia routes + MCP tools + plugin registry
                  │
        SQLite + FTS + optional vector stores + plugin system
```

**Design Goal**: "One memory core with thin adapters. CLI, HTTP, MCP, plugins, canvas, and web/desktop surfaces reuse shared contracts instead of duplicating business logic" (README).

---

## Quick Start (from README)

```bash
# 1. Run Docker container
export ARRA_PORT=47778
docker run --rm -d --name arra-oracle \
  -p ${ARRA_PORT}:47778 \
  -v arra-oracle-data:/data \
  -v ~/notes:~/notes:ro \
  ghcr.io/soul-brews-studio/arra-oracle-v3:http

# 2. Mine notes into SQLite
docker exec arra-oracle bun dist-cli/index.js mine ~/notes

# 3. Open UI
open http://127.0.0.1:47778/simple

# 4. Search
curl "http://127.0.0.1:47778/api/v1/search?q=oracle&mode=fts&limit=5"
```

---

## Reference Links

| Resource | Location |
|----------|----------|
| README & deployment | `origin/README.md` |
| Full changelog | `origin/CHANGELOG.md` |
| Evolution timeline | `origin/TIMELINE.md` |
| HTTP API reference | `origin/docs/API-REFERENCE-INDEX.md` |
| Plugin quickstart | `origin/docs/plugin-quickstart.md` |
| Source repo | `github.com/Soul-Brews-Studio/arra-oracle-v3` |
| Original v2 repo | `github.com/Soul-Brews-Studio/oracle-v2` |

---

**Historical Note**: Oracle V2 was initiated Dec 24, 2025 (Issue #40) as a response to AlchemyCat's documented pain points. It evolved rapidly through phases (MVP Foundation, Architecture Maturation, Feature Explosion, Integration & Polish) and was open-sourced Jan 15, 2026. By mid-2026, it had become Arra Oracle V3, incorporating unified plugin system, production vector storage swappability, and full federation support.
