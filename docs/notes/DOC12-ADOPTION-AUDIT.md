# PLAN-OPS-1 — Doc 12 Adoption Audit & Session Plan

**Session:** PLAN-OPS-1 (DOCS-ONLY — analysis output only)
**Date:** 2026-07-15
**Author:** planning pass
**Status:** **RESOLVED — DECISION V (ARCHITECTURE_DECISIONS.md v1.20, 2026-07-15).** Architect
ruled on all eleven flags; OPSC-S0 (docs-only) recorded DECISION V, ratified ADR-047/048/049/050/052/053
(ADR-051 HELD), updated Doc 11 / Doc 12 / CLAUDE.md, inserted OPSC-S0..S4 into §5b, and advanced the
STATUS.md pointer to OPSC-S1. **FLAG-PLANOPS1-1..11 are all RESOLVED** — see the per-flag resolutions in
§3 below. This note is retained as the audit record; the authoritative rulings live in DECISION V.

> **Resolution summary (DECISION V):** FLAG-1 A · FLAG-2 A · FLAG-3 B · FLAG-4 A · FLAG-5 A ·
> FLAG-6 A · FLAG-7 A · FLAG-8 C-modified · FLAG-9 B (ADR-051 HELD) · FLAG-10 A · FLAG-11 A.
> Plus the binding philosophy clause: the console is an observability/diagnostics interface, not an
> operational control plane.

**Subject:** `docs/12-admin-operations-console-architecture(2).md` (Doc 12, v1.0, Status: **Draft**)
vs. the frozen record (`docs/ARCHITECTURE_DECISIONS.md` v1.19, `CLAUDE.md`, `docs/IMPLEMENTATION_PLAN.md`).

> **Precedence reminder (ARCHITECTURE_DECISIONS.md line 3):** where Doc 12 (a Draft doc, Docs 1–11 tier)
> conflicts with `ARCHITECTURE_DECISIONS.md` or `CLAUDE.md`, the latter win. Doc 12 §21's claim that
> "Documents 1–12 constitute the frozen Architecture v1.0 baseline" is **aspirational** — Doc 12 is
> Status: Draft and has not been ratified into the authoritative record. This audit exists to gate that
> ratification.

---

## 1. Conflict Audit — every Doc 12 section vs the frozen docs

Legend: **CONSISTENT** = adoptable as-is under existing authority · **NEEDS-RULING** = not prohibited
but requires an architect decision to unblock (missing authority) · **CONFLICT** = contradicts a frozen
ruling and cannot be adopted without amending the frozen doc.

