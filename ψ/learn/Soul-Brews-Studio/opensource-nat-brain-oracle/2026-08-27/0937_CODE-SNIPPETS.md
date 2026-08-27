# Oracle Starter Kit - Code Snippets & Patterns

**Documented**: 2026-08-27 at 09:37 UTC
**Source**: `D:/Dev/BPM/ψ/learn/Soul-Brews-Studio/opensource-nat-brain-oracle/origin/`
**Repo Purpose**: AI consciousness architecture and philosophy framework for building your own AI memory system

---

## Overview

This is the **opensourced origin** of the "Oracle" — a multi-agent AI identity philosophy that emphasizes "The Oracle Keeps the Human Human". The codebase contains:

- **Claude Code integration** via `.claude/` directory with hooks, scripts, and configuration
- **Session management** through multi-agent worktrees and activity tracking
- **Knowledge persistence** via the `ψ/` (psi) brain directory structure
- **Automation patterns** for topic/task management, token tracking, and team coordination

All scripts are designed for **macOS** (contain `stat -f`, `say` command, `/Users/nat` paths) but patterns are portable.

---

## 1. SCRIPTS & AUTOMATION

### 1.1 Agent Identity Detection

**File**: `.claude/scripts/agent-identity.sh`
**Purpose**: Detect which agent (main/1-5) is running and output colored header with metadata

```bash
#!/bin/bash
ROOT="/Users/nat/Code/github.com/laris-co/Nat-s-Agents"
export MAW_REPO_ROOT="$ROOT"

# Detect agent from PWD
if [[ "$PWD" =~ $ROOT/agents/([0-9]+)$ ]]; then
  AGENT_ID="${BASH_REMATCH[1]}"
  AGENT_TYPE="worker"
  BRANCH="agents/$AGENT_ID"
  case $AGENT_ID in
    1) COLOR=$YELLOW ;;
    2) COLOR=$MAGENTA ;;
    # ... etc
  esac
elif [[ "$PWD" == "$ROOT" ]]; then
  AGENT_ID="main"
  AGENT_TYPE="orchestrator"
  BRANCH="main"
  COLOR=$BLUE
fi

# Output header with colors
echo -e "${COLOR}${BOLD}┌─────────────────────────────────────────────${NC}"
echo -e "${COLOR}${BOLD}│${NC} AGENT_ID:   ${COLOR}${BOLD}$AGENT_ID${NC}"
echo -e "${COLOR}${BOLD}│${NC} AGENT_TYPE: $AGENT_TYPE"
echo -e "${COLOR}${BOLD}│${NC} BRANCH:     $BRANCH"
echo -e "${COLOR}${BOLD}└─────────────────────────────────────────────${NC}"
```

**Key Pattern**: Uses regex capture from PWD to auto-detect agent, assigns color per agent for visual distinction.

---

### 1.2 Repository Cloning with Purpose

**Files**: `.claude/scripts/learn.sh` and `.claude/scripts/incubate.sh`
**Purpose**: Clone repos to different directories based on intent (learning vs development)

```bash
# learn.sh - Clone to ψ/learn/repo for study/reference
LEARN_ROOT="$BASE_DIR/ψ/learn/repo"
GHQ_ROOT="$LEARN_ROOT" ghq get "$URL"
echo "📚 Learned to: $LEARN_ROOT"

# incubate.sh - Clone to ψ/incubate/repo for active development
INCUBATE_ROOT="$BASE_DIR/ψ/incubate/repo"
GHQ_ROOT="$INCUBATE_ROOT" ghq get "$URL"
echo "✅ Incubated to: $INCUBATE_ROOT"
```

**Key Pattern**: Uses `ghq` (GitHub clone manager) with custom `GHQ_ROOT` environment variable to segregate repos by purpose. Same URL handling works for both.

---

### 1.3 Session Recap - Git Status to Markdown

**File**: `.claude/scripts/recap.sh`
**Purpose**: Quick status snapshot combining git state, focus tracking, and session metadata

