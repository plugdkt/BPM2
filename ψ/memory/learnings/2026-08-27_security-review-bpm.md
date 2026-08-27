---
pattern: "BPM security review: app-layer defenses (CSRF, IDOR scoping, XSS escaping, row locking) were solid; the gap was infrastructure-layer — zero HTTP security headers"
date: 2026-08-27
source: "manual review + security-audit subagent"
concepts: ["security", "php", "iis", "headers", "csrf"]
---

# Security review of BPM (plugdkt/BPM2)

Full pass over CSRF, IDOR/department-scoping, XSS, SQL injection, session
timeout, row locking, and export filename handling. Verified independently
by manual read-through AND a separate subagent — both converged on the
same two real findings, everything else was already solid:

1. **No HTTP security headers anywhere** — no `X-Frame-Options`,
   `X-Content-Type-Options`, `Referrer-Policy`, no `web.config` equivalent
   either. This is the kind of gap that's easy to miss because it's not a
   "bug" in any single file — it's an absence across the whole app.
   Fixed by adding headers in the one file every page already requires
   first (`src/bootstrap.php`), rather than duplicating them per-page.

2. **Export filename built via raw string concatenation** into
   `Content-Disposition` — not currently exploitable (input was always a
   validated int), but sanitized as defense-in-depth since export helpers
   tend to get reused with less-constrained input later.

**Pattern worth remembering**: app-layer input handling (CSRF, prepared
statements, server-side ownership checks ignoring client-supplied filter
params) was already disciplined throughout — the actual gap was at the
infrastructure/transport layer, which a code-only review of individual
action handlers won't surface unless you specifically check for it.
