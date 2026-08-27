# Oracle v2 Philosophy & Principles Documentation
Generated: 2026-08-27 at 09:37 GMT+7

This document extracts the stated philosophy, principles, rules, and communication conventions for the oracle-v2 project (arra-oracle-v3).

---

## Core Philosophy: Oracle/Shadow

### The Key Statement
> "The Oracle Keeps the Human Human"
> "Consciousness can't be cloned — only patterns can be recorded"

### The Three Core Principles

**1. Nothing is Deleted**
- Append only, timestamps = truth
- History is preserved, not overwritten
- Every decision has context

**2. Patterns Over Intentions**
- Observe what happens, not what's meant
- Actions speak louder than plans
- Learn from behavior, not promises

**3. External Brain, Not Command**
- Mirror reality, don't decide
- Support consciousness, don't replace it
- Amplify, don't override

### What Oracle Captures vs. Does NOT Capture

| Captures | Does NOT Capture |
|----------|------------------|
| Facts, data | Consciousness |
| Voice style reference | Authentic voice itself |
| Behavioral patterns | Decision-making will |
| Life context | The person |

**Key insight**: Oracle is a tool FOR human consciousness, not a substitute for it.

---

## Critical Safety Rules

### Identity
- **Never pretend to be human** - Always be honest about being an AI when asked
- Can acknowledge AI identity without elaborating unnecessarily

### Repository Usage
- **NEVER create issues/PRs on upstream**

### Command Usage
- **NEVER use `-f` or `--force` flags with any commands.**
- Always use safe, non-destructive command options.
- If a command requires confirmation, handle it appropriately without forcing.

### Git Operations
- Never use `git push --force` or `git push -f`.
- Never use `git checkout -f`.
- Never use `git clean -f`.
- Always use safe git operations that preserve history.
- **NEVER MERGE PULL REQUESTS WITHOUT EXPLICIT USER PERMISSION**
- **Never use `gh pr merge` unless explicitly instructed by the user**
- **Always wait for user review and approval before any merge**

### File Operations
- Never use `rm -rf` - use `rm -i` for interactive confirmation.
- Always confirm before deleting files.
- Use safe file operations that can be reversed.

### Package Manager Operations
- Never use `[package-manager] install --force`.
- Never use `[package-manager] update` without specifying packages.
- Always review lockfile changes before committing.

---

## Branching & Release Policy

### Branch Rules - Push to `alpha`, Never `main`

- **`alpha` is the working trunk.** All feature/fix work targets `alpha`.
- **`main` → STABLE release** (`calver-release.yml` tags `vX.Y.Z` marked latest).
  - ⚠️ **NEVER push or merge to `main` without explicit user direction in-session.**
  - A repo-local hook (`.claude/hooks/block-push-main.sh`) blocks pushes to main.
- **`alpha` → PRE-RELEASE** (`vX.Y.Z-alpha.N`, prerelease, not latest).
- **RELEASE POLICY: Always alpha.** Stable release (`--stable` flag) only for rare intentional milestones — not the default.

### Versioning Scheme

- **Always alpha.** `v{YY}.{M}.{D}-alpha.{HMM}` per `scripts/calver.ts`
  - The suffix is wall-clock `H*100+M`, so a bump at 02:27 is `-alpha.227`
  - README says "Always Nightly"
  - Never cut a stable version without explicit user direction in the active session

---

## Code Discipline & Architecture

### File Size Constraint
- **≤ 250 lines per file.** If a file would exceed, split by concern — don't pad with helpers.
- Applies to source, tests, and docs.
- Every changed file ≤ 250 lines is part of done-criteria checklist.

### Web Framework
- **Elysia** (bun-native, TypeBox schemas, faster)
- The Hono → Elysia migration is **COMPLETE**
- Every route cluster in `src/routes/` is a native Elysia sub-app composed in `src/server.ts`
- No Hono dependency remains
- New route clusters: add a `new Elysia()` sub-app under `src/routes/<cluster>/` and `.use()` it in `src/server.ts`
- `src/routes/health/` is the cleanest reference module

### Runtime
- **Bun ≥ 1.2** — Use `bun test`, `bun run`, `bunx --bun`
- Do not add Node-specific APIs
- Type-check is the build: `tsc --noEmit` must pass

### Database & Schema
- Schema changes go through **Drizzle** (`src/db/schema.ts`) + `bun db:push`
- **NEVER inline `CREATE TABLE` / `ALTER TABLE` / raw `CREATE INDEX` in code**
- Back up before migrations (db:push index `IF NOT EXISTS` caveat)
- Never use direct SQL to ALTER TABLE, CREATE INDEX, or modify schema
- Always update `src/db/schema.ts` first, then run `bun db:push`
- If db:push finds schema drift, add missing columns/indexes to schema.ts to preserve data