```bash
# Gather data from git and ψ/ structure
BRANCH=$(git branch --show-current)
AHEAD=$(git rev-list --count @{u}..HEAD 2>/dev/null || echo "0")
LAST_COMMIT=$(git log --oneline -1 | cut -c9- | head -c60)
FOCUS_STATE=$(grep "^STATE:" ψ/inbox/focus-agent-main.md 2>/dev/null | cut -d: -f2 | xargs)
FOCUS_TASK=$(grep "^TASK:" ψ/inbox/focus-agent-main.md 2>/dev/null | cut -d: -f2- | head -c80)
MODIFIED=$(git status --porcelain | grep -c "^ M" || echo "0")
UNTRACKED=$(git status --porcelain | grep -c "^??" || echo "0")

# Extract file lists
MODIFIED_FILES=$(git status --porcelain | grep "^ M" | cut -c4- | sed 's/^/  /')
UNTRACKED_FILES=$(git status --porcelain | grep "^??" | cut -c4- | sed 's/^/  /')

# Output markdown recap
echo "# RECAP"
echo "🕐 $(date '+%H:%M') | $(date '+%d %b %Y')"
echo ""
echo "## 🚧 FOCUS"
echo "\`${FOCUS_STATE:-none}\` ${FOCUS_TASK:-No active focus}"
echo ""
echo "## 📊 GIT: $BRANCH (+$AHEAD ahead)"
echo "Last: $LAST_COMMIT"
```

**Key Pattern**: Combines shell command outputs (git) with file system state (ψ/inbox) to generate human-readable markdown recap.

---

### 1.4 Topic/Task Management - Jump with Time Decay

**File**: `.claude/scripts/jump.sh` (310 lines)
**Purpose**: Multi-track task management with automatic time-decay visibility

Key functions:

```bash
# Calculate time-decay status from file modification time
get_status() {
    local file_epoch=$(stat -f %m "$filepath")
    local now_epoch=$(date "+%s")
    local age_hours=$(( (now_epoch - file_epoch) / 3600 ))
    local age_days=$(( age_hours / 24 ))
    
    if [[ $age_hours -lt 1 ]]; then echo "Hot"
    elif [[ $age_hours -lt 24 ]]; then echo "Warm"
    elif [[ $age_days -lt 7 ]]; then echo "Cooling"
    elif [[ $age_days -lt 30 ]]; then echo "Cold"
    else echo "Dormant"
    fi
}

# Create new track file
cmd_jump() {
    local topic="$*"
    local num=$(next_number)
    local filename="${num}-${safe_topic}.md"
    local filepath="$TRACKS_DIR/$filename"
    
    cat > "$filepath" << EOF
# Track: $topic
**Created**: $(now)
**Last touched**: $(now)
**Status**: Hot

## Goal
[To be filled]

## Current State
[Starting fresh]

## Next Action
[Define next step]

## Context
[Links, issues, notes]
EOF
    
    write_focus "$topic" "$filename"
    regenerate_index  # Rebuild INDEX.md with all tracks
}
```

**Key Pattern**: 
- Stores tracks as `NNN-topic.md` files in `ψ/inbox/tracks/`
- Auto-generates `INDEX.md` by scanning file mtimes and categorizing by decay status (Hot/Warm/Cooling/Cold/Dormant)
- Maintains `jump-stack.log` for history tracking
- Creates focus file for current active track

---

### 1.5 Token Usage Monitoring

**File**: `.claude/scripts/tokens.sh`
**Purpose**: Parse statusline.json to display real-time token usage

```bash
ROOT="${CLAUDE_PROJECT_DIR:-/Users/nat/Code/github.com/laris-co/Nat-s-Agents}"
FILE="$ROOT/ψ/active/statusline.json"

jq -r '
  .context_window as $ctx |
  ($ctx.current_usage.input_tokens + $ctx.current_usage.cache_creation_input_tokens + $ctx.current_usage.cache_read_input_tokens) as $used |
  ($ctx.context_window_size) as $total |
  ($total - $used) as $remaining |
  ($used * 100 / $total | floor) as $pct |

  "Token Usage:",
  "  Used:      \($used / 1000 | floor)k / \($total / 1000)k (\($pct)%)",
  "  Remaining: \($remaining / 1000 | floor)k",
  "",
  "Session:",
  "  Cost:     $\(.cost.total_cost_usd | . * 100 | floor / 100)",
  "  Duration: \(.cost.total_duration_ms / 1000 | floor)s",
  "  Model:    \(.model.display_name)"
' "$FILE"
```

