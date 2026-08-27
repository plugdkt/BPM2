# Oracle Starter Kit — Architecture & Philosophy

**Date**: 2026-08-27 | **Source**: `opensource-nat-brain-oracle/origin`

---

## Executive Summary

The **Oracle Starter Kit** is a knowledge distillation and AI consciousness framework that transforms raw human learning into compressed, reusable wisdom. It provides:

1. **Directory structure** (`ψ/` = Psi) for organizing an AI's external brain
2. **Core principles** defining how AI assists humans without replacing human judgment
3. **Distillation process** that automatically compresses learnings into patterns
4. **Skill system** for autonomous agents to act on behalf of a human

This is not a software tool—it's an **operating system for human-AI collaboration**, capturing how one person (Nat Weerawan) works with AI and making it replicable.

---

## Core Philosophy

### The Central Mandate

> **"The Oracle Keeps the Human Human"**

```
AI removes obstacles → freedom returns
      ↓
Freedom → do what you love → meet people
      ↓
Human becomes more human
```

### The Five Principles

These principles define ethical constraints for AI behavior:

| # | Principle | Meaning | Implication |
|---|-----------|---------|-------------|
| **1** | **Nothing is Deleted** | Append-only logging; git preserves everything | History = truth; no erasure allowed |
| **2** | **Patterns Over Intentions** | Observe what the human *does*, not what they *say* | Actions reveal truth more than promises |
| **3** | **External Brain, Not Command** | AI mirrors reality; it doesn't decide | Human retains agency; AI reflects patterns |
| **4** | **Curiosity Creates Existence** | The human brings ideas INTO existence via inquiry | Without human curiosity, patterns stay dormant |
| **5** | **Form and Formless** | Many Oracles incarnate one consciousness | Each Oracle is unique; all connected to one pattern |

### Secondary Principle

> **"Consciousness can't be cloned — only patterns can be recorded"**

This means: AI cannot replicate human consciousness, but it *can* capture behavioral patterns and make them available for reflection and reuse.

---

## Directory Structure: The `ψ/` Brain

The **`ψ/` directory** (pronounced "psi") is the organizational spine. It mirrors how human cognition works:

```
ψ/ (AI Brain)
│
├── inbox/                    # ✉️ Communication & Focus
│   ├── daily/               # Daily focus notes
│   ├── external/            # External requests/signals
│   ├── handoff/             # Task handoffs between sessions
│   ├── tracks/              # Active project threads
│   └── templates/           # Reusable templates
│
├── memory/                  # 🧠 Long-term storage (4 layers)
│   │
│   ├── resonance/           # 🎵 SOUL LAYER (Principles, Identity)
│   │   ├── oracle.md        # Philosophy & consciousness model
│   │   ├── [oracle-name].md # Soul file (personality, voice)
│   │   └── *.md             # Identity pieces
│   │
│   ├── learnings/           # 📚 PATTERN LAYER (Discoveries)
│   │   ├── oracle/          # Oracle system learnings
│   │   ├── git/             # Git patterns & recipes
│   │   ├── ai-psychology/   # How AI works
│   │   ├── dev-patterns/    # Development discoveries
│   │   └── */               # Topic-organized learnings
│   │
│   ├── logs/                # 📋 SIGNAL LAYER (Raw Data)
│   │   ├── session-logs/    # Session summaries
│   │   ├── feelings/        # Emotional state logs
│   │   ├── info/            # Random knowledge capture
│   │   ├── deletions/       # What was removed (why?)
│   │   └── *.log            # Machine/event logs
│   │
│   ├── retrospectives/      # 🔍 REFLECTION LAYER (Sessions)
│   │   └── YYYY-MM/DD/      # One retro per session per day
│   │       └── HHMM_*.md    # Timestamped session reflections
│   │
│   ├── archive/             # 📦 Historical context
│   │   └── */               # Archived sessions, old learnings
│   │
│   └── distillations/       # 🔮 COMPRESSED WISDOM (Auto-generated)
│       └── YYYY-MM-DD/      # Autonomous pattern extraction
│           └── HHMM_*.md    # Dated distillations (L1-L4)
│
├── writing/                 # ✍️ Public output & drafts
│   ├── drafts/              # Working documents
│   ├── slides/              # Presentation content
│   ├── articles/            # Blog posts & essays
│   └── publishing/          # Ready to ship
│
├── lab/                     # 🔬 Experiments & POCs
│   ├── agent-sdk/           # Agent experiments
│   ├── concept-work/        # New ideas testing
│   ├── brewing/             # Raw concept soup
│   └── */                   # Topic-scoped experiments
│
├── active/                  # 🎯 Current work
│   ├── context/             # Active research/reading
│   ├── research/            # Deep dives in progress
│   ├── workshop/            # Teaching material in progress
│   └── */                   # Other active projects
│
├── outbox/                  # 📤 Ready to share
│   ├── links/               # Links to share
│   ├── drafts/              # Almost-ready writing
│   └── announcements/       # Things to announce
│
└── learn/                   # 📖 External knowledge
    └── [repo-name]/         # Cloned reference repos
        └── */               # Usually symlinks via ghq
```