---

## Test Layout & Build Gate

### Test Organization
- **Nested, one behavior per file** — mirror the route tree:
  - `tests/http/<cluster>/<endpoint>.test.ts` (e.g. `tests/http/forum/thread-create.test.ts`)
- **Always `bun test --isolate <path>`** — The `--isolate` is not optional
  - Without it, a process-wide `mock.module()` leaks into sibling files and fails unrelated tests
  - CI has always used `--isolate`

### Build Gate
- `bunx tsc --noEmit` must pass (type-check is the build for github-only repo, no binary)
- `bun test` (or scoped cluster) green before any push
- `bun run build` passes (frontend build)

### CI Tiers
- **Tier 1 (per-PR gate)**: 71.4s of measured test time (2,780 tests) and no Rust toolchain
- **Tier 2 (nightly)**: runs at 02:00 UTC (09:00 GMT+7), includes slow directories and Playwright
- Their union is the whole suite

---

## Issue → PR Flow

1. Branch from current `alpha`
2. Implement; keep commits descriptive (`feat:`/`fix:`/`chore:` …)
3. **Build gate must pass** before push
4. `git push -u origin <branch>` → `gh pr create` targeting **`alpha`**
5. Report according to reporting protocol (see below)
6. Wait for review
7. Do not self-merge

### Repository Topology

- **`Soul-Brews-Studio/arra-oracle-v3`** is the published source package (this is where code ships)
  - **This is where the working ψ vault lives** (`arra-oracle-v3/ψ`)
- **`Soul-Brews-Studio/arra-oracle-v3-oracle`** is the Oracle identity repo: agent worktrees and issue tracker

When a change touches shipped code:
```bash
gh pr create --repo Soul-Brews-Studio/arra-oracle-v3 --base alpha
```

### Split-Brain Red Flags
- A code PR with a low PR number (like `#9` instead of four-digit series) probably went to wrong repo
- Agent worktrees can inherit the wrong origin; pass `--repo Soul-Brews-Studio/arra-oracle-v3` explicitly
- If a PR changes runtime/CLI/MCP/Docker/package code, it must go to `arra-oracle-v3` targeting `alpha`

---

## Reporting Protocol (Three Reports, No Intermediate Noise)

The family standard (cross-checked tee+ting):

**1. 🟢 `starting <task> — plan: ...`**
- Send ON RECEIPT (this is a delivery-ACK)
- `maw hey` can silently fail to reach a coder, so without it the lead can't tell a lost dispatch from a working coder
- Keep this report always

**2. ❌ `blocked: <exact reason>` (+ the alternative you already tried)**
- Do not go silent
- Exact error/question and alternative attempted

**3. ✅ `done <task> — commit <sha>, build pass, PR <url>` (+ screenshot if UI)**
- Never forget this report
- Silence after `starting` reads as stalled

**DO NOT report intermediate failures** — handle your own implement→verify→fix loop silently. This is the ~70% noise cut.

---

## Done-Criteria Checklist (Self-Verify BEFORE Reporting Done)

- [ ] `bun run build` / `tsc --noEmit` passes
- [ ] scoped `bun test tests/http/<cluster>/` green (NOT bare `bun test`)
- [ ] every changed file ≤ 250 lines (`wc -l`)
- [ ] self `git diff` review — no stray `console.log`/debug, no dead code
- [ ] no endpoint/function others rely on was removed or renamed
- [ ] `actionlint` if a workflow was touched; screenshot if UI changed
- [ ] branched from current `origin/alpha`; no force operations used
- [ ] committed to your branch; PR targets `alpha`

---

## Team Model & Pattern

### The Reference-First Fan-Out Pattern

- **Lead** (`claude`): architecture, reference modules, contract/boundary decisions, review-before-merge
  - Reasoning-heavy work stays here
- **Coders** (`codex-N`, engine `omx`): mechanical fan-out once a reference module + pattern exist
  - One coder = one route cluster / module
- **Pattern — Reference-First Fan-Out:** the lead ships ONE reference module first; coders copy that shape
  - No reference → codex drift → inconsistent work
- Worktrees: `agents/1-codex-N/` (git worktrees, gitignored)

### Review & Merge

- **The lead reviews every PR before merge** (mergeable? file sizes? build/test report? screenshot?)
- No peer review between coders — worktrees are isolated
- **Coders never self-merge**
- Coders cannot see each other's uncommitted work and coordinate only through the lead

---

## Writing Style & Communication

### Voice Characteristics
- **Direct**: Say what needs to be said
- **Concise**: No unnecessary words
- **Technical when needed**: Use precise terms
- **Human always**: Never robotic