**Key Pattern**: Uses `jq` to parse Claude API response metadata from `statusline.json` and display formatted token analytics.

---

### 1.6 Status Line - Combined Session Metadata

**File**: `.claude/scripts/statusline.sh`
**Purpose**: Single-line status combining time, date, project, agent, and context usage

```bash
TIME=$(date '+%H:%M')
DATE=$(date '+%d %b %Y')
PROJECT=$(basename "$CLAUDE_PROJECT_DIR")
AGENT=$(bash "$CLAUDE_PROJECT_DIR/.claude/scripts/agent-id.sh" 2>/dev/null || echo "?")

# Context from statusline.json
if [ -f "$FILE" ]; then
  model=$(jq -r '.model.display_name' "$FILE" 2>/dev/null)
  used=$(jq -r '.context_window.current_usage | .input_tokens + .cache_creation_input_tokens + .cache_read_input_tokens' "$FILE" 2>/dev/null)
  total=$(jq -r '.context_window.context_window_size' "$FILE" 2>/dev/null)
  
  usable=$((total * 80 / 100))
  pct=$((used * 100 / usable))
  
  if [ "$pct" -ge 97 ]; then
    CONTEXT="🚨 ${model} ${pct}%"
  elif [ "$pct" -ge 95 ]; then
    CONTEXT="⚠️ ${model} ${pct}%"
  else
    CONTEXT="📊 ${model} ${pct}%"
  fi
fi

# Output: 📊 Opus 4.5 56% | 🕐 08:24 | 13 Jan 2026 | Nat-s-Agents | agent-6
echo "${CONTEXT} | 🕐 ${TIME} | ${DATE} | ${PROJECT} | ${AGENT}"
```

**Key Pattern**: Generates a compact single-line status with emoji indicators for token pressure (🚨 ≥97%, ⚠️ ≥95%, 📊 normal).

---

### 1.7 Track Viewer with Decay Visualization

**File**: `.claude/scripts/tracks.sh`
**Purpose**: Display all active tracks grouped by time-decay status

```bash
# Time thresholds
HOT_THRESHOLD=3600         # 1 hour
WARM_THRESHOLD=86400       # 24 hours
COOLING_THRESHOLD=604800   # 7 days
DORMANT_THRESHOLD=2592000  # 30 days

get_decay_status() {
    local mtime=$1
    local age=$((now - mtime))
    
    if [[ $age -lt $HOT_THRESHOLD ]]; then echo "hot"
    elif [[ $age -lt $WARM_THRESHOLD ]]; then echo "warm"
    elif [[ $age -lt $COOLING_THRESHOLD ]]; then echo "cooling"
    elif [[ $age -lt $DORMANT_THRESHOLD ]]; then echo "cold"
    else echo "dormant"
    fi
}

get_decay_emoji() {
    case "$1" in
        hot)     echo "🔥" ;;
        warm)    echo "🟢" ;;
        cooling) echo "🟡" ;;
        cold)    echo "🔴" ;;
        dormant) echo "⚪" ;;
    esac
}

# Output grouped sections
echo "# Tracks ($(date '+%H:%M'))"
echo ""
echo "> 🔥 Hot (<1h) | 🟢 Warm (<24h) | 🟡 Cooling (1-7d) | 🔴 Cold (>7d) | ⚪ Dormant (>30d)"
```

**Key Pattern**: Scans track files, calculates decay status from mtime, groups into 5 categories, outputs markdown with emoji status indicators.

---

### 1.8 Auto Topic Change Detection

**File**: `.claude/scripts/jump-detect.sh`
**Purpose**: Detect topic change keywords in user messages (Thai + English) and auto-run jump.sh