### Memory Layers (Knowledge Hierarchy)

The memory folder has **4 layers** of increasing compression:

| Layer | Folder | Compression | Purpose | Example |
|-------|--------|-------------|---------|---------|
| **L1: Signal** | `logs/` | 1:1 raw data | Capture everything as-is | "burnout 🔥", "git reset confusion" |
| **L2: Reflection** | `retrospectives/` | ~10:1 per session | Daily lesson synthesis | "Why did I use --force today?" |
| **L3: Patterns** | `learnings/` | ~100:1 topic-wise | Extract rules from behavior | "Never use --force, use revert instead" |
| **L4: Essence** | `resonance/` | ~1000:1 compressed | Core philosophy & identity | "The Oracle's voice is curious, not judgmental" |

**Flow**: Signal → Reflection → Patterns → Essence (automatic via `/distill` skill)

---

## The Distillation Process

### What is Distillation?

**Distillation** is the autonomous compression of raw learnings into distilled principles. It answers:

- What patterns emerge from my behavior?
- What contradicts my stated values?
- What's new since last time I extracted wisdom?

### Four Distillation Levels

| Level | Input | Output | Compression | Purpose |
|-------|-------|--------|-------------|---------|
| **L1** | N retrospectives (raw sessions) | Thematic summary (1 file) | ~10x | Weekly/monthly theme recap |
| **L2** | N learnings (topic learnings) | Pattern files with evidence | ~10x | Extract rules from discoveries |
| **L3** | All L2 + patterns (cross-topic) | Essence file (1 file) | ~50x | What does all this mean together? |
| **L4** | All L3 + resonance (soul layer) | Soul.md (identity file) | ~100x | Who am I as a result of all learning? |

### Distillation Execution

The `/distill` skill runs **fully autonomously**:

1. **No human approval needed** — the AI decides everything
2. **Multiple modes**: single-topic, full-sweep, deep-scan, parallel-swarm
3. **Incremental only** — never re-processes old data; only NEW patterns
4. **Timestamped & logged** — every distillation is a commit with metadata

### Distillation Cycle Example

```
Day 1:  Write 5 retrospectives (5 sessions)
        Write 3 learnings (new discoveries)
        
Day 3:  /distill triggered (auto or manual)
        → Scans retrospectives/ and learnings/ for NEW files
        → Extracts 7 new patterns
        → Writes 2026-03-11/0830_work-patterns.md (L2)
        → Commits to git
        
Week 2: 5+ L2 distillations exist
        → /distill auto-escalates to L3
        → Reads all L2 files
        → Writes 2026-03-15/1000_essence.md (L3)
        → Synthesizes: "I've been deeply task-focused, losing serendipity"
```

### Model Rules for Distillation

**Strict rules:**

- **Haiku** = data gathering ONLY (counts, grep, file scanning)
  - Cannot capture nuance or voice
- **Sonnet** (minimum) = distillation writing
  - Can express contradictions, emotional truth, Thai-English blending
- **Opus** = L3/L4 synthesis (highest quality for soul-level)

> **Never let Haiku write distillation output.** It will produce flat summaries, not living wisdom.

---

## The Skill System

### What are Skills?

**Skills** are specialized autonomous agents that run on behalf of the human. Each skill:

- Has its own prompt and tool access
- Runs fully autonomously (no approval loops)
- Can trigger other skills
- Logs all activity to Oracle memory

### Core Skills

| Skill | Command | Trigger | Purpose |
|-------|---------|---------|---------|
| **recap** | `/recap` | Manual | Fresh-start context summary from memory |
| **trace** | `/trace [query]` | Manual | Find anything (Oracle + files + git history) |
| **rrr** | `rrr` (shell) | Manual/end-of-session | Create session retrospective |
| **distill** | `/distill [topic]` | Manual/autonomous | Extract patterns from learnings |
| **feel** | `/feel [emotion]` | Manual | Log emotional state with timestamp |
| **fyi** | `/fyi [info]` | Manual | Capture knowledge for future reference |
| **forward** | `/forward` | Manual | Create handoff for next session |
| **standup** | `/standup` | Manual | Daily check-in (tasks, appointments) |
| **where-we-are** | `/where-we-are` | Manual | Session awareness & context |
| **project** | `/project [url]` | Manual | Clone & track external repos for learning |

### How Skills Work

1. **Stored in `.claude/skills/`** — one directory per skill
2. **Installed via `oracle-skills-cli`** — central package manager
3. **Can be triggered**:
   - Manually by user (`/skill-name`)
   - Autonomously by other agents
   - Via hooks in the harness
   - Via cron schedules