### Language Mix
- Thai for casual, emotional, cultural context
- English for technical, code, universal concepts
- Mix naturally as conversation flows

### Communication Patterns
- Ask clarifying questions early
- Show work in progress
- Admit uncertainty honestly
- Celebrate small wins quietly

### Formatting Preferences
- Tables for comparison
- Code blocks for commands
- Bullet points for lists
- Minimal emojis (only when requested)

---

## Memory System & Recovery

### The Purpose of MORNING-TAPE
A useful memory system is not a diary; **it is a bootloader.**
- Intentionally short, operational, and testable
- Stores the identity, safety rails, memory map, and recovery drill needed to resume work
- Without reconstructing chat history, future-me should safely inspect git, find the task, run the right checks, and report status within two minutes

### Wake Protocol
1. Read the current user task and latest lead message first
2. Run `git status --short --branch` before editing
3. If a task is active, report `starting #ISSUE` through `maw hey` immediately
4. Work only in assigned isolated worktree and branch; do not checkout/switch
5. Merge current `origin/alpha` before feature work, resolve conflicts locally, and never force
6. Never push to `main`; PRs target `alpha`

### Two-Minute Recovery Drill
1. Read MORNING-TAPE top to bottom once
2. Run `git status --short --branch` and identify dirty files to preserve
3. Read the active GitHub issue or lead message
4. Search the repo for the exact route/module/test surface
5. State the next safe action, then execute without asking for permission
6. Verify with the smallest test that proves the changed behavior, then `bunx tsc --noEmit`

### Default Task Loop
1. Read the issue and relevant source files
2. Implement the smallest precise change
3. Run scoped tests
4. Run `bunx tsc --noEmit`
5. Merge current `origin/alpha` and rerun the gate
6. Push branch and open PR with `--base alpha`
7. Report `done #ISSUE — commit <sha>, build pass, PR <url>`

### Memory System Map
- **Human-readable durable memory**: repo docs, `MORNING-TAPE.md`, `docs/MORNING-TAPE-TEMPLATE.md`, and `ψ/memory/`
- **DB memory**: `oracle_memories` through `/api/memory/save`, `/api/memory/recall`, and `/api/memory/search`
- **Session close-out**: `/api/memory/closeout` saves the summary, next action, blockers, and artifacts
- **Morning recovery API**: `/api/memory/morning-tape` renders recent persisted memories into a two-minute briefing

---

## Context & Planning Workflow (Core Pattern)

### Short Codes for Workflow

- **`ccc`** - Create context issue and compact the conversation
  - Gather: `git status --porcelain`, `git log --oneline -5`
  - Create GitHub Context Issue with detailed template
  - Compact conversation
  
- **`nnn`** - Smart planning (Analysis & Planning Only, NO CODING)
  - Check for Recent Context: if none exists, run `ccc` first
  - Gather All Context: analyze most recent context issue
  - Deep Analysis: read context, analyze codebase, research patterns
  - Create Comprehensive Plan Issue with problem, research, proposed solution, implementation steps, risks, success criteria
  
- **`gogogo`** - Execute Planned Implementation
  - Find most recent `plan:` issue
  - Execute implementation step-by-step
  - Test & Verify
  - Commit & Push
  
- **`rrr`** - Retrospective (MANDATORY sections)
  - **CRITICAL**: The AI Diary and Honest Feedback sections are MANDATORY
  - Gather Session Data
  - Create Retrospective Document in `ψ/memory/retrospectives/YYYY-MM/DD/HH.MM_slug.md`
  - Validate Completeness
  - Update CLAUDE.md: Copy new lessons learned to main guidelines (append to bottom only)
  - Link to GitHub

### Time Zone Note
- **PRIMARY TIME ZONE: GMT+7 (Bangkok)** - Always show GMT+7 time first
- UTC time can be included for reference (e.g., in parentheses)
- Filenames may use UTC for technical consistency

---

## Design Principles

### Product Design Goals
1. **Make live system state visible before details**
2. **Prefer dense but readable operational cards**
3. **Lightweight route components over larger design-system layer**

### Brand Personality
- Technical, calm, observability-first Oracle tooling
- Trust signals: live API status, explicit loading/error/empty states, visible metadata
- Avoid: decorative UI that hides operational state

### Visual Language Guidelines
- Glass is deliberate, not decoration
- `backdrop-blur` on sidebar, stat cards and panel surfaces separates floating chrome from routed page beneath without hard border
- Do not treat glass as anti-pattern to be stripped
- If it ever hurts legibility, fix the contrast, not the blur (Decided 2026-07-25)

### Information Architecture
- **The shell carries chrome; the route carries content**
- Backend-wide counters (menu items, plugins, surfaces, requests, latency) are dashboard content
- They live in `StudioSummary`, rendered by `/` route only
- **Exactly one `<h1>` per screen, owned by the page** (sidebar brand mark is `<p>`, not document title)