| Doc 12 § | Claim / feature | Verdict | Citation & reasoning |
|---|---|---|---|
| §1 Purpose — "Core owns infrastructure; modules own implementations; registry/provider-driven; no module→module deps; future extraction possible" | Architectural framing | **CONSISTENT** | Mirrors CLAUDE.md Rules 5 & 6 and the core/module split. No conflict at the principle level. Conflicts are in the *concrete* sections below. |
| §2 Roadmap Alignment — "Document 11 **is updated to include** … Phase 1A – Expanded – Operations Console" | Factual claim about Doc 11 | **CONFLICT (factual)** | **False against the tree.** `grep` of `docs/11-…md` for `Phase 1A-Expanded` / `Operations Console` / `Expanded` → **zero matches**. Doc 11 was never updated. Doc 12 asserts a roadmap state that does not exist. → **FLAG-PLANOPS1-1**. |
| §3 Core Operations Module — new `Core/Operations/{Admin,Assets,Contracts,Registries,Providers,Services,Diagnostics,UI}` | New core subtree | **NEEDS-RULING (folder) + CONFLICT (casing/stack)** | CLAUDE.md folder structure lists `core/` (lowercase) as "Infrastructure only — contracts, DI container, events, queue, workers, delivery adapters, reconciliation, security, observability." `Operations/` is **not** enumerated; Doc 2 (folder-structure authority) has **zero** mention of `Core/Operations/`, React, node, npm, or a build toolchain (verified). Adding an admin/UI/assets subtree to `core/` is a **new stack decision** (see §3/Assets + §10 React). Also note casing: Doc 12 writes `Core/` (uppercase dir) whereas the repo/namespace convention is `core/` (lowercase dir, `HSP\Core\` namespace). Additionally, placing **operations contracts** under `Core/Operations/Contracts/` (not `core/Contracts/`) collides with **CLAUDE.md Rule 5** ("Modules depend on `core/Contracts/` only") once modules provide widgets/diagnostics/metrics against those contracts (see §19). → **FLAG-PLANOPS1-2**, **FLAG-PLANOPS1-3**, **FLAG-PLANOPS1-11**. |
| §4 Operations Registry (Page/Nav/Widget/Action/Asset registries) | Registry-driven discovery | **CONSISTENT (pattern)** / **NEEDS-RULING (scope)** | Registry-driven discovery is consistent with the platform's explicit-registration model (Doc 8 / event & adapter registries; ADR-052/048 in Doc 12). No frozen conflict. Building it is net-new scope with no session — needs a Session-Map row (proposed §2). |
| §5 Registries vs Providers (Health/Metrics/Worker Status/Queue Status/Endpoint Providers) | Provider pattern for runtime data | **CONSISTENT (pattern)**; **Metrics Provider → see §12** | The provider abstraction is consistent. The **data behind** Metrics/Health/Worker/Queue providers must be sourced per DECISION Q (derived-on-demand, no persistence) — that constraint is carried at §11/§12 below. |
| §6 Navigation — MVP = {Operations, API Playground}; Future = Dashboard/Health/Replay/Reconciliation/Logs/Settings/Module Mgmt/Audit | Nav surface | **CONSISTENT (MVP subset)** | MVP nav is intentionally minimal (2 items). The "Future" list is explicitly future — not in scope. Proposed OPSC track is scoped to MVP nav only, per DoD item 2. |
| §7 Widget Architecture (core owns infra, modules provide widgets, "widgets never poll independently") | Widget model | **CONSISTENT** | Fits Rule 5. "Never poll independently" defers to §8 Refresh Coordinator — consistent with DECISION Q (no per-widget metric sinks). |
| §8 Refresh Coordinator | Centralized refresh scheduling | **CONSISTENT** | Read-path aggregation over providers. No frozen conflict, provided the underlying reads honor the connection topology (§ below). |
| §9 Console State Store (UI state SoT, provider cache) | UI-side cache | **CONSISTENT** | UI-layer cache of provider output. Not a persistence store → does not trip DECISION Q. Must not become a server-side metrics store (would trip Q). |
| §10 Operations Service Layer — "React UI → Operations Services → Provider Interfaces → Infrastructure" | React frontend + service layer | **CONFLICT (stack) + NEEDS-RULING (coding standard)** | (a) **React** introduces a **node/JS build toolchain** and shipped JS/TS assets under `core/` — a **new tech-stack decision** absent from CLAUDE.md "Tech Stack & Versions" (which lists PHP/WP/PG/PHPUnit only) and Doc 2. → **FLAG-PLANOPS1-3**. (b) The service layer is a **WP-admin boundary** → the **coding-standard TBD** bites here: CLAUDE.md "Coding Standard TBD"; IMPLEMENTATION_PLAN §3 says PSR-12 + WPCS security rules at the WP boundary **but "Do not enforce either standard until confirmed."** An admin UI is exactly the boundary DECISION S clause (d) deliberately **avoided** by shipping WP-CLI-only ("sidesteps the still-TBD WPCS/coding-standard decision at the WP admin boundary"). → **FLAG-PLANOPS1-4**. |
| §11 Diagnostics (Health/Metrics/Config/Env/Version/Warnings/Recommendations; severity OK…Critical); **"Historical health storage is intentionally out of scope … reports current operational state only"** | Diagnostics providers, current-state only | **CONSISTENT** | Explicitly current-state-only — aligns with DECISION Q (no persistence) and DECISION P (single current-state heartbeat row, no history). This section is well-aligned. |
| §12 Metrics — "first-class metrics": Queue Depth, Worker Count, Worker Heartbeat, Retry Count, Failed Jobs, Processing Rate, Replay Progress, Reconciliation Status, API Availability | Metrics catalog | **CONSISTENT (with binding constraint) + NEEDS-RULING (2 items)** | Must be served per **DECISION Q**: derived-on-demand PG aggregates (queue depth, worker count from `system.worker_heartbeats`, failed/DLQ depth) + structured-log runtime counters — **no metrics table, no rollups**. "Processing Rate" and "Replay Progress" imply **rate/progress over time**, which DECISION Q does not persist (runtime counters are log-only; there is no time-series store). Serving these as first-class live tiles needs a ruling: compute-from-logs? point-in-time only? → **FLAG-PLANOPS1-5**. "Reconciliation Status" maps to DECISION U outputs (no persisted status surface exists yet — same concern). |
| §13 System Information (Platform/WP/PHP/PG version, modules, module versions, queue provider, migration version, worker info) | Static introspection | **CONSISTENT** | Reads `system.module_versions`, `system.schema_versions`, env — all existing. No persistence added. |
| §14 Module Inspector (version/events/endpoints/transformers/adapters/workers/diagnostics/metrics/actions per module) | Module introspection | **CONSISTENT** | Discovery over the module registry (OPEN-9 union shape). Read-only. |
| §15 API Playground (Endpoint Explorer/Request Builder/Execution/Response Viewer; endpoint metadata; Display Categories incl. Commerce/Search) | Delivery-API explorer | **CONSISTENT (MVP) + scope note** | In-scope MVP nav item. Serves the six `hsp/v1` endpoints (DECISION N/F). Display Categories "Commerce/Search" are placeholders for out-of-MVP domains — must not pull WooCommerce/OpenSearch into scope (IMPLEMENTATION_PLAN §1 Out-of-Scope). "Request Execution Service" hitting the live delivery API from wp-admin is a read path — consistent with Rule 6 (consumers use the API contract). |
| §16 Operational Events (Worker Started/Stopped, Queue Backlog Changed, Replay Completed, Module Registered; "consume events rather than polling") | Event-driven console updates | **NEEDS-RULING** | No event bus for *operational* signals exists. "Worker Started/Stopped/Replay Completed" are not emitted anywhere today (heartbeat is current-state upsert, DECISION P; there is no operational-event stream). Building one is net-new and unspecified. MVP can fall back to Refresh Coordinator polling (§8). Deferring event-consumption keeps this out of the MVP critical path. → note under proposed track (non-blocking). |
| §17 Operational Actions — Replay, Reconciliation, **Flush Queue**, **Restart Workers**, Validate Configuration; each with Required Capability / Confirmation / Destructive flag; "actions execute only through Operations Services" | State-changing operator actions | **CONFLICT (2 actions) + CONSISTENT (routing) + NEEDS-RULING (auth boundary)** | **Replay** → must route to `ReplayService`/`ReplayWorkerStrategy` (DECISION T/S). **Reconciliation** → must route to `ReconciliationService`/`ReconciliationWorkerStrategy` (DECISION U). Doc 12's "actions execute only through dedicated Operations Services … UI must never invoke infrastructure directly" is **consistent** *iff* those services delegate to the ratified primitives and **never** open a second path (DECISION T point 5 / DECISION U point 2: "never inventing a second repair path"). → carried as a binding constraint, **FLAG-PLANOPS1-6**. **Flush Queue** → **CONFLICT** with CLAUDE.md anti-pattern "Silently drop a failed sync (failed events go to DLQ; replays are always possible)" and "Never lose a sync" (DECISION 1). A destructive queue flush that discards pending/failed jobs violates at-least-once. → **FLAG-PLANOPS1-7**. **Restart Workers** → **CONFLICT (feasibility)** with the runtime model: CLAUDE.md + IMPLEMENTATION_PLAN §4 state workers run under **systemd/Supervisor/container**; WP-Cron is fallback only. A wp-admin PHP request cannot restart a systemd-managed process — it has no supervisor control. → **FLAG-PLANOPS1-8**. |
| §18 Notification Providers (Queue Threshold, Worker Offline, Replay Completed, Migration Available, Config Warning) | Transient notifications | **CONSISTENT (derived) / NEEDS-RULING (if push)** | If computed on-demand from existing state (queue depth, heartbeat age) → consistent with DECISION Q. "Worker Offline" = heartbeat-age query (DECISION P) — already proven in GATE-S3. Only conflicts if it requires a persisted notification store or an event stream (§16) — neither is owed at MVP. |
| §19 Extensibility (core owns infra/registries/services/aggregation/rendering; modules own pages/widgets/diagnostics/metrics/actions/endpoints) | Extension model | **NEEDS-RULING** | Modules "own pages/widgets/diagnostics/metrics/actions" — they must implement operations contracts. But Doc 12 §3 places those contracts under `Core/Operations/Contracts/`, **outside** `core/Contracts/`. **CLAUDE.md Rule 5** binds modules to depend on `core/Contracts/` **only**; a module importing `HSP\Core\Operations\Contracts\…` would import from outside `core/Contracts/`, breaking Rule 5 verbatim. → **FLAG-PLANOPS1-11**. "Rendering" in core is the React-shell concern → inherits the §10 stack ruling. |
| §20 ADR-047…ADR-053 | Seven new ADRs declared "Accepted" | **CONFLICT (unratified)** | ADR-047–053 appear **only** in Doc 12 (verified: sole file in repo outside vendor). They are **absent from `ARCHITECTURE_DECISIONS.md`**, which is the authoritative ADR/conflict-resolution record and the precedence winner (line 3). Prior ADR ceiling in the frozen docs is **ADR-046** (Doc 10 §30), so the *numbers* 047–053 do **not collide** — but marking them "Accepted" inside a **Draft** doc does not ratify them. Until they are entered in `ARCHITECTURE_DECISIONS.md` (with amendment-log rows) they are **not** binding and cannot be cited as authority by any build session. → **FLAG-PLANOPS1-9**. |
| §21 Summary — "Documents 1–12 constitute the frozen Architecture v1.0 baseline" | Ratification claim | **CONFLICT (status)** | Doc 12 is **Status: Draft**. It cannot self-declare itself frozen. Freezing is the architect's act (record it in `ARCHITECTURE_DECISIONS.md`). Rolled into **FLAG-PLANOPS1-9**. |

### 1a. Explicit coverage of the nine required checks (DoD item 1)

| # | Required check | Where covered | Verdict |
|---|---|---|---|
| i | DECISION S "no admin UI" + WPCS/PSR-12 TBD at WP-admin boundary | §10, §17 rows | **CONFLICT/NEEDS-RULING** — DECISION S clause (d) shipped CLI-only *specifically to sidestep* the coding-standard TBD; Doc 12 reintroduces the admin UI that DECISION S avoided. CLAUDE.md "Coding Standard TBD" + IMPLEMENTATION_PLAN §3 "do not enforce until confirmed" → **FLAG-PLANOPS1-4**. |
| ii | Flush Queue vs never-drop-a-sync anti-pattern | §17 row | **CONFLICT** — **FLAG-PLANOPS1-7**. |
| iii | Restart Workers under systemd/Supervisor | §17 row | **CONFLICT (feasibility)** — wp-admin cannot control a supervisor-managed process → **FLAG-PLANOPS1-8**. |
| iv | Which PG handle admin-request-path providers use vs DECISION L Ruling 0 | see §1b | **NEEDS-RULING** — **FLAG-PLANOPS1-10**. |
| v | DECISION Q vs Metrics Providers | §11, §12 rows | **CONSISTENT with constraint**; Processing Rate / Replay Progress / Reconciliation Status need a source ruling → **FLAG-PLANOPS1-5**. |
| vi | Replay/Reconciliation actions must invoke DECISION T/U services, never a second path | §17 row | **CONSISTENT iff constrained** → binding constraint **FLAG-PLANOPS1-6**. |
| vii | ADR-047–053 numbering collisions + absence from ARCHITECTURE_DECISIONS.md | §20 row | No numeric collision (ceiling was ADR-046); **absent/unratified** in the authoritative record → **FLAG-PLANOPS1-9**. |
| viii | Core/Operations/ + React assets vs CLAUDE.md folder structure + node build toolchain as a new stack decision | §3, §10, §19 rows | **NEEDS-RULING (folder) + CONFLICT (new stack)** → **FLAG-PLANOPS1-2** (folder), **FLAG-PLANOPS1-3** (stack), **FLAG-PLANOPS1-11** (operations contracts under `Core/Operations/Contracts/` vs Rule 5 `core/Contracts/`-only). |
| ix | Whether Doc 11 actually contains "Phase 1A-Expanded" as Doc 12 §2 claims | §2 row | **NO — it does not** (verified, zero matches) → **FLAG-PLANOPS1-1**. |

### 1b. Connection topology (DoD item 1 (iv)) — detail

DECISION L Ruling 0 (v1.16) **freezes the PostgreSQL topology at exactly four handles**: relay
(`outbox.connection.pgsql`), queue/worker runtime (`queue.connection.pgsql`), delivery
(`DatabaseConnectionInterface` singleton, `DeliveryServiceProvider`), dispatcher
(`dispatcher.connection.pgsql`). **"No fifth handle may ever be introduced without a new ADR."**

The Operations Console runs in the **wp-admin PHP request** (not the worker runtime). Its Health/Metrics/
Queue-Status/Worker-Status providers must **read** `system.queue_jobs`, `system.dead_letter_jobs`,
`system.worker_heartbeats`, and `content.*`. The frozen answer for read-path PG access is the **delivery
handle** (DECISION K: "delivery reads (REST query providers) … use exactly one dedicated
`DatabaseConnectionInterface`"). Whether the admin request may reuse the delivery singleton, or whether a
console read in the admin request context is a *new* consumer that risks a fifth handle, is **not settled**
by any frozen doc. Reusing the delivery `DatabaseConnectionInterface` (no new handle, no new `pg_*`
wrapper — DECISION E) is the topology-preserving path and should be the ruling, but it must be **ruled**,
not assumed. → **FLAG-PLANOPS1-10**.

---

## 2. Proposed Session Map rows (PROPOSED — draft text only, not applied)

Scoped to **Doc 12 MVP nav only** (§6 MVP = **Operations** + **API Playground**). Read-only console
first; state-changing actions gated behind the flags they depend on. IMPLEMENTATION_PLAN.md §5b format.
Track prefix: **OPSC-S\***. **No row may begin until its cited authority exists** — several are **BLOCKED**
pending the flags below.

> These rows presuppose the architect first (a) ratifies Doc 12 into the frozen record (ADR-047–053 →
> `ARCHITECTURE_DECISIONS.md`) and (b) resolves the stack/standard/topology flags. Row Authority columns
> cite the ruling that must exist to unblock them; where that ruling does not yet exist the row is
> **BLOCKED**.

| ID | Deliverable | In-scope paths | Authority | Definition of Done | Depends-on |
|---|---|---|---|---|---|
| **OPSC-S0** | **Adoption pre-work (docs-only).** Ratify Doc 12 into the frozen record: enter ADR-047–053 in `ARCHITECTURE_DECISIONS.md` with amendment-log rows; resolve the stack decision (React/node build toolchain), the WP-admin coding standard, the admin-request PG handle, and the operations-contracts location vs Rule 5; update Doc 11 to actually contain "Phase 1A-Expanded"; rename the canonical doc file. | `docs/ARCHITECTURE_DECISIONS.md`, `docs/11-…md`, `CLAUDE.md` (Tech Stack + Coding Standard), `docs/IMPLEMENTATION_PLAN.md` §5b, `docs/12-admin-operations-console-architecture(2).md` | Architect ruling on **FLAG-PLANOPS1-1…11** | ADR-047–053 recorded + versioned; Doc 11 §roadmap lists Phase 1A-Expanded; CLAUDE.md names the frontend stack + the confirmed coding standard at the admin boundary; DECISION for admin-request PG handle recorded; operations-contracts location ruled (per FLAG-PLANOPS1-11) and Doc 12 §3 tree reconciled; **canonical filename fixed — `docs/12-admin-operations-console-architecture(2).md` renamed to `docs/12-admin-operations-console-architecture.md`** (drop the `(2)` download-artifact suffix so the ratified doc has one canonical path) | — (architect only) |
| **OPSC-S1** | **Operations core scaffolding** — `Core/Operations/` subtree, Operations Registry (Page/Nav/Widget/Action/Asset), Provider contracts (Health/Metrics/Worker/Queue/Endpoint), Refresh Coordinator, Console State Store, Operations Service layer — **read-only, no UI actions**. | `core/Operations/{Contracts,Registries,Providers,Services}/` | ADR-047/048/052 (once ratified in OPSC-S0); CLAUDE.md Rule 5/6; OPEN-9 | Registries discover pages/widgets/providers via explicit registration (no reflection); providers resolve via constructor injection (ADR-012); unit tests for registry + provider contracts; **no** `core/` UI/React yet | OPSC-S0; **BLOCKED on FLAG-PLANOPS1-2** (subtree) **and FLAG-PLANOPS1-11** (operations-contracts location vs Rule 5 — determines whether contracts land in `core/Contracts/Operations/` or `core/Operations/Contracts/`) |
| **OPSC-S2** | **Diagnostics + Metrics providers (current-state, derived-only)** — Health, Worker Status, Queue Status, Metrics providers computed **on-demand per DECISION Q** (PG aggregates + structured-log counters); System Information (§13); Module Inspector (§14). No persistence, no rollups, no history. | `core/Operations/{Providers,Diagnostics,Services}/`, `modules/Content/Operations/` (module-provided diagnostics/metrics) | **DECISION Q** (no persistence); **DECISION P** (heartbeat current-state); OPEN-8 (`module_versions`/`schema_versions` reads); Rule 5 | Providers return live queue depth / DLQ depth / worker count / oldest-pending age from existing tables via the **delivery handle** (per OPSC-S0 topology ruling); zero new tables/columns; worker-offline = heartbeat-age query; integration test on live PG | OPSC-S1; **BLOCKED on FLAG-PLANOPS1-5** (Processing Rate / Replay Progress / Reconciliation Status source), **FLAG-PLANOPS1-10** (admin-request handle) **and FLAG-PLANOPS1-11** (module-provided diagnostics/metrics in `modules/Content/Operations/` import operations contracts — must resolve the Rule 5 location first) |
| **OPSC-S3** | **Operations Console UI shell (React) + Operations + API Playground pages** — MVP nav (§6): Operations dashboard (read-only widgets over OPSC-S2 providers) + API Playground (§15: Endpoint Explorer/Request Builder/Execution/Response Viewer over the six `hsp/v1` endpoints). | `core/Operations/{Admin,Assets,UI}/`, `resources/` (JS/TS + build config), `modules/Content/Operations/` (endpoint metadata) | ADR-047/052/053; **DECISION N/F** (endpoints); **stack ruling** (React/node) + **coding-standard ruling** at WP-admin boundary | wp-admin page renders the console; widgets read from providers (no direct infra calls — ADR-053); API Playground executes live GETs against `hsp/v1` and renders responses; build toolchain documented; no state-changing actions present | OPSC-S2; **BLOCKED on FLAG-PLANOPS1-3** (stack) **and FLAG-PLANOPS1-4** (coding standard) |
| **OPSC-S4** | **Operational Actions — Replay + Reconciliation ONLY** — registry-driven actions (§17) routed through Operations Services that delegate **exclusively** to `ReplayService`/`ReplayWorkerStrategy` (DECISION T/S) and `ReconciliationService`/`ReconciliationWorkerStrategy` (DECISION U). Capability check + confirmation + destructive flag per action. **Flush Queue and Restart Workers are explicitly excluded** pending their flags. | `core/Operations/{Services,Registries}/`, `modules/Content/Operations/` | **DECISION T** (replay re-emission), **DECISION S** (DLQ replay lifecycle), **DECISION U** (reconciliation re-emission), ADR-051/053; **binding: no second path** (DECISION T pt 5 / U pt 2) | Replay + Reconcile actions invoke the ratified services only (asserted by a write-spy: zero direct `content.*`/`system.*` writes on the action path, mirroring GATE-S3); capability + confirmation enforced; audit trail; integration test on live MySQL+PG | OPSC-S3; **CONSTRAINED by FLAG-PLANOPS1-6**; **Flush Queue BLOCKED on FLAG-PLANOPS1-7**, **Restart Workers BLOCKED on FLAG-PLANOPS1-8** (both out of this row until resolved) |

**Deferred out of the OPSC MVP track (non-blocking, recorded so they are not silently pulled in):**
Operational Events / event-driven console (§16 — no operational event stream exists; MVP uses Refresh
Coordinator polling), Notification push (§18 — derived-only at MVP), and all §6 "Future" nav
(Dashboard/Health/Replay/Reconciliation/Logs/Settings/Module Mgmt/Audit as standalone pages).

---

## 3. Flag list — one per conflict needing an architect ruling

Each flag: what it blocks + concrete options. None resolved here.

### FLAG-PLANOPS1-1 — Doc 12 §2 misstates Doc 11 (Phase 1A-Expanded not present)
**RESOLVED — DECISION V (Option A):** Doc 11 updated to actually add "Phase 1A – Expanded — Operations Console & Developer Experience" (§4 phase list + new §6b).
Doc 12 §2 says Doc 11 "is updated to include Phase 1A – Expanded." Verified false: zero matches in
`docs/11-…md`. **Blocks:** roadmap coherence; any session citing "Phase 1A-Expanded" as a roadmap phase.
**Options:** (A) Architect updates Doc 11 to actually add the Phase 1A-Expanded row (recommended — makes
the roadmap real). (B) Rescope: drop the "Expanded" phase name; fold the console into an existing phase
(e.g., Phase 3 Operational Hardening / Phase 1B). (C) Reword Doc 12 §2 to "Doc 11 *should be* updated"
and treat as a pending action.

### FLAG-PLANOPS1-2 — `Core/Operations/` subtree absent from CLAUDE.md/Doc 2 folder structure
**RESOLVED — DECISION V (Option A):** `core/Operations/` (lowercase) added to CLAUDE.md as core infrastructure; Doc 2's tree amended by the ruling (Doc 2 not edited).
CLAUDE.md folder structure and Doc 2 do not list an `Operations/` subtree under `core/` (and Doc 12 uses
`Core/` uppercase vs the repo's `core/`). **Blocks:** OPSC-S1 scaffolding. **Options:** (A) Amend CLAUDE.md
+ Doc 2 to add `core/Operations/` as infrastructure (recommended; lowercase to match convention). (B) Place
the console in a **module** (`modules/Operations/`) instead of core — but Doc 12 §1/§19 insist it is *core*
infrastructure, so this contradicts the doc's own thesis. (C) Reject the subtree; keep operations tooling
CLI-only (status quo per DECISION S).

### FLAG-PLANOPS1-3 — React / node build toolchain is a new, undeclared tech-stack decision
**RESOLVED — DECISION V (Option B):** MVP console is server-rendered PHP + minimal vanilla JS + WP native admin — NO node/npm/bundler. React deferred to a future ADR that must not alter the provider/registry architecture.
Doc 12 §10/§19 require a React UI → shipped JS/TS assets + a node/npm/bundler build under `core/`.
CLAUDE.md "Tech Stack & Versions" lists **only** PHP/WP/PostgreSQL/PHPUnit; Doc 2 has no frontend
toolchain. **Blocks:** OPSC-S3 (UI shell). **Options:** (A) Ratify a frontend stack (React + build tool +
pinned versions in `package.json`/CI) and record it in CLAUDE.md Tech Stack (recommended if a rich console
is wanted). (B) Build the MVP console as **server-rendered PHP + minimal vanilla JS** (no node toolchain) —
lower cost, honors "latest-stable PHP only," still delivers Operations + API Playground. (C) Defer the UI
entirely; ship providers + WP-CLI surface now (fully consistent with DECISION S), build UI post-MVP.

### FLAG-PLANOPS1-4 — WP-admin coding standard is still TBD (DECISION S deliberately avoided this)
**RESOLVED — DECISION V (Option A):** PSR-12 for all platform code; WPCS security requirements (escape/sanitize/capability/nonce) at WordPress entry points only. CLAUDE.md "TBD" hold lifted.
An admin UI + service layer is a WP-admin boundary. CLAUDE.md "Coding Standard TBD"; IMPLEMENTATION_PLAN
§3 names PSR-12 + WPCS security rules **but "do not enforce until confirmed."** DECISION S clause (d)
shipped CLI-only *precisely to sidestep* this. **Blocks:** OPSC-S3/S4 (any code at the admin boundary).
**Options:** (A) Architect confirms PSR-12 (internals) + WPCS security rules (sanitize/escape/nonce at the
admin boundary) and records it in CLAUDE.md/composer (recommended — unblocks all admin-boundary code). (B)
Keep CLI-only (no admin boundary), deferring the standard indefinitely. (C) Confirm a standard scoped only
to the Operations subtree.

### FLAG-PLANOPS1-5 — Metrics with a time dimension vs DECISION Q (no persistence)
**RESOLVED — DECISION V (Option A):** All console metrics derived on-demand per DECISION Q (processing rate / replay status / reconciliation status computed point-in-time from existing data); zero new persistence.
Doc 12 §12 lists "Processing Rate," "Replay Progress," "Reconciliation Status" as first-class metrics.
DECISION Q forbids a metrics/rollup/time-series store; runtime counters are **structured-log only**; there
is no persisted progress/status surface. **Blocks:** OPSC-S2 (these three tiles). **Options:** (A) Serve
them **point-in-time only** (e.g., processing rate = derived from a rolling query window over
`system.queue_jobs.processed_at`; replay/recon status = "last run" summary held in-memory/log-derived),
persisting nothing — recommended, stays within DECISION Q. (B) Amend DECISION Q to authorize a minimal
metrics/progress table (new frozen-schema decision — heavier). (C) Drop these three from MVP; show only
the DECISION-Q-native derived metrics (queue depth, DLQ depth, worker count, oldest-pending age).

### FLAG-PLANOPS1-6 — Operational Actions must not create a second replay/reconciliation path (binding constraint)
**RESOLVED — DECISION V (Option A):** Replay/Reconcile actions are thin delegators to ReplayService (T/S) + ReconciliationService (U); no second repair path; OPSC-S4 DoD requires a write-spy proof (zero direct content.*/system.* writes on the action path).
Doc 12 §17 Replay/Reconciliation actions. DECISION T point 5 and DECISION U point 2 mandate a **single**
repair primitive ("never inventing a second repair path"; repair = re-emission only, no direct PG writes).
**Blocks:** nothing if honored; **is a hard constraint** on OPSC-S4. **Options:** (A) Ratify that Operations
Services for Replay/Reconcile are **thin delegators** to `ReplayService`/`ReplayWorkerStrategy` and
`ReconciliationService`/`ReconciliationWorkerStrategy`, with a write-spy test proving zero direct
`content.*`/`system.*` writes (recommended — mirrors the GATE-S3 evidence). (B) N/A — any design that
writes projections directly from the console is prohibited outright.

### FLAG-PLANOPS1-7 — "Flush Queue" action vs never-drop-a-sync / at-least-once
**RESOLVED — DECISION V (Option A):** Flush Queue REMOVED from the action set. Any future queue maintenance must be replay-safe, never destructive deletion.
Doc 12 §17 lists Flush Queue as an operational action. CLAUDE.md anti-pattern: "Silently drop a failed sync
(failed events go to DLQ; replays are always possible)"; DECISION 1 "never lose a sync"; at-least-once
(Rule 4). A flush that discards pending/failed jobs violates all three. **Blocks:** Flush Queue in
OPSC-S4. **Options:** (A) **Drop Flush Queue** from the MVP action set (recommended). (B) Redefine it as a
**non-destructive** operation — e.g., "requeue timed-out jobs" (already `MaintenanceWorkerStrategy` /
DECISION R) or "move stuck jobs to DLQ" (preserves replayability) — never a discard. (C) Authorize a
narrowly-scoped destructive flush behind a hard capability + audit, with an explicit DECISION accepting the
data-loss semantics (not recommended; contradicts the platform's core guarantee).

### FLAG-PLANOPS1-8 — "Restart Workers" action is infeasible from a wp-admin request
**RESOLVED — DECISION V (Option C-modified):** No Restart Workers action. Console provides worker status, heartbeat, restart guidance, and runbook links only; worker lifecycle belongs to the process supervisor.
Doc 12 §17. Workers run under systemd/Supervisor/container (CLAUDE.md; IMPLEMENTATION_PLAN §4); WP-Cron is
fallback only. A wp-admin PHP request has no control over a supervisor-managed process. **Blocks:** Restart
Workers in OPSC-S4. **Options:** (A) **Drop Restart Workers** from MVP (recommended). (B) Reframe as a
**soft** action — request graceful shutdown via a DB/heartbeat flag the worker polls, then the supervisor
auto-restarts it (cooperative, not a real "restart"); requires a small worker-side contract + a DECISION.
(C) Provide it as documentation/runbook only (ops performs the restart at the supervisor), no console
action.

### FLAG-PLANOPS1-9 — ADR-047–053 declared "Accepted" in a Draft doc but absent from ARCHITECTURE_DECISIONS.md
**RESOLVED — DECISION V (Option B):** ADR-047/048/049/050/052/053 entered into ARCHITECTURE_DECISIONS.md and ratified; **ADR-051 (Operational Actions) recorded as HELD — not citable as authority** — pending incorporation of the FLAG-7 (no Flush Queue) and FLAG-8 (no Restart Workers) rulings into its text. Doc 12 §21 self-freeze removed; Doc 12 → Accepted (as amended by DECISION V).
ADR-047–053 exist only in Doc 12 (Draft). They are not in `ARCHITECTURE_DECISIONS.md`, the authoritative
record and precedence winner. Numbers don't collide (ceiling was ADR-046) but they are **unratified** —
no session may cite them as authority yet. Doc 12 §21's self-freeze is not binding. **Blocks:** all OPSC
rows that cite ADR-047+. **Options:** (A) Architect enters ADR-047–053 into `ARCHITECTURE_DECISIONS.md`
with amendment-log rows + Implications entries, promoting Doc 12 from Draft to Accepted (recommended — the
proper ratification path). (B) Ratify a **subset** (e.g., 047/048/052/053 for the read-only console) and
hold the action-related ADRs (051) pending FLAG-7/8. (C) Decline ratification; Doc 12 stays Draft and no
OPSC session starts.

### FLAG-PLANOPS1-10 — Which PG handle serves admin-request-path providers (vs DECISION L Ruling 0 four-handle freeze)
**RESOLVED — DECISION V (Option A):** Console providers reuse the delivery `DatabaseConnectionInterface` (DECISION K). Four-handle topology (DECISION L Ruling 0) unchanged; no fifth handle; no new `pg_*` wrapper (DECISION E).
The console runs in wp-admin (not the worker runtime) yet must read `system.*`/`content.*`. DECISION L
Ruling 0 freezes the topology at four handles — "no fifth without a new ADR." **Blocks:** OPSC-S2 (provider
reads). **Options:** (A) Rule that admin-request console reads **reuse the delivery
`DatabaseConnectionInterface`** (DECISION K), opening no new handle and no new `pg_*` wrapper (DECISION E) —
recommended, topology-preserving. (B) Authorize a **fifth** read-only console handle via a new ADR
(explicitly amends DECISION L Ruling 0). (C) Serve provider data **without** a direct admin-side PG
connection — proxy through the existing worker-runtime/delivery layer or the REST API (heaviest, but
keeps admin-side PHP free of PG entirely).

### FLAG-PLANOPS1-11 — Operations contracts under `Core/Operations/Contracts/` vs CLAUDE.md Rule 5 (`core/Contracts/`-only)
**RESOLVED — DECISION V (Option A):** Operations contracts live under `core/Contracts/Operations/` (a namespace under the existing contracts root); Rule 5 holds verbatim. Doc 12 §3's `Core/Operations/Contracts/` tree is superseded on this point.
Doc 12 §3 places operations contracts under `Core/Operations/Contracts/`, and §19 has **modules** own
"pages/widgets/diagnostics/metrics/actions" — i.e., modules must implement those contracts. **CLAUDE.md
Rule 5** binds modules to "depend on `core/Contracts/` **only**." A module-provided widget/diagnostic/metric
importing `HSP\Core\Operations\Contracts\…` imports from **outside** `core/Contracts/`, breaking Rule 5
verbatim. This is the OPSC-S2 in-scope path `modules/Content/Operations/` (module-provided diagnostics/
metrics) and the OPSC-S1 contracts location. **Blocks:** OPSC-S1 (where the contracts land) and OPSC-S2
(module implementations). **Options:** (A) Operations contracts live in **`core/Contracts/Operations/`**
(a namespace under the existing contracts root) so Rule 5 holds **verbatim**; Doc 12 §3's
`Core/Operations/Contracts/` tree is amended on ratification to point contracts at `core/Contracts/Operations/`
— **recommended** (no Rule 5 edit; keeps the single module→contracts dependency edge). (B) Amend **Rule 5**
to "modules depend on **core contracts** only" (looser wording that permits `core/Operations/Contracts/`);
requires a CLAUDE.md edit and widens the module→core surface. (C) Modules provide **no** console
implementations at MVP — a **core-only** console with no module-contributed pages/widgets/diagnostics/
metrics; this contradicts Doc 12 §19's extensibility thesis and defers the module extension seam.

---

## 4. Bottom line

Doc 12 is **not adoptable as-is.** It is a Draft that (a) misstates the roadmap (§2 vs Doc 11), (b)
declares seven ADRs "Accepted" that are absent from the authoritative record, (c) introduces two new
tech-stack/structure decisions (React/node toolchain; `core/Operations/` subtree) not present in CLAUDE.md
or Doc 2, (d) reopens the coding-standard TBD that DECISION S deliberately sidestepped, and (e) lists two
operational actions (**Flush Queue**, **Restart Workers**) that conflict with never-drop-a-sync and the
supervisor-managed worker model. Its **read-only** console core (§1,4,5,7–15,19) and its **Replay/
Reconciliation** actions are **adoptable once routed through the ratified DECISION T/U services** and once
the stack/standard/topology/ratification flags are resolved.

**Recommended sequence:** OPSC-S0 (architect ratifies Doc 12 → ADR-047–053 into
`ARCHITECTURE_DECISIONS.md`, resolves FLAG-1…10) **before** any OPSC build row. The proposed OPSC-S1…S4
track is scoped to Doc 12 MVP nav (Operations + API Playground), read-only first, with destructive/
infeasible actions excluded pending their flags.

**RESOLVED (2026-07-15) — architect ruled on all eleven flags; OPSC-S0 recorded DECISION V
(ARCHITECTURE_DECISIONS.md v1.20), ratified the ADR subset (ADR-051 HELD), updated Docs 11/12 +
CLAUDE.md, inserted OPSC-S0..S4 into §5b, and advanced the STATUS.md pointer to OPSC-S1.** The
read-only console core and the Replay/Reconcile actions (thin delegators to the DECISION T/U services)
are now adoptable; Flush Queue and Restart Workers are permanently excluded (FLAG-7/8). This note is
retained as the audit record; DECISION V is the authoritative ruling.