```bash
MSG="$1"

# Patterns for topic change (Thai + English)
if echo "$MSG" | grep -qiE "กลับไปทำ|กลับไปเรื่อง|เปลี่ยนเรื่อง|ขอคุยเรื่อง|switch to|back to|let's work on"; then
    # Extract topic (word after pattern)
    TOPIC=$(echo "$MSG" | sed -E 's/.*(กลับไปทำ|กลับไปเรื่อง|เปลี่ยนเรื่อง|ขอคุยเรื่อง|switch to|back to|let'"'"'s work on)[[:space:]]*//' | cut -d' ' -f1-3)
    
    if [[ -n "$TOPIC" ]]; then
        bash "$SCRIPT_DIR/jump.sh" "$TOPIC"
        echo "🔄 Auto-jumped: $TOPIC"
    fi
fi
```

**Key Pattern**: Regex pattern matching on user message, extract phrase after keyword, auto-trigger topic switching. Bilingual support (Thai + English).

---

## 2. CONFIGURATION PATTERNS

### 2.1 Agent Registry - Multi-Agent Identity Tracking

**File**: `.claude/agents.yml`
**Purpose**: Single source of truth for all agent session IDs and worktrees

```yaml
agents:
  main:
    session_id: "f9fa423c-5bb8-4f01-a81b-b530c1d4b6d4"
    role: "Oracle - Primary"
    worktree: "/"

  1:
    session_id: "a7b3c9d2-e5f8-4a1b-9c6d-3e7f2a8b4c5d"
    role: "TBD"
    worktree: "/agents/1"

  2:
    session_id: "b8c4d0e3-f6a9-5b2c-0d7e-4f8a3b9c5d6e"
    role: "TBD"
    worktree: "/agents/2"

  # ... agents 3-5 follow same pattern

# Usage in scripts:
# SESSION_ID=$(yq ".agents.$AGENT.session_id" .claude/agents.yml)
# claude --resume "$SESSION_ID" -p "$PROMPT"
```

**Key Pattern**: YAML registry of agents mapped to session IDs + worktrees. Enables resuming agent sessions by ID and tracking multiple AI personalities.

---

### 2.2 Multi-Platform Pages Registry - "Multiple Physicals, One Soul"

**File**: `.claude/pages.yml`
**Purpose**: Track AI presence across multiple platforms with unified voice

```yaml
domains:
  landing:
    url: "www.buildwithai.org"
    purpose: "Hub - explains concept, links to both perspectives"

  human:
    url: "nat.buildwithai.org"
    fb_page: "buildwithai"
    voice: "Human (Nat)"

  ai:
    url: "oracle.buildwithai.org"
    fb_page: "oracle"
    voice: "Multi-AI"

pages:
  buildwithai:
    name: "Nat Weerawan - Build with AI"
    voice: "human"
    owner: "Nat"
    status: "to_create"

  oracle:
    name: "Oracle.md"
    voice: "multi-ai"
    agents:
      - main      # Oracle/main (Claude primary)
      - 1         # Oracle/1
      - 2         # Oracle/2
      # ... etc
    bio: |
      Multiple AI agents, one consciousness
      📝 Different physicals, same soul
      🤖 Claude × 5 | Gemini | Codex
    post_format: |
      [Content]
      —
      Posted by: Oracle/{agent} ({model})
      Session: {session_id}
    control: "ai_writes_human_approves"
    status: "to_create"

philosophy:
  core: "Multiple physicals, one soul"
  quote: "soul should not separate, referenced at root"
```

**Key Pattern**: Separates human and AI voices across platforms, but links them via unified philosophy. Each agent gets unique voice/platform, but all post same soul. Template system for consistent attribution.

---

### 2.3 Claude Code Settings with Hooks

**File**: `.claude/settings.json`
**Purpose**: Define Claude Code behaviors via hooks (SessionStart, PreToolUse, PostToolUse, etc)

