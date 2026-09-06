# Performance & Scale Brief — HSP

**Status:** Derived digest — **NOT authoritative.** Every number below is cited to the frozen
document that owns it. On any conflict, the cited source wins, and
`docs/ARCHITECTURE_DECISIONS.md` wins over all of them.

**Purpose:** so a session can size a design decision for speed **without re-reading the PRD,
Doc 1, Doc 8, Doc 10 and Doc 11**. Read this first; open the cited section only when the number
itself is in dispute or you need the surrounding rationale.

**Last verified against the sources:** 2026-09-06 (FLAG-P1BS0-1 → DECISION AB — §3 cadence + SLA rewritten).

---

## 1. The two numbers that drive every design decision

| Target | Value | Source |
|---|---|---|
| **End-to-end sync latency** | **< 30 seconds** — a WordPress edit is visible through the Delivery API within 30s under normal operation | **PRD §Performance + §Success Criteria** (the owning source) and **Doc 10 §24** ("Sync Delay"); referred to as "the <30s sync SLA" in Doc 8 §10, DECISION L (g) and ADR-054 §4 |
| **Queue lag** | **< 60 seconds** during normal operation | Doc 10 §24 |

Everything else is a consequence of these two.

> **Citation warning.** Several places in the repo attribute the <30s SLA to **"Doc 11 §24"**
> (Doc 8 §10, DECISION L (g), `IMPLEMENTATION_PLAN.md` lines 203/264). **Doc 11 §24 is "Success
> Metrics" and contains no latency number** — it lists proof obligations (Content Module Proven,
> Replay Proven, …). The number lives in the **PRD** and **Doc 10 §24**. The stale cross-reference
> is recorded here rather than corrected in place: Doc 8, Doc 11 and DECISION L are frozen, so
> repointing them is a docs-reconciliation task, not a side effect of a build session. Cite the
> PRD and Doc 10 §24.

---

## 2. Scale targets

| Dimension | Target | Source |
|---|---|---|
| Content records | **100,000+** | PRD §Scalability; Doc 1 §3 |
| Products (Phase 2+) | **500,000+** | PRD §Scalability; Doc 1 §3 |
| Events/day | **1,000,000+** | PRD §Scalability; Doc 1 §3 |

Doc 1 §15 phases the same numbers: **Phase 1 = 10,000 content records / 50,000 products**;
Phase 2 = 100,000 / 500,000; Phase 3 = 1,000,000+ events/day. The platform must reach the top
numbers **without architectural redesign** — that phrase is the actual requirement. Design for
the shape that scales; do not pre-build for a million rows.

---

## 3. How speed is achieved — the ONE mechanism (ADR-054)

**Throughput is scaled by cron frequency and batch size. Nothing else.**
(Doc 8 v2.0 §10; ADR-054 §4; ALIGN-S0 Deliverable 5.)

- **No worker pool, no daemon, no supervisor, no "run N workers"** — that model is superseded.
  Overlapping cron cycles are safe (`FOR UPDATE SKIP LOCKED`, Doc 8 §11), so a tighter schedule
  raises throughput until SKIP LOCKED contention and the cycle time budget balance out.
- One cycle runs **relay → dispatch → project → maintenance** in sequence and exits. An event
  captured before a cycle starts can therefore traverse the whole pipeline **within that one
  cycle** — the pipeline's three hops do not multiply the wait.
- Latency ≈ **(time until the next cycle) + (cycle duration)**. The first term dominates, and it
  is the cron interval. **This is why cadence is the latency lever.**

### Shipped defaults (`headless-sync/config/worker.php` → `processing`)

