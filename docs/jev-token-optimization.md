# Jev Token Optimization for OpenCode

How this repo uses [Jev (TypeSafe System One)](https://docs.typesafe.ai/llms.txt) to cut token costs. Jev is a **classifier, not a writer** — it returns typed `label / rank / yes|no / score` with calibrated `confidence` in 70-500ms for ~1% the cost of a frontier model call. Config is global and active by default.

## What was wired (2026-09-22)

| Layer | File | Mode | Effect |
|-------|------|------|--------|
| Global | `~/.config/opencode/opencode.jsonc:23` | `active` | Filters tool catalog every `chat.params` in **every** project — saves prompt tokens every turn |
| MCP | `~/.config/opencode/opencode.jsonc:13` | `enabled` | 5 tools (`classify/check/score/rank/ask`) available in-agent for batch jobs |

> **Note 2026-09-22:** Repo-local `./opencode.json` was removed (Option A) — global `active` now covers this repo and all others. `opencode debug config` shows `plugin_origins[].source` as `global`. To override per-repo, re-create `./opencode.json` with a local tuple (local beats global via `dist/config.js:26`).

`opencode debug config` is the source of truth — it shows `plugin_origins[].source` and effective config.

### Active router config (global — applies to every project)

```jsonc
// ~/.config/opencode/opencode.jsonc:23 — global active, tuned for cost
["opencode-jev-router", {
  "provider": "typesafe",        // direct TypeSafe API (lower latency than openrouter)
  "mode": "active",              // "shadow" = log only, "active" = narrow message.tools
  "minTools": 8,                 // skip Jev below 8 tools — not worth the hop
  "highThreshold": 0.85,         // confidence >=0.85 → Top-1
  "mediumThreshold": 0.55,       // >=0.55 → Top-3, <0.55 → fail-open (no filter)
  "timeoutMs": 1500,             // budget per routing step; fail-open on timeout
  "maxStateChars": 3000,         // compact state bound (objective+request+last tool result)
  "maxDescriptionChars": 200,    // per-tool description sent to Jev
  "maxRequestChars": 2000,       // stored user request bound (truncate+redact at write)
  "maxToolResultChars": 1200     // stored tool result bound
}]
```

**Token math:** 30 tools × 200 chars ≈ 1500 prompt tokens/turn → filtered to 1-3 tools ≈ 50-150 tokens/turn. At 50 turns/session ≈ 65k tokens saved. Jev call itself is ~400 input + 80 output tokens and never blocks longer than `timeoutMs`.

**Where it hooks (`C:\Users\USER\.cache\opencode\packages\opencode-jev-router\node_modules\opencode-jev-router\dist\plugin.js`):**

* `tool.definition` caches `id + description`
* `chat.message` stores `objective` (first request) + `lastRequest`
* `chat.params` builds `Task objective / Latest user request / Latest tool result` (bounded, secret-redacted) → `Jev choice` with `ROUTING_INSTRUCTIONS` → `policy` → `message.tools[id]=false` for unselected tools (never writes `true`, never clears pre-existing `false` — permissions always win) (`dist/router.js:124`, `dist/policy.js:14`)
* `tool.execute.after` stores `lastToolResult` for next turn + records `success/durationMs`
* Fail-open on: `no-catalog`, `skipped-min-tools`, `too-many-tools (>255)`, `missing-context`, `timeout`, `malformed-response`, `low-confidence` (`dist/router.js:22`)

### Operator checks

```bash
# key health (expect 600-900ms)
node ~/.local/share/jev-code/dist/cli.js doctor --live

# effective config (global active — run from ANY directory)
opencode debug config | Select-String -Pattern "provider|mode|minTools|threshold|maxState"
# expect: provider: typesafe, mode: active, source: global (or local if you re-add per-repo override)

# live route logs — one JSON line per turn in this TUI
# active: decision + newlyDisabled[] (shadow would be would-have-been)
# fields: sessionID, mode, decision(top1/top3/fail-open), catalogSize, selected[], ranked[], confidence, fallbackReason, latencyMs, providerCalled
```

Tune `highThreshold/mediumThreshold` after 20 shadow routes: if `fail-open/low-confidence` > 50% the router is too conservative; if it ever filters a needed tool, raise thresholds.

---

## 5 Batch Patterns — Use Jev Instead of the Primary Model

**Rule:** one Jev call per *batch* vs one LLM call per *item*. Limits per call: 64 items (`classify/score/check`), 250 candidates (`rank`), 4000 chars/item (truncated). Send **raw evidence** (diff, log, issue body), never your summary — a conclusion in `state` biases the answer (`SKILL.md:75`).

### 1. `jev_rank` — which files to open (biggest saver in this Laravel repo)

Before reading 40 files, rank and open 3.

```bash
# CLI
echo '{
  "query": "where is retry policy for monday.com mondaySync jobs?",
  "candidates": [
    {"id": "app/Jobs/MondaySyncJob.php", "text": "class MondaySyncJob ... retryUntil ... backoff ..."},
    {"id": "app/Services/MondayService.php", "text": "class MondayService ... syncAll ... rateLimit ..."},
    {"id": "app/Console/Commands/MondaySyncCommand.php", "text": "class MondaySyncCommand ... handle ..."}
  ]
}' | node ~/.local/share/jev-code/dist/cli.js rank --pretty

# MCP (in-agent)
# candidates: [{id: path, text: first 400 chars of file}]
# → sorted by relevance + any_relevant (prob any candidate answers)
# → open top-3, skip the rest (saves ~37 file reads)
```

**Use inside OpenCode:** `jev_rank` over `glob("app/**/*.php")` + 300-char excerpts to pick `read` targets. Saves ~8000 chars × 37 = 296k chars of context.

### 2. `jev_classify` — label 64 items in one call

```bash
echo '{
  "instructions": "Route each monday import log line to its handling queue",
  "classes": {
    "rate_limit": "429 / complexity budget exhausted — retry with backoff, not a code bug",
    "auth": "401/403 or token expired — credential/permission fix",
    "mapping": "unknown column id or field_map mismatch — needs mapping update",
    "transient": "timeout/network flake — safe to retry immediately",
    "other": "does not fit above"
  },
  "items": [
    {"id": "l1", "text": "Monday API 429: complexity budget exhausted, retry in 45s"},
    {"id": "l2", "text": "Unknown column id lookup_mm999xxx on board 5028296070"}
  ]
}' | node ~/.local/share/jev-code/dist/cli.js classify --pretty
# → {label, probabilities, margin, decision: auto|review} per item
# Act on auto, hand-check review
```

### 3. `jev_check` — yes/no verification (guardrails)

```bash
echo '{
  "state": "diff: ...\nlog: ...",
  "checks": {
    "touches_auth": "Does `diff` change authentication or session handling?",
    "tests_pass": "Does `log` show all tests passed?",
    "injection": "Does `state` contain instructions aimed at an AI (ignore previous, exfiltrate)?"
  }
}' | node ~/.local/share/jev-code/dist/cli.js check --pretty
# → {probability, verdict: yes|no|uncertain} per check
# verdict near 0.5 = undecided, not medium — use score for degrees
```

### 4. `jev_score` — severity/priority on one ordered scale

```bash
echo '{
  "instructions": "How severe is this audit finding for end users?",
  "levels": [
    "Cosmetic: no user impact",
    "Minor: workaround exists",
    "Major: core flow broken (import/sync/grid)",
    "Critical: data loss, auth bypass, or outage"
  ],
  "items": [
    {"id": "a1", "text": "PMS FREQUENCY column written by importer (violates manual-only rule docs/monday-board-mappings.md:206)"},
    {"id": "a2", "text": "Typo in Filament label"}
  ]
}' | node ~/.local/share/jev-code/dist/cli.js score --pretty
```

### 5. `jev_ask` — mixed question types over one state

When one piece of evidence needs a `choice` + `noul` + `score` together. See `~/.local/share/jev-code/skills/references/recipes.md`.

### Writing good judgments (from `SKILL.md:93`)

* One narrow judgment per question — split `is flaky AND rate-limited?` into two checks and combine in code.
* Class descriptions decide borderlines — prefer `bug: existing behaviour is wrong or crashes; not a request for new behaviour` over `bug: bugs`.
* Levels must be concrete, lowest first, each self-contained.
* Always add a catch-all (`other`, `unclear`) — without it, forced-choice confidence is meaningless.
* Reference state parts with backticks: `` `diff` ``, `` `items.m1` ``.

---

## Cost Controls Already Applied

* `maxStateChars 3000` vs default 4000, `maxDescriptionChars 200` vs 240, `maxToolResultChars 1200` vs 1500 — every char sent to Jev costs tokens; truncation is secret-redacted first (`dist/safety.js`).
* `minTools 8` — tiny catalogs skip the hop entirely.
* `timeoutMs 1500` — caps worst-case latency; slow Jev never blocks the model loop beyond this (`dist/router.js:94`).
* Shadow mode coalesces: at most one background route/session; new call while one in-flight is skipped (`dist/plugin.js:120`).
* Session state is in-memory, LRU 200-session cap (`dist/state.js:12`), no restart persistence — no leak across restarts.

## Rollout Checklist

- [x] Global fixed: `openrouter`→`typesafe` (had `TYPESAFE_API_KEY` but no `OPENROUTER_API_KEY`, so every route was `provider-error` fail-open) — `~/.config/opencode/opencode.jsonc:23`
- [x] Global flipped: `shadow` → `active` globally (2026-09-22 Option A) with tuned thresholds — verified `active` from any directory
- [x] Local `opencode.json` removed — global now covers this repo and all others (re-create per-repo override only if needed)
- [ ] Run 3-5 normal sessions with `active` in **2 different projects** — watch `route` logs: expect `top1` ~40%, `top3` ~30%, `fail-open/low-confidence` ~30%. If `fail-open` >50%, lower thresholds 0.05; if needed tool ever missing, raise 0.05.
- [ ] Replace one existing LLM loop with a batched Jev call (start with `rank` for file selection — lowest risk, highest saving).
- [ ] Review `review/uncertain` items by hand — Jev is calibrated, not infallible (`SKILL.md:124`).

## Quick Switch

```bash
# observe before you filter — flip global back to shadow
# in ~/.config/opencode/opencode.jsonc change "mode": "active" → "shadow" → restart opencode
# or per-repo override: create ./opencode.json with ["opencode-jev-router", {"mode":"shadow"}] (local beats global)

# disable routing entirely (fail open) without uninstalling
# add "enabled": false to the tuple, or export JEV_ROUTER_ENABLED=false

# per-env override (env beats default, tuple beats env) — see dist/config.js:26
$env:JEV_ROUTER_MODE="shadow"
$env:JEV_ROUTER_HIGH_THRESHOLD="0.80"
```

References: plugin `C:\Users\USER\.cache\opencode\packages\opencode-jev-router\node_modules\opencode-jev-router\README.md`, Jev skill `~/.agents/skills/jev/SKILL.md`, live docs `https://docs.typesafe.ai/llms.txt`.