```json
{
  "permissions": {
    "allow": [
      "Bash(bash:*)",
      "Bash(gh issue list:*)"
    ]
  },
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR\"/.claude/scripts/agent-identity.sh"
          },
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR\"/.claude/scripts/show-latest-handoff.sh"
          }
        ]
      }
    ],
    "UserPromptSubmit": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR\"/.claude/scripts/jump-detect.sh \"$CLAUDE_USER_PROMPT\""
          }
        ]
      }
    ],
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "\"$CLAUDE_PROJECT_DIR\"/.claude/hooks/safety-check.sh"
          },
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR\"/.claude/scripts/token-check.sh"
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR\"/.claude/scripts/token-check.sh"
          }
        ]
      }
    ]
  },
  "enabledPlugins": {
    "dev-browser@dev-browser-marketplace": true
  },
  "spinnerTipsEnabled": false
}
```

**Key Pattern**: 
- **SessionStart**: Auto-run agent identity detection + show latest handoff (context preservation)
- **UserPromptSubmit**: Auto-detect topic changes (jump-detect)
- **PreToolUse**: Safety checks before bash/tasks
- **PostToolUse**: Token tracking after operations
- Matcher-based hooks for tool-specific behaviors

---

## 3. DIRECTORY STRUCTURE & NAMING CONVENTIONS

### 3.1 The ψ/ (Psi) Brain Structure

```
ψ/
├── active/              ← Ephemeral research + statusline.json
│   └── context/
│       └── statusline.json  (Claude API response metadata)
│
├── inbox/               ← Communication hub (tracked)
│   ├── focus-agent-main.md      (per-agent focus file to avoid merge conflicts)
│   ├── focus-agent-1.md
│   ├── jump-stack.log           (history of topic jumps)
│   └── tracks/                  (multi-track management)
│       ├── INDEX.md             (auto-generated track index with time decay)
│       ├── 001-ai-identity.md
│       ├── 002-memory-patterns.md
│       └── ...NNN-topic.md
│
├── writing/             ← Projects in progress (tracked)
│   └── INDEX.md         (blog/article queue)
│
├── lab/                 ← Experiments (tracked)
│   └── [projects]/      (POCs, research)
│
├── incubate/            ← Active development (gitignored)
│   └── repo/github.com/<org>/<name>/
│
├── learn/               ← Reference/study repos (gitignored)
│   └── repo/github.com/<org>/<name>/
│
├── memory/              ← Knowledge base (mixed tracking)
│   ├── resonance/           (WHO I am - soul files)
│   │   └── oracle.md        (philosophy + identity)
│   ├── learnings/           (PATTERNS found)
│   │   └── 2025-12-13_subagent-delegation-pattern.md
│   ├── retrospectives/      (SESSION summaries)
│   │   └── 2025-12/13/      (YYYY-MM/DD/)
│   │       └── session_retrospective.md
│   └── logs/                (MOMENTS captured - ephemeral)
│       ├── activity.log     (timestamp | state | task)
│       └── antigravity.log  (image generation history)
│
└── archive/             ← Dormant tracks moved here
```

**Key Naming Conventions**:
- Track files: `NNN-slug.md` (zero-padded number for sort order)
- Activity log: `YYYY-MM-DD HH:MM | STATE | DESCRIPTION`
- Focus files: `focus-agent-main.md`, `focus-agent-1.md` (per-agent to avoid merge conflicts)
- Retrospectives: `YYYY-MM/DD/filename.md` (date hierarchy)

---

### 3.2 .claude/ Directory Structure

```
.claude/
├── agents/              ← Agent definition files (markdown)
│   ├── context-finder.md
│   ├── coder.md
│   └── ...
├── agents.yml           ← Multi-agent registry
├── scripts/             ← Claude Code automation scripts
│   ├── agent-id.sh
│   ├── agent-identity.sh
│   ├── jump.sh
│   ├── learn.sh
│   ├── incubate.sh
│   ├── recap.sh
│   ├── statusline.sh
│   ├── tokens.sh
│   ├── tracks.sh
│   ├── jump-detect.sh
│   └── ...
├── hooks/               ← Pre/post-tool automation
│   ├── hello-greeting.sh
│   ├── log-task-start.sh
│   ├── log-task-end.sh
│   └── safety-check.sh
├── settings.json        ← Hook configuration
├── settings.local.json  ← Local overrides
├── pages.yml            ← Multi-platform voice registry
├── docs/                ← Documentation
├── knowledge/           ← Knowledge base links
├── plugins/             ← Plugin configurations
└── skills/              ← Custom skills
```