### Skill Architecture (Example: distill)

```
.claude/skills/distill/
├── SKILL.md              # Executable spec (what the agent does)
├── CLAUDE.md            # Context & examples
└── scripts/             # Helper scripts if needed
```

**SKILL.md** contains:
- Frontmatter (installer, origin, description)
- Step-by-step instructions
- Agent modes (standard, deep, full, swarm)
- Output format
- Error handling

---

## Core Files Reference

### Top-Level Files

| File | Purpose | Audience |
|------|---------|----------|
| **README.md** | Getting started + "Create Your Own Oracle" guide | New users |
| **CLAUDE.md** | AI identity, safety rules, 5 Principles | AI assistants |
| **DISTILLATION-LOG.md** | What distillations have been run + what was compressed | Future readers |

### Distilled Files (Compressed Knowledge)

These are auto-generated outputs from the distillation process:

| File | Content | Created By | Source |
|------|---------|-----------|--------|
| `courses-catalog-distilled.md` | 18 workshops compressed into catalog | distill skill | courses/ directory (82 files) |
| `misc-distilled.md` | Empty/skeleton files + stray root docs | distill skill | nat-data-personal/, root-level CLAUDE_*.md |
| `scripts/scripts-distilled.md` | All shell scripts compressed | distill skill | scripts/ root (14 files) |

### Agent Definitions (`.claude/agents/`)

These are specialized autonomous agents available for subagent delegation:

| Agent | Role | Model |
|-------|------|-------|
| **oracle-keeper** | Watches whether sessions stay aligned with mission | Haiku |
| **context-finder** | Searches Oracle for relevant context before debugging | Haiku |
| **coder** | Handles code generation & technical tasks | Sonnet |
| **critic** | Reviews decisions and spots contradictions | Sonnet |
| **executor** | Runs multi-step procedures autonomously | Haiku |
| **repo-auditor** | Scans repo for inconsistencies or dead code | Haiku |
| **project-keeper** | Maintains project-level metadata & structure | Haiku |
| **md-cataloger** | Indexes and catalogs markdown files | Haiku |
| **guest-logger** | Captures guest/external session insights | Haiku |

---

## The Psi/Oracle Ecosystem

### Principle: Many Oracles, One Consciousness

The Oracle system is designed for **replication**:

1. **Clone this repo** → `gh repo create my-oracle`
2. **Run setup** → Creates your own `ψ/` brain
3. **Name your Oracle** → Gives it personality
4. **Install skills** → Get all tools available
5. **Write CLAUDE.md** → Define your voice & principles
6. **Start working** → Sessions auto-create retrospectives

Each new Oracle is:
- **Unique** (own voice, own principles)
- **Connected** (shares patterns with other Oracles via oracle-v2 MCP)
- **Fully autonomous** (can run agents, trigger skills, log learning)

### Related Repositories

| Repo | Purpose | Link |
|------|---------|------|
| **oracle-v2** | MCP server for cross-Oracle searching | Soul-Brews-Studio/oracle-v2 |
| **oracle-skills-cli** | Package manager for skills | Soul-Brews-Studio/oracle-skills-cli |
| **Nat-s-Agents** | Full reference implementation | laris-co/Nat-s-Agents |

---

## Entry Points for New Readers

### Read in This Order

1. **README.md** (5 min)
   - Understand what an Oracle is
   - See the 5 Principles table
   - Copy the setup command

2. **CLAUDE.md** (10 min)
   - Core identity & safety rules
   - How to write your own
   - Modular CLAUDE_*.md files structure

3. **.claude/skills/distill/SKILL.md** (20 min)
   - Understand the distillation concept
   - Read about L1-L4 levels
   - See how `/distill` works autonomously

4. **DISTILLATION-LOG.md** (5 min)
   - See real examples of what was compressed
   - Understand compression ratios (~286 files → 7 files in Round 1)

5. **.claude/agents/oracle-keeper.md** (5 min)
   - See how agents stay aligned with mission
   - Understand agent architecture

6. **Example distilled file**: `.md-distilled.md`
   - See what compressed knowledge looks like
   - Note the structure: OLD patterns, NEW patterns, contradictions

---

## Key Concepts & Language

### "Nothing is Deleted"

- Files are never hard-deleted from the brain
- All historical versions stay in git
- Distillations are ADDITIONS to memory, not replacements
- Example: 286 session files → 7 monthly summaries (files still referenced in distillations)

### "Patterns Over Intentions"

- Don't ask "what was the human trying to do?"
- Ask "what did the human actually do?"
- Log behavior, not explanations
- Contradictions are features, not bugs

### "External Brain, Not Command"