| Key | Default | Meaning |
|---|---|---|
| `interval_seconds` | **20** | recurring `hsp_processing_cycle` interval — pinned by **DECISION AB** so the worst case fits the <30s SLA (was 60) |
| `relay_batch_size` | 200 | max `wp_hsp_outbox` rows relayed per cycle |
| `dispatch_batch_size` | 200 | max `system.events` rows enqueued per cycle |
| `projection_batch_size` | 200 | max `system.queue_jobs` claimed + projected per cycle |
| `cycle_time_budget_seconds` | 20 | soft budget; the cycle stops claiming new work past it. Keep **well inside** PHP `max_execution_time` |

### The <30s SLA: settled by DECISION AB (2026-09-06) — FLAG-P1BS0-1 closed

Measured (P1B-S5): a single edit traverses **outbox → relay → dispatch → project → readable** in
**0.06s**; a saturated 200-event batch costs ~25–30ms per event, so **6–9s depending on host** (measured on two runs). **The pipeline is ~0.2%
of sync latency; the cron cadence is the other ~99.8%.** So the SLA is a cadence question and
nothing else — the ruling is two halves, and **both are required**:

**1. Config (shipped).** `processing.interval_seconds` is **20**, not 60. Worst case ≈ 20.1s for a
single edit, ≈ 26–29s with a saturated 200-batch (the burst regime — batch size is the other lever there) — inside 30s either way. A change takes effect on the
next firing: `wp_reschedule_event()` looks the interval up by schedule name, so there is no
migration and no re-scheduling step.

**2. Deployment (operator obligation).** The interval alone is **not sufficient**. WordPress's own
request-triggered cron refuses to spawn more often than `WP_CRON_LOCK_TIMEOUT` — **60s** by core
default, enforced in `spawn_cron()`:

```php
// wp-includes/cron.php — "Don't run … more than once every 60 sec."
if ( $lock + WP_CRON_LOCK_TIMEOUT > $gmt_time ) { return false; }
```

so on a default site the effective cadence is floored at 60s no matter what the schedule says (and
on a quiet site it is worse — WP-Cron only fires on traffic at all). **`wp cron event run` defines
`DOING_CRON` and bypasses that path entirely**, which is why the SLA requires an out-of-band
trigger at **≤ 20s**. System cron is minute-granular, so use the offset trio:

```cron
* * * * *              cd /path/to/wp && wp cron event run --due-now >/dev/null 2>&1
* * * * * sleep 20 ;   cd /path/to/wp && wp cron event run --due-now >/dev/null 2>&1
* * * * * sleep 40 ;   cd /path/to/wp && wp cron event run --due-now >/dev/null 2>&1
```

Pair it with `define( 'DISABLE_WP_CRON', true );` so page loads stop racing the trigger. This is a
**trigger, not a daemon** — each invocation runs one bounded cycle and exits (ADR-054 §5/§23).

**Without the trigger the platform still works with zero configuration** (Principle 8) — content
syncs, nothing breaks, only the <30s SLA is unmet. The SLA is therefore a *supported deployment
property*, not an unconditional platform guarantee.

**Rejected options** (recorded in DECISION AB): restating the SLA to fit a 60s cadence — the
measurement shows there was nothing to concede; and emitting `spawn_cron()` on every content save,
which the same 60s lock renders ineffective while adding a loopback HTTP request per save.

**Guard:** `ProcessingCycleIntegrationTest::test_end_to_end_sync_latency_through_one_cycle` now
reads `interval_seconds` from the shipped config and **asserts** the worst case is under 30s.

---

## 4. Delivery-path speed rules (read latency)

These are architectural rules, not optimisations — breaking one is a Rule violation, not a slow
query:

1. **No synchronous WordPress read on the consumer path** (Rule 6). A REST response is served
   from the PostgreSQL projection only.
2. **Transform before persist** (Rule 2). The projection stores the delivery shape, so read time
   does no assembly work. Never a raw `wp_posts`/`wp_postmeta` replica.
3. **Cursor pagination, never offset** (Doc 9 §13; DECISION F `CursorPage`). The cursor encodes
   `{"s": <sort value>, "id": <uuid>}` so duplicate sort values cannot skip or duplicate rows.