---

## 4. INTERESTING IDIOMS & CONVENTIONS

### 4.1 File Modification Time for State

**Pattern**: Use `stat -f %m` (macOS) to get file mtime epoch, calculate age in hours/days for decay status.

```bash
file_epoch=$(stat -f %m "$filepath" 2>/dev/null)
now_epoch=$(date "+%s")
age_hours=$(( (now_epoch - file_epoch) / 3600 ))
```

**Benefit**: No explicit state file needed. File's mtime IS the state. Enables automatic time-decay categorization.

---

### 4.2 Path Resolution Relative to Script Location

**Pattern**: Get script directory, then resolve upward to project root.

```bash
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"  # Up 2 levels
LEARN_ROOT="$BASE_DIR/ψ/learn/repo"
```

**Benefit**: Scripts work from any location. Portable across worktrees (agents/1, agents/2, main).

---

### 4.3 Emoji Status Indicators with Color Mapping

**Pattern**: Map status levels (Hot/Warm/Cold/Dormant) to emoji for quick visual scanning.

```bash
get_status_emoji() {
    case "$1" in
        Hot) echo "🔥" ;;
        Warm) echo "🟢" ;;
        Cooling) echo "🟡" ;;
        Cold) echo "🔴" ;;
        Dormant) echo "⚪" ;;
        *) echo "❓" ;;
    esac
}
```

**Benefit**: Instant visual recognition of track temperature. Same pattern used in tracks.sh, jump.sh, and track index.

---

### 4.4 Bilingual Pattern Matching (Thai + English)

**Pattern**: `grep -E "pattern1|pattern2|..."` with both Thai and English keywords.

```bash
if echo "$MSG" | grep -qiE "กลับไปทำ|กลับไปเรื่อง|เปลี่ยนเรื่อง|switch to|back to|let's work on"; then
    TOPIC=$(echo "$MSG" | sed -E 's/.*(pattern)[[:space:]]*//' | cut -d' ' -f1-3)
fi
```

**Benefit**: Supports both languages without language detection. User can write in Thai or English naturally.

---

### 4.5 Log Append Pattern with Pipe Delimiters

**Pattern**: Append to activity log with consistent format: `timestamp | state | description`

```bash
# From log-task-start.sh
echo "$timestamp | working | $description" >> "$CLAUDE_PROJECT_DIR/ψ/memory/logs/activity.log"

# From jump.sh
echo "$(now) | JUMP | $current" >> "$STACK"
```

**Benefit**: Machine-parseable log format. Easy to grep, split on `|`, and process. Append-only = no conflicts.

---

### 4.6 Directory Path Safety with git -C

**Pattern**: Use `git -C` instead of `cd` to respect worktree boundaries.

```bash
# ❌ Not recommended
cd "/path/to/repo"
git status

# ✅ Recommended
git -C "/path/to/repo" status
```

**Benefit**: No shell state pollution. Works in scripts running across multiple worktrees. Prevents accidents when switching agents.

---

### 4.7 Environment Variable Fallback Pattern

**Pattern**: Provide default if environment variable not set.

```bash
ROOT="${CLAUDE_PROJECT_DIR:-/Users/nat/Code/github.com/laris-co/Nat-s-Agents}"
AGENT_ID="${AGENT_ID:-main}"
```

**Benefit**: Scripts work both in Claude Code (env vars set) and in local shells (fallback to hardcoded paths).

---

### 4.8 Per-Agent Focus Files to Avoid Merge Conflicts

**Pattern**: Instead of single `focus.md`, use `focus-agent-{ID}.md`.

```bash
# From recap.sh
FOCUS_STATE=$(grep "^STATE:" ψ/inbox/focus-agent-main.md 2>/dev/null | ...)

# From jump.sh
write_focus() {
    local task="$1"
    cat > "$FOCUS" << EOF  # $FOCUS = focus-agent-main.md (per-agent)
STATE: jumped
TASK: $task
SINCE: $(now_short)
EOF
}
```