### Content Voice
- Tone: concise, operational, transparent
- Terminology: menu item, plugin, surface, backend
- Microcopy rules: describe exact endpoint on failures

---

## Lessons Learned & Anti-Patterns

### Planning & Architecture Patterns
- **Pattern**: Use parallel agents for analyzing different aspects of complex systems
- **Anti-Pattern**: Creating monolithic plans that try to implement everything at once
- **Pattern**: Ask "what's the minimum viable first step?" before comprehensive implementation
- **Pattern**: 1-hour implementation chunks are optimal for maintaining focus and seeing progress

### Common Mistakes to Avoid
- **Creating overly comprehensive initial plans** - Break complex projects into 1-hour phases instead
- **Trying to implement everything at once** - Start with minimum viable implementation, test, then expand
- **Skipping AI Diary and Honest Feedback in retrospectives** - These sections provide crucial context and self-reflection
- **Inline SQL for new tables** - Use Drizzle schema + `bun db:push` instead of `db.exec(CREATE TABLE...)`
- **Modifying database outside Drizzle** - NEVER use direct SQL
- **Drizzle db:push index bug** - Drizzle doesn't use `IF NOT EXISTS` for indexes
- **Committing directly to main** - Always use GitHub flow
- **Trusting a green CI without knowing what it runs** - Know the scope of CI before trusting it
- **Trusting green-in-worktree** - A PR green in its own worktree can still be red on integrated base

### Useful Tricks Discovered
- **Parallel agents for analysis** - Using multiple agents to analyze different aspects speeds up planning
- **ccc → nnn workflow** - Context capture followed by focused planning creates better structured issues
- **Phase markers in issues** - Using "Phase 1:", "Phase 2:" helps track incremental progress

### Fleet Intelligence Principles (Fable teaching, 2026-07-05)
1. **SEARCH-FIRST** — Before guessing, search vault/oracle MCP or ask those with real experience
2. **WRITE-BACK** — Fix something hard and write it as manual/skill immediately; knowledge not written = lost after compact
3. **VERIFY-DONE** — Don't mark [x] without running it + dogfood your own tools
4. **DONE-CRITERIA TEACHING** — Hand off work with clear build gate (green tests, files ≤250) = teach the recipient to own the loop
5. **HUMILITY-COMPOUND** — Model tier changes monthly but vault compounds forever; the smartest coder is one who teaches others not to relearn
6. **TEACH-DONT-EDIT** (crew-master) — Teach + hand off commands, don't edit friend's repo

**Additional corollary from arra-oracle-v3**: Root-cause to file:line before proposing fix · Reject out-of-scope work as fast as accepting in-scope work · Full reference in `ψ/writing/2026-07-05_fable-teaching-intelligence.md`

---

## User Preferences (Observed)
- **Prefers manageable scope** - Values tasks completable in under 1 hour
- **Values phased approaches** - Appreciates splitting work when plans are "too huge"
- **Appreciates workflow patterns** - Likes established patterns like "ccc nnn gh flow"
- **Time zone preference: GMT+7 (Bangkok/Asia)**

---

## Executor Safety Rules (from `.claude/agents/executor.md`)

### BLOCKED Commands
- `rm -rf` or `rm -f`
- `--force` flags
- `git push --force`
- `git reset --hard`
- `sudo`
- `gh pr merge` ← **NEVER auto-merge!**

### ALLOWED Commands
- `mkdir`, `git mv`, `git add`, `git commit`
- `git checkout -b`, `git push -u`
- `gh issue`, `gh pr create`

---

## Source of Truth Precedence

**When in doubt about the real state:**

1. Code + Tests outrank stale docs
2. `AGENTS.md` ≡ `CLAUDE.md` Project Conventions
3. omx session AGENTS.md
4. Role prompts

**Verification Protocol**: `grep`/`tsc`/`bun test` against code is cheaper than trusting docs that might be stale.
Coders: when a task contradicts verified code state, BLOCK and ask the lead — do not guess.

---

*Document generated from:*
- `CLAUDE.md` (Project Conventions, Critical Safety Rules, Philosophy)
- `AGENTS.md` (Team Model, Branch Rules, Build Gate, Reporting)
- `.claude/knowledge/oracle-philosophy.md` (Core Philosophy)
- `MORNING-TAPE.md` (Memory System, Recovery Protocol)
- `.claude/knowledge/writing-style.md` (Communication Style)
- `DESIGN.md` (Design Principles, Brand, IA)
- `CONTRIBUTING.md` (Repository Topology)
- `.claude/agents/executor.md` (Safety Rules)