4. **Index-backed queries.** Every projection ships indexes for its access paths — see
   `content.posts`: slug, status, `published_at`, `updated_at`, plus a **GIN index on
   `meta_jsonb`**. A new projection must ship the equivalents.
5. **Watch for N+1 on relationship resolution.** Resolving a per-row related entity (e.g. a
   featured image per post) one query at a time turns one list request into N+1 round-trips.
   Batch-resolve, or denormalise into the projection at write time.
6. **API-level caching is supported but not built** (Doc 9 §21) — cache layer between API and
   query layer, **event-driven invalidation** keyed on `content.*.updated`. Not in the MVP; do
   not assume a cache exists when reasoning about read latency.

---

## 5. Write-path speed rules (sync latency)

1. **Capture is a near-atomic post-commit write to `wp_hsp_outbox`** (DECISION 1) — the WordPress
   request pays one MySQL insert, nothing more. Never make a WordPress save wait on PostgreSQL.
2. **Write-suppress by projection checksum** (DECISION 3): recompute the projection checksum and
   compare it to the **stored** one. Unchanged content = zero writes. Never compare against the
   event's own checksum — that is traceability only.
3. **Atomicity costs one transaction**: projection upsert + `system.processed_events` insert +
   `system.aggregate_versions` upsert commit together (DECISION 3). Do not split it to "go
   faster".
4. **Batch sizes are config-driven, not hard-coded** — a stage that hard-codes its batch size
   removes the only throughput lever the platform has.
5. **The cycle must exit inside its time budget.** Work that cannot be bounded per cycle does not
   belong in a cycle.
6. **Four PostgreSQL handles, opened lazily** (DECISION K / DECISION L Ruling 0 / DECISION Z). No
   fifth handle, and connecting is deferred to first real use so an unreachable PostgreSQL never
   fatals a page load.

---

## 6. What "performance work" is NOT allowed to be

- Adding worker processes, daemons, supervisors, or a CLI worker loop (ADR-054).
- Adding a fifth PostgreSQL handle or a new `pg_*` wrapper (DECISION E, DECISION L Ruling 0).
- Adding persistence for metrics — console metrics are derived on demand (DECISION Q).
- Offset pagination anywhere (Doc 9 §13).
- Reading WordPress synchronously on the consumer path (Rule 6).
- A `tsvector` column, full-text index, or search endpoint before Phase 5 (DECISION Y).

---

## 7. How to size a new feature in one paragraph

> Does it add a synchronous WordPress read on the delivery path? (Rule 6 — no.) Does it add a
> per-row lookup to a list endpoint? (N+1 — batch or denormalise.) Does it add unbounded work to
> a cycle? (Bound it by a config-driven batch size.) Does its list surface use the cursor
> envelope and index-backed sort columns? Does its projection ship indexes for its access paths?
> Does the canonical checksum move when the feature's data moves — or will DECISION 3 silently
> suppress the write? If all six answer well, the feature is within the <30s SLA and the
> 100k-record target by construction.

---

## Source index (open only if the digest is insufficient)

| Topic | Where |
|---|---|
| Latency + scale targets | `docs/PRD.md` §Scalability, §Performance, §Success Criteria |
| Scale targets, phased | `docs/01-…md` §3, §15 |
| Scaling model (cron frequency + batch size) | `docs/08-…md` §10, §11 |
| Ops targets (queue lag, sync delay, processing freshness) | `docs/10-…md` §24 |
| Gate criteria (reliability / scalability / operability / extensibility) | `docs/11-…md` §9–10 |
| Pagination, caching, query ownership | `docs/09-…md` §13, §21, §8–12 |
| Execution model (authoritative) | `docs/ARCHITECTURE_DECISIONS.md` ADR-054, DECISION X |
| Cycle config defaults | `headless-sync/config/worker.php` |