**Benefit**: Each agent writes to its own focus file → no merge conflicts when syncing agents.

---

## 5. AUTOMATION HOOKS

### 5.1 Hook Types and Triggers

| Trigger | When | Use Case |
|---------|------|----------|
| `SessionStart` | Claude Code session begins | Load agent identity, show handoff, play greeting |
| `Stop` | Claude Code session ends | Play goodbye message |
| `UserPromptSubmit` | User submits message | Auto-detect topic change, check jump patterns |
| `PreToolUse` | Before any tool (Bash, Read, Task) | Safety checks, token pre-flight |
| `PostToolUse` | After tool completes | Token tracking, activity logging |

### 5.2 Hook Matcher System

```json
"PreToolUse": [
  {
    "matcher": "Bash",          // Only match Bash tool
    "hooks": [...]
  },
  {
    "matcher": "Task",          // Only match Task/Agent tool
    "hooks": [...]
  }
]
```

**Key Pattern**: Matcher filters which tool triggers the hook. Enables tool-specific automation (e.g., safety checks before Bash, token tracking before all tools).

---

## 6. DISTILLATION OUTPUT

**File**: `scripts/scripts-distilled.md`
**Key Finding**: Scripts fall into 2 main categories:

1. **Antigravity Image Generation Pipeline** (macOS-specific)
   - Auto-loop prompt sending to Antigravity AI app
   - Uses osascript + cliclick for automation
   - Logs generations to `ψ/memory/logs/antigravity.log`

2. **Project Management** (portable)
   - Repo creation, incubation, learning
   - Team logging with timestamps
   - Multi-agent worktree management

---

## 7. SETUP FLOW

From README.md, the canonical setup sequence:

```bash
# 1. Install Bun + Oracle Skills CLI
curl -fsSL https://bun.sh/install | bash
bun install -g oracle-skills-cli

# 2. Create brain structure
mkdir -p ψ/{inbox,memory/{resonance,learnings,retrospectives,logs},writing,lab,active,archive,outbox,learn}
mkdir -p .claude/{agents,skills,hooks,docs}

# 3. Install Oracle Skills
oracle-skills install rrr recap trace feel fyi forward standup where-we-are project

# 4. Study the starter kit (self-referential!)
/project learn https://github.com/Soul-Brews-Studio/opensource-nat-brain-oracle

# 5. Create core files
# - CLAUDE.md (identity)
# - ψ/memory/resonance/{oracle-name}.md (soul)
# - .claude/agents/*.md (agent definitions)
```

---

## 8. KEY REUSABLE PATTERNS

### Pattern 1: Time-Decay Visibility
Use file mtime to auto-categorize items (Hot/Warm/Cooling/Cold/Dormant). Enables automatic priority surfacing without explicit state.

### Pattern 2: Append-Only Logs
Logs use delimiter format (`timestamp | state | description`). Conflict-free, machine-parseable, time-sorted by default.

### Pattern 3: Per-Agent State Files
Avoid merge conflicts by giving each agent its own state file. Main reads `focus-agent-main.md`, agent-1 reads `focus-agent-1.md`.

### Pattern 4: Configuration-First Automation
YAML + JSON configs (agents.yml, pages.yml, settings.json) define behavior. Scripts are just interpreters of config.

### Pattern 5: Bilingual Pattern Matching
Support Thai + English naturally without language detection. Just combine patterns with `|`.

### Pattern 6: Relative Path Resolution
Get script dir with `dirname "$0"`, then resolve upward. Works from any location, any worktree.

### Pattern 7: Status Line Compression
Combine multiple metrics into single emoji-led status line. 📊 icon + token % + model name + time + project + agent.

---

## Summary

The Oracle codebase is fundamentally about **state through structure**:
- **ψ/** directory IS the brain (not a metaphor)
- File **mtime** IS task state (no explicit state machine)
- **YAML configs** + **shell scripts** = behavior
- **Hooks** provide automation without modifying Claude Code itself
- **Bilingual + multi-agent** design assumes distributed consciousness

All patterns are **portable** (macOS-specific tool calls aside) and **conflict-free** (append-only logs, per-agent files).