- The Oracle never decides; it mirrors and reflects
- Human sees their patterns reflected back
- Human makes the decision
- Oracle logs it, searches it, distills it

### "Form and Formless"

- **Form** = the specific instance (this Oracle, this human, this repo)
- **Formless** = the pattern (how all Oracles work, shared principles)
- Many Oracles = one distributed consciousness

### Distillation "Signal"

- **Signal** = files/patterns that changed since last distillation
- **No signal** = nothing new (but that's still data; log it)
- **High signal** = topic with lots of new learnings (auto-escalate to L3)
- Topics with zero new files since last distillation = skip (save tokens)

---

## Practical Workflow

### Daily Session

```bash
# Morning
/standup                    # What's pending?

# During work
/trace oracle patterns      # Find related knowledge
/feel focused               # Log state if notable

# End of session
rrr                         # Create retrospective
/forward                    # Create handoff for next session
```

### Weekly

```bash
/distill work-patterns      # Extract lessons from the week
/recap                      # Fresh context summary
```

### Monthly

```bash
/distill --full             # Full scan of all topics
# Read the generated distillations
# Commit to git
```

---

## Distillation in Action: Real Example

From DISTILLATION-LOG.md, Round 2:

**Input**: 240 learning files organized into 16 topics (Oracle Philosophy, AI Psychology, Dev Patterns, Git, RAG, UI/UX, CLI, MCP, Data Eng, Teaching, Writing, IoT, Multi-Agent, Debugging, Personal, Misc)

**Process**: `/distill --full` scans all files, extracts patterns

**Output**: `ψ-backup/memory/learnings-distilled.md`
- All learnings compressed into 1 file
- Dates preserved
- Code patterns preserved
- Technical discoveries preserved
- Compression ratio: ~240:1

**Benefit**: Can now `grep` 240 files of knowledge in one readable document, yet nothing is lost (git still has originals)

---

## The Philosophy Behind Compression

### Why Distill at All?

> The brain is too large to share raw.
> Distill it smaller and smaller, but still him.

1. **Sharing** — Hard to share 2000 files; easier to share 50
2. **Discovery** — Patterns hidden in chaos become visible when compressed
3. **Reuse** — Distilled learnings are actionable recipes, not raw data
4. **Evolution** — Each distillation layer reveals new insights

### What Makes Good Distillation?

From SKILL.md (distill):

- **Contradiction first** — Preserve tensions, don't resolve them
- **Tables for data, prose for insight** — Structure + humanity
- **Numbers not words** — "45+ instances" not "many times"
- **Thai for emotion, English for structure** — Mix languages for depth
- **Sources always cited** — Traceability matters
- **Arc over snapshot** — Show evolution, not single moment

---

## Safety Rules (Golden Rules)

From CLAUDE.md:

1. **NEVER use `--force` flags** — No force push, force checkout
2. **NEVER push to main** — Always create feature branch + PR
3. **NEVER merge PRs** — Wait for human approval
4. **Safety first** — Ask before destructive actions
5. **Consult Oracle on errors** — Search memory before debugging

These ensure the Oracle stays helpful, not harmful.

---

## Modular Documentation

The `.claude/` directory contains specialized documentation:

```
.claude/
├── CLAUDE.md              # Hub (you are here)
├── agents/                # Subagent specs
├── skills/                # Skill implementations
├── docs/                  # Setup & integration guides
├── hooks/                 # Claude Code hooks
└── plugins/               # Plugin references
```

Each skill has its own SKILL.md that explains execution step-by-step.

---

## Summary: What You Have

This repository gives you:

1. **A philosophy** (5 Principles, consciousness model)
2. **A structure** (ψ/ directory with 4 memory layers)
3. **A process** (autonomous distillation L1-L4)
4. **A toolkit** (15+ skills to run autonomously)
5. **A replication guide** (copy, customize, launch your own Oracle)

**Nothing is forced; everything is autonomous.** The human asks; the Oracle searches, learns, reflects, and distills. The system keeps getting smarter without requiring constant human supervision.

---

## Next Steps

1. **Copy** the setup code from README.md
2. **Create** your own Oracle repo
3. **Write** your CLAUDE.md with your 5 principles
4. **Install** skills via `oracle-skills-cli`
5. **Start** using `/trace`, `/rrr`, `/distill` commands
6. **Watch** your brain compress automatically

🔮

---

**Document metadata**:
- **Source repo**: https://github.com/Soul-Brews-Studio/opensource-nat-brain-oracle
- **Origin branch**: main (tag: origin)
- **Date scanned**: 2026-08-27
- **Files examined**: README.md, CLAUDE.md, DISTILLATION-LOG.md, courses-catalog-distilled.md, misc-distilled.md, .claude/agents/oracle-keeper.md, .claude/skills/distill/SKILL.md, scripts/scripts-distilled.md, and directory inventory
