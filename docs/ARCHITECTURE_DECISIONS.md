# HSP Architecture Decisions — Authoritative Conflict-Resolution Record

**Precedence: when this document conflicts with the PRD or Docs 1–11, THIS document wins. These resolutions are Accepted and frozen. Do not re-open or re-derive them.**

Version: 1.41  
Status: Accepted  
Owner: Architecture  

---

## Amendment Log

| Version | Date | Items changed |
|---|---|---|
| 1.41 | 2026-09-08 | **DECISION AI — the full-batch cycle budget becomes a controlled CI performance gate (architect ruling 2026-09-08; resolves FLAG-PERFCYCLE-1).** Option (a) chosen; (b) baseline subtraction and (c) raising the threshold both rejected. **(AI-1)** the guarantee is unchanged — an extrapolated default projection batch must drain in **under half of `processing.cycle_time_budget_seconds`** (under 10 s against the shipped 20 s), and that threshold does not move because one workstation has unstable database round-trip latency. **(AI-2)** the assertion is reclassified a **PERFORMANCE GATE** rather than a machine-independent integration test, executing where database services are **colocated**, stable and reproducible; **"containers are slow" must not be encoded as architecture** — colocated CI containers are acceptable, the defect is the noisy host topology. **(AI-3)** local runs may skip behind an **explicit, visible** environment guard (`HSP_PERFORMANCE_GATE=1`); a skipped gate reports **skipped, never passed**, CI enables it explicitly, and release evidence must show it actually ran. **(AI-4)** **no self-calibration** — no measured baseline, subtraction formula or host calibration factor, because that machinery risks subtracting away a real regression. **(AI-5)** the threshold does not rise; a consistent failure on the controlled runner is a **STOP-and-flag** and investigation of query efficiency, adapter work, source loading, access patterns, batch allocation and round-trip count **before** any budget change (DECISION AG Part 5 item 10 remains in force). **(AI-6)** the mixed-domain proof stays **separately required** — the full-batch gate and the Commerce-does-not-starve-Content scenarios answer different questions and neither replaces the other. **No production behaviour changes**: not the cycle budget, batch size, cadence, PHP timeout, connection count or execution architecture. |
| 1.40 | 2026-09-08 | **DECISION AH — permalink reconstruction: Case B authorised, implementation scheduled for Phase 4 (architect ruling 2026-09-08; resolves FLAG-COMMPERMA-1).** Requirement A classified the outcome Case B at the P2-S2/P2-S3 preflights; this ruling settles it. **The flag is RESOLVED — record it as *architecture decided, implementation scheduled*, never as an open gap.** Phase 2 remains correct as shipped (no stored product or category permalink, no derived URL/path/URI column, no permalink in the endpoint contract) and does **not** reopen. **(AH-1)** a Commerce-owned store-level configuration projection (`commerce.store_config`) is authorised **in Phase 4** — ONE store-level source, never duplicated onto products/variations/taxonomies and never a per-entity derived permalink, so changing `product_base` updates the configuration projection **only** rather than rewriting every Product row. **(AH-2)** **Commerce owns it, not Core** — no generic `core.settings`/`system.wordpress_settings` projection pre-emptively; a Core contract only once **two** real modules demonstrate a shared capability. **(AH-3)** minimum configuration is `product_base`, `category_base`, `attribute_base` with **token semantics intact** (`%product_cat%` is part of the template, not a separate value, and must not be flattened) — and those three must **NOT** be assumed complete: the Phase 4 preflight verifies the full required state, including any WordPress-level permalink mode, trailing-slash or site-relative semantics the supported version consults; **only verified settings may be projected, never an arbitrary option dump.** **(AH-4)** invalidation flows through the **normal pipeline** (capture → `commerce.store_config.changed` → outbox → relay → dispatch → handler), preferring a **narrow option-specific hook**, with generic `updated_option` acceptable only when tightly guarded to the exact option; bootstrap and reconciliation must include current configuration so an existing store converges without an admin edit; no direct WP→PG repair path. **(AH-5)** permalinks resolve at **READ time** from projected state — no stored `permalink`/`path`/`uri`/`url` columns, no rewrite fan-out, no WordPress query during delivery. **(AH-6)** **HSP owns the resolution algorithm** — `%product_cat%` selection is **verified against supported WooCommerce behaviour**, never an arbitrary first-row/lowest-id/alphabetical choice, and HSP promises supported core behaviour rather than third-party permalink-filter parity. **(AH-7)** the delivery contract is a read-time `links.permalink` carrying a **RELATIVE public path** (the WordPress host and the frontend host may differ), never persisted; **no `/hsp/v1/store` endpoint is required** to solve this flag, though Phase 4 may evaluate one on independent merit. **(AH-8)** Product Category permalinks follow the same model — hierarchy resolved from projected relationships, **no stored category paths**, ambiguous leaf slugs use the DECISION AD/AE/AF path semantics, and **no WordPress term id enters the public addressing contract.** **(AH-9)** `test_no_commerce_projection_stores_a_derived_url` is **permanently valid in principle**; the endpoint guard is Phase-2-scoped and must be **replaced or amended, never deleted**, when Phase 4 introduces the approved contract. Adds an explicit Phase 4 roadmap item (store-config aggregate, verified capture/invalidation, bootstrap, both resolvers, `%product_cat%` expansion, `links.permalink`, no stored URLs, no delivery-time WP reads, compatibility tests). |
| 1.39 | 2026-09-07 | **DECISION AG — Phase 2 ratification: HSP becomes a genuinely multi-module platform, and the WooCommerce Catalog domain model is fixed (P2-S0; architect rulings 2026-09-07).** Records **fourteen rulings + four standing requirements**. **AG-1…AG-11 resolve the eleven blocking flags raised in this session's ratification review; AG-12, AG-13 and AG-14 are proactive architect rulings, NOT flag resolutions.** **Multi-module infrastructure (P2-S1):** **(AG-1)** core must not import or hardcode a concrete module — `ModuleInterface::getServiceProvider()` becomes a real contract, `ContainerBuilder`'s `ContentServiceProvider` import is retired, Commerce adds no line to core, and the isolation guard scans core→module as well as module→module. **(AG-2)** replay and reconciliation become **Core-owned registries keyed by aggregate type** (`ReplayEmitterRegistryInterface` / `ReconciliationSourceRegistryInterface`) — a single container key was last-writer-wins and would have **silently deleted** Content's emitter; Core (not a module) constructs `ReplayService`/`ReconciliationService`; duplicate aggregate registration throws; missing coverage is never silently `continue`d; backfill enumerates all ACTIVE sources. **(AG-3)** the three hardcoded `content.*` projection maps become a **module-registered `ProjectionRegistryInterface`/`ProjectionDescriptor`** — core must not know every domain table; identifiers come only from trusted module registration and are validated (`BackfillReader` interpolates the table name into SQL); no arbitrary-SQL escape hatch, no new persistence. **(AG-4)** one explicit **domain→partition routing seam** (`content.*→content`, `commerce.*→commerce`, `system.*→system`) replaces the hardcoded `'content'` — this is the routing ADR DECISION L v1.12 deferred; **`processing.projection_batch_size` stays the TOTAL cycle budget, shared fairly across active partitions (deterministic round-robin), never multiplied per domain**; no weighted scheduling or priority queues without a new ruling. **(AG-5)** `FilterSet` is superseded by a domain-neutral **`QueryFilterInterface`** with module-owned typed DTOs (`ContentFilterSet`, `ProductFilterSet`); providers explicitly reject a foreign domain filter; **no untyped array bag and no QueryFilterRegistry**. **(AG-6)** `system.module_versions` finally gets an **idempotent writer**, only after a module's migrations reach its declared schema version; `system.schema_versions` remains the authoritative migration-state record. **Commerce domain model:** **(AG-7)** Doc 3 §18's mandatory cross-aggregate FKs are **superseded** — variation→product, inventory→product and attribute-term→attribute use **soft references**, because at-least-once, non-FIFO, replay and overlapping cycles mean a child may legitimately arrive before its parent and the database must not turn valid out-of-order sync into a DLQ failure; PK/unique/check constraints and same-transaction structures are unaffected. **(AG-8)** **`commerce.inventory` owns stock state** — the duplicated `stock_status` is removed from the product projection (one fact, two checksums, two write-suppress decisions that can disagree); product reads JOIN inventory rather than copying it back. **(AG-9)** DECISION AA extends to Commerce: **one shared `commerce.taxonomies` + `commerce.entity_taxonomies`** discriminated by `taxonomy_type`, superseding Doc 3's `commerce.categories`/`attribute_terms`/`product_categories` **where they merely represent terms and relationships** — but **`commerce.attributes` remains separate because global attribute DEFINITIONS are not taxonomy terms**; `product_tag`, local/custom attributes and a second `attribute_terms` table are out of scope. **(AG-10)** `content.media` stays the single attachment projection — **no duplicate Commerce copy, no Commerce→Content PHP import, no hidden `Commerce SQL → content.media` dependency**; expanded media is optional capability composition through a Core contract with **bulk** resolution, and **Commerce product sync must still succeed when that capability is absent**. **(AG-11)** the PostgreSQL column canon applies platform-wide to `commerce.*`, but **semantic columns do not clone** — a join table needs no checksum for symmetry, a never-tombstoned table needs no `deleted_at`, `meta_jsonb` follows the contract. **Proactive rulings:** **(AG-12)** module lifecycle gains **three conditions — DISCOVERED / AVAILABLE / READY(ACTIVE)** — with data bootstrap (`pending`/`complete`) tracked separately; **a module is never runtime-ready merely because `isAvailable()` is true**, migration failure leaves it not-active while every other module keeps running, and an unavailable module contributes nothing (no capture, emitters, sources, descriptors, routing, endpoints or backfill counts). **WooCommerce installed AFTER HSP must converge the existing catalog with no reactivation, no manual migrate and no manual reconcile**, via an explicit Core-owned lifecycle coordinator (not hidden in `CommerceServiceProvider`) and the EXISTING Migration Engine — P2-S1 is authorised to fix the engine if module migrations can only run at plugin activation; **no second migration system**. Module bootstrap state = a module-keyed WordPress option (`hsp_module_bootstrap_state`, or one option per module if that is what gives lost-update safety) — **lifecycle state, not metrics, and no PostgreSQL table**; a sibling module's state must never be erased. Bootstrap runs as module-scoped `ReconciliationService` re-emission — no direct WP→PG copy, no second repair path, no in-request drain, no reset of global onboarding, Content stays online. **(AG-13)** Phase 2 supports **`simple` + `variable` only**; grouped/external/custom are **normal out-of-scope source entities, not processing failures** — no repeated retry, no DLQ, no blocked reconciliation or bootstrap, and excluded from expected counts; **all four type transitions are mandatory coverage**, and simple/variable→unsupported must **tombstone** through DECISION I/T/U rather than leaving a permanently visible projection. **(AG-14)** inventory ownership is **aggregate-aware** (`owner_type`/`owner_id`) so a variation can own its stock — **no `product_inventory`/`variation_inventory` split and no variation stock duplicated onto the product**; a variation inheriting parent-managed stock gets **no** invented duplicate fact; **missing inventory is never "out of stock"** and never removes a valid product from a listing (LEFT JOIN, tolerant reads). **Standing requirements:** **(A)** permalink parity is a **verification target** — P2-S2 (products) and P2-S3 (categories) preflight the installed WooCommerce permalink configuration and classify Case A/B/C, with **P2-S7 reporting Product and Product Category permalink compatibility separately as PROVEN or EXPLICITLY DEFERRED WITH FLAG**; no hardcoded `/product/{slug}`, no forced stored permalink column, no WP read at delivery time. **(B)** WooCommerce **catalog visibility is not `post_status`** — a published-but-hidden product stays out of list endpoints. **(C)** money stays **`NUMERIC` with no imposed precision/scale**, normalized to a **deterministic exact decimal string before checksum construction** so `10`/`10.0`/`10.00` cannot churn the projection; PHP floats are never canonical. **(C′)** **currency is store-level**, not a per-product column — Doc 3's per-product `currency` is superseded pending store-level configuration semantics. **Cross-cutting:** every aggregate proves **create → update → leave-scope → tombstone → replay → reconciliation**; relationships are tested through **real handlers**; the `product_cat` hierarchy is preflighted before the migration, `(taxonomy_type, slug)` is **never** the durable identity and gets **no UNIQUE constraint** without verification, and ambiguous leaf slugs follow **DECISION AD/AE/AF** (full ancestor path at read time, reusing `HierarchicalQueryProviderInterface`, no second hierarchy architecture, no stored path column) — with **no legacy one-segment arm**, since DECISION AF already removed it for pages. Mixed-domain performance is benchmarked together, and **if the DECISION AB ≈20.1 s margin cannot absorb Commerce the session STOPS and flags** rather than raising budgets. Inserts **P2-S1…P2-S7** into IMPLEMENTATION_PLAN.md §5b; amends Doc 3 §13–18 and Doc 11 §11 under banners (original text retained); Implications table updated; CLAUDE.md SETTLED + MVP-scope reconciled. **Docs only — no production code.** |
| 1.38 | 2026-09-07 | **DECISION AF — the one-segment leaf fallback reaches Removed, completing DECISION AD ruling 2 (scope-owner directive 2026-09-07; the "explicit lifecycle session" ruling 2 reserved removal for).** `/hsp/v1/pages/{path}` is now **exact-path lookup and nothing else** — every miss is a 404 at any depth, and `/pages/team` resolves the top-level page of that name or nothing. **(1)** The fallback call is gone from `ContentRestRegistrar::handlePageSingle()`. **(2) The mitigation SQL is DELETED, not merely orphaned:** `PageQueryProvider::findBySlug()` carried the FLAG-PAGESLUG-1 bare-slug lookup (`ORDER BY parent_id, id`), and merely ceasing to call it would have left a method handing an arbitrary nested page to any future caller. It cannot be deleted — `QueryProviderInterface` requires it and pages need that interface for the listing's `list()` — so it now **delegates to `findByPath($slug)`**, giving a bare page slug the one meaning AD ruling 2 already defines for a one-segment address (the top-level page of that name) and making the two impossible to drift apart. **(3) Corrects DECISION AD ruling 7 on a point of fact:** that ruling anticipated the registrar's page slot narrowing to `HierarchicalQueryProviderInterface` alone; **it does not** — the intersection stays, because the `/pages` LISTING still calls `list()`. What ended was the single-route handler's *use of* `findBySlug()`, not the need for the interface. **(4)** WordPress parity in the FLAG-PAGEPATH-ANCESTOR-1 case is now reached exactly as **DECISION AE predicted**: the fallback used to return a published child whose ancestor is unprojected — a page WordPress itself 404s — and with no bare-slug back door left, the test that pinned the old behaviour now pins parity. **On the lifecycle, stated plainly:** Deprecated (AD) and Removed (AF) fall on the same calendar day, a short window by any reading of Doc 9 §26. It is a legitimate lifecycle completion rather than the direct cut-over ruling 2 prohibited, because the platform is at **version 0.1.0 and unreleased** — `hsp/v1` has no external consumers to strand — and AD explicitly reserved removal for an explicit lifecycle session. **This must not be cited as precedent for a same-day retirement on a released contract**, which would still require a genuine migration window or an `hsp/v2` transition (Doc 9 §7). No schema, migration, persistence, capture-model, contract, route or descriptor change; ADR-055 stays at 11 routes. |
| 1.37 | 2026-09-07 | **DECISION AE — unprojected page ancestors are a documented limit, not a defect (FLAG-PAGEPATH-ANCESTOR-1 settled; scope-owner directive 2026-09-07, resolved on empirical evidence).** Option **(a) accept and document**; (b) deferred to a future OPEN-10 ruling; (c) prohibited by ADR-040. **The flag as raised over-stated the problem, and the correction is the substance.** Verified against live WordPress: `wp_insert_post()` skips slug generation for `draft`/`pending`/`auto-draft`, so a never-published parent normally has an **empty `post_name`** — `get_page_uri()` on the published child then returns the **leaf alone** (`team`, not `about/team`) and `get_page_by_path()` resolves it under **neither** address. WordPress advertises a permalink it cannot route, so in the normal draft case **HSP is at parity — there is no address to miss.** The real divergence is one narrow sub-case: a parent that has **never been published yet carries an explicitly-set slug** (permalink edited on a draft, or an import setting `post_name`), which WordPress *does* resolve and HSP cannot, because the parent has no projection row. The already-published-then-unpublished case was never affected — rows are soft-deleted, never removed, so the slug survives and the path resolves (asserted). **(1)** The limit is accepted and documented. **(2)** It is **pinned by test**, not merely described, so a future OPEN-10 change cannot alter it silently. **(3)** The deprecated one-segment leaf fallback (AD ruling 2) is currently **more permissive than WordPress** here — it returns a child WordPress itself 404s — so retiring it at the Doc 9 §26 transition brings this case to exact parity, a fix rather than a regression. **(b) was not taken** because it changes what the pipeline captures (**OPEN-10**, frozen) and would place never-public content in the delivery store: today the projection holds non-public rows only for content that was public at least once, and widening that turns any future query-predicate slip into a leak of unpublished material — a bug class this codebase has already hit three times. If WordPress fidelity in that sub-case is later judged worth it, **it is an OPEN-10 ruling and must be taken as one**; this decision does not pre-empt it. No schema, migration, persistence, capture-model, contract or code change — one integration test added. |
| 1.36 | 2026-09-07 | **DECISION AD — hierarchical page addressing by full ancestor path (FLAG-PAGESLUG-1 settled; architect ruling 2026-09-07).** WordPress scopes page slug uniqueness per PARENT, so `/about/team` and `/services/team` both project with `slug='team'` and a bare-slug lookup could not address either one on purpose. **(1)** The canonical page identity becomes the **full ancestor path**: `GET /hsp/v1/pages/{path}` — `/pages/about/team` resolves that exact hierarchy and **`/pages/wrong-parent/team` is a 404 even when `/about/team` exists**. A **widening of the same endpoint** (parameter `slug` → `path` in route and descriptor together); ADR-055 route count unchanged; no second page-addressing API. **(2)** A one-segment request canonically means the **top-level** page. Because leaf lookup is currently supported and Doc 9 §26 forbids direct removal, `hsp/v1` tries the exact path first, **never** leaf-falls-back for a multi-segment miss, and falls back to the deterministic leaf lookup **only** for a one-segment miss — explicitly **deprecated** behaviour, removed at a formal contract transition, not here. **(3) Read-time resolution: no `path`/`uri`/`permalink` column, no migration, no cache, no descendant fan-out.** A stored path duplicates hierarchical state — a parent rename changes every descendant URI although no descendant was edited and WordPress emits no event for them, so it would need invalidation machinery and still leave a stale window (hourly drift is timestamp-only and would not see it; repair would wait for nightly incremental). OPEN-11's "no precomputed URIs" exclusion is **upheld, not waived**. **(4)** One query: a **recursive CTE walking up** from leaf candidates, index-backed on `idx_content_pages_slug` + `uq_content_pages_source_post_id`, no N+1, no WP read (ADR-040); `MAX_ANCESTOR_DEPTH` is a defensive cycle/corruption bound, not a hierarchy limit. **(5)** The public-set predicate applies to the **requested page only** — ancestors are structural, so a published child under an unpublished or soft-deleted parent stays addressable while that parent stays unretrievable through its own address. **(6)** Sanitization is **per segment** (`sanitize_title()` strips `/` and would collapse `about/team` into `aboutteam`); leading/trailing separators trimmed; malformed paths **400** before any lookup; traversal blocked by both the route character class and the sanitizer. **(7)** New **additive** core capability `HierarchicalQueryProviderInterface::findByPath()`; `QueryProviderInterface::findBySlug()` is **unchanged** and still correct for the flat resources; the registrar's page slot is typed as the intersection so the compatibility arm is visible in the signature. Rule 5 unchanged. **(8)** **No `?parent=`** in either form — by id it would make WP post IDs the public addressing contract (Rule 6); by slug it is ambiguous the moment the parent is nested. Scope is **Pages only**. **New flag FLAG-PAGEPATH-ANCESTOR-1:** a **never-published** ancestor has no projection row at all (OPEN-10), so a published descendant beneath it cannot have its path reconstructed — a projection-coverage question, flagged rather than worked around. Also recorded: `PlaygroundRequestExecutor` now encodes path parameters **per segment** (`rawurlencode()` on the whole value would have produced `about%2Fteam` and 404'd the console playground; byte-identical for every single-slug route). No schema, migration, persistence, handle (L Ruling 0) or `pg_*` wrapper (E) change. |
| 1.35 | 2026-09-07 | **DECISION AC — reconciliation and backfill cover every supported aggregate (FLAG-RECON-COVERAGE-1 settled; scope-owner directive 2026-09-07).** Options **(a) + (c) together**; (b) rejected. `media` and `tag` were implemented end-to-end in `WpReconciliationSource` and `ContentReplayEmitter` but missing from the three CONSUMING lists, and `ReconciliationService::reconcile()` `continue`s past a supported type with no projection entry **silently** — so no reconcile mode ever touched them and, since the onboarding backfill IS `reconcileFull()` (DECISION W (b)), **a fresh install never backfilled them** and could be declared **converged with zero media and zero tags projected**. **(1)** Both types added to `ReconciliationService::PROJECTION` (`media` → `content.media`/`source_post_id`; `tag` → `content.taxonomies`/`source_term_id` scoped `taxonomy_type = 'post_tag'`), `BackfillReader::PROJECTION` and `BackfillProgress::TYPES` — three list entries, **no new mechanism**: worker, adapter, extractor, transformer, event, migration and repair path are all untouched. The DECISION AA taxonomy scoping is the **prerequisite** that makes the `tag` entry safe. **(2)** Convergence semantics change in the strict direction: onboarding now scores media and tags, so a site stays **in progress** until they land — a site that previously reported complete with unprojected media was reporting a state that was never true. **(3)** The seam is **guarded**: `tests/Unit/Reconciliation/AggregateCoverageTest.php` asserts every `WpReconciliationSource::AGGREGATE_TYPES` entry appears in all three consuming lists and is re-emittable. A **unit** test on purpose — `Phase1BValidationTest`'s producing-side assertion is integration-only and self-skips without a live DB, exactly when a new aggregate gets added. A runtime throw was rejected: core owns the map, modules own the supported list, so a module ahead of core would fatal every cron cycle instead of failing a build. **Rejected: (b)** scope the backfill to page/post/category — that makes two thirds of Phase 1B's aggregates permanently hook-only and never repaired, contradicting Rule 1, to save three list entries. No schema, no migration, no new persistence (DECISION Q holds), no contract change, no new PG handle (L Ruling 0), no `pg_*` wrapper (E), no second repair path (DECISION T holds). |
| 1.34 | 2026-09-06 | **DECISION AB — sync-latency SLA: 20s shipped cadence + out-of-band trigger obligation (FLAG-P1BS0-1 settled; product/architect ruling 2026-09-06).** Options **(a) + (c) together**; (b) and (d) rejected. The P1B-S5 measurement made the ruling cheap: the pipeline costs **0.06s** for one edit (~6–9s for a saturated 200-batch) — **~0.2% of sync latency against the cadence's ~99.8%** — so cadence was the entire problem. **(1)** `config/worker.php` → `processing.interval_seconds` **60 → 20** (with `ProcessingCronRegistrar::DEFAULT_INTERVAL_SECONDS` tracking it), putting the worst case at **≈20.1s** (≈26–29s at a saturated 200-batch — the burst regime, where batch size is the other lever) inside the PRD <30s SLA; propagates on the next firing (`wp_reschedule_event()` resolves the interval by schedule name) — **no migration, no re-scheduling**. **(2)** The interval **alone is insufficient**: `spawn_cron()` enforces `WP_CRON_LOCK_TIMEOUT` (**60s** core default), so request-triggered WP-Cron is floored at 60s regardless of the schedule. The SLA therefore **requires** an out-of-band trigger running `wp cron event run --due-now` at **≤20s** (WP-CLI defines `DOING_CRON` and bypasses the lock) — a **trigger, not a daemon**; ADR-054 is not reopened. **(3)** The <30s SLA is consequently a supported **deployment property**, not an unconditional guarantee — without the trigger the platform still runs with zero configuration (Principle 8) and only the SLA is unmet. **(4)** `ProcessingCycleIntegrationTest::test_end_to_end_sync_latency_through_one_cycle` now reads the interval **from the shipped config** (never restated) and **asserts** the worst case < 30s. **Rejected: (b)** restating the SLA's basis — nothing left to concede once measured; **(d)** `spawn_cron()` on capture — the same 60s lock makes it ineffective for sub-60s cadence while adding a loopback request per save and edging toward the in-request drain DECISION W (c) forbids. No schema, no migration, no persistence, no contract change, no new PG handle (L Ruling 0) or `pg_*` wrapper (E); batch sizes, `cycle_time_budget_seconds` and `heartbeat.offline_after_seconds` untouched. Doc 10 §7 "Reliable Cadence (optional, recommended)" is **narrowed in effect, not contradicted** (optional for function, required for the SLA). |
| 1.33 | 2026-09-06 | **DECISION AA — shared taxonomy projection per owning domain (FLAG-TAXSCHEMA-1 settled; architect ruling 2026-09-06).** Taxonomies project into **one shared table per owning domain**, told apart by a `taxonomy_type` discriminator — `content.taxonomies` + `content.entity_taxonomies` carry `category`, `post_tag` and every future **Content** taxonomy. **Not** table-per-taxonomy; **not** one platform-wide taxonomy table (Commerce gets its own `commerce.*` projection model, designed with the Commerce module). A new Content taxonomy is **new data, not a migration**. The shipped Phase 1B implementation **already matched this model and is preserved** — no rewrite. What the ruling changed: (1) a **query rule** — every read of the shared table must identify BOTH taxonomy type and term identity (a read keyed on the globally-unique `source_term_id` is exempt), now enforced by `tests/Unit/Content/TaxonomyQueryRuleTest.php`; (2) **index shapes aligned to the real delivery query paths** — migration `0008_align_content_taxonomy_indexes` replaces `(slug)` and `(taxonomy_type)` with `(taxonomy_type, slug)` and widens the entity-join reverse index to `(taxonomy_id, entity_id)`; `(taxonomy_type, parent_id)` is deliberately deferred until a query path needs it. The P1A-S4 pure-join ruling on `content.entity_taxonomies` is **upheld**. No column, key, constraint, contract, event, handle or persistence change. |
| 1.32 | 2026-09-05 | **DECISION Y — PostgreSQL full-text search deferred from Phase 1B to Phase 5 (P1B-S0, docs-only; product decision 2026-09-05).** Phase 1B — Content Enhancement is **featured images, media synchronization, tags, basic ACF, pagination**; **search is NOT a Phase 1B deliverable** and lands in **Phase 5 — Search Expansion** (Doc 11 §14), which already states PostgreSQL Search remains supported. **Doc 11 → v1.2**: the "PostgreSQL Search" deliverable and the "Search Queries" validation item in §7 are retained under an explicit DECISION Y banner (superseded text is bannered, never deleted — DOC-RECON-S1 precedent). **§17 Search Roadmap ordering is unchanged** (PostgreSQL Search still precedes the provider contract and OpenSearch/Typesense — only the phase placement moved), and **Doc 9 §14/§15, Doc 3 §27 and the Doc 5/6/7 search-projection references are untouched** — all Phase 5+ material. No Phase 1B session may introduce a `tsvector` column, full-text index, or search endpoint. No schema change, no contract change, no code change. |
| 1.31 | 2026-09-05 | **DECISION Z — lazy PostgreSQL connections at the container boundary (LAZYPG-S1, interstitial before P1B-S0).** Resolves the ONB-S1b "lazy-connection ruling pre-Phase-1B" carry-forward. All four runtime PG handles (delivery, relay, queue-claim, dispatcher) previously called `pg_connect()` **inside their singleton factory**, so merely RESOLVING a binding threw a raw `\RuntimeException` when PostgreSQL was unreachable or unconfigured; because `rest_api_init` fires on **every REST request to the site** (`wp/v2` and the block editor included) and building the content registrar resolves the query providers, an unreachable PostgreSQL fatalled **every REST request**, not just `hsp/v1`. Each factory now hands a **connector `\Closure`** to its wrapper; `PostgresDatabaseConnection` accepts a handle **or** a connector, invokes it at most once on first real use, memoizes it, and translates connect failure to `DatabaseException` at that boundary (onward translation to `OutboxWriteException`/`QueueException` unchanged — DECISION E v1.6); `rollback()` on a never-opened connection is a no-op. Mirrors the existing `MysqliOutboxConnection` connector hotfix on the capture path. **Still four handles with their existing flags** — FORCE_NEW on delivery/queue/dispatcher, none on relay: DECISION K isolation and DECISION L Ruling 0 topology untouched. No fifth handle, no new `pg_*` wrapper, no persistence, no schema change, no contract change. DECISION **Y** is reserved for the P1B-S0 Phase-1B search deferral. |
| 1.30 | 2026-09-05 | **ADR-054 sibling-document reconciliation applied (DOC-RECON-S1, docs-only) — closes FLAG-DOC8V2-1. No new ruling, no ADR re-opened.** The already-ratified ADR-054 wording was propagated into the sibling frozen docs that still asserted the superseded daemon/CLI-worker execution model: **Doc 4 → v1.1** (§19 heartbeat = cycle freshness; §20 ADR-024 status → **SUPERSEDED by ADR-054**, original text retained verbatim as history; §29 scaling = overlapping cycles; §30 checklist), **Doc 10 → v1.1** (§4/§5 topologies, §7 rewritten WP-Cron-only, §20 `uptime` removed, §23 "Worker Offline" → "Processing Stalled", §24 availability target → processing freshness, §26 runbook rename, §27 systemd/Supervisor/worker-launch assets removed, §28 shared hosting without CLI/process supervision promoted to first-class supported), **Doc 11 → v1.1** (Doc-8 title, Scalability "Multiple Worker Processes" → concurrent claimants, "Restart Workers" note). CLAUDE.md was already clean (2026-07-20 rewrite). Superseded text is retained under explicit banners, never deleted; ADR-054 remains the single authority. See the APPLIED note under the ADR-054 Conflict Report. |
| 1.29 | 2026-07-20 | **ADR-055 (f)(2) meta-schema gate moved to the Node ajv toolchain — architect ruling "D" 2026-07-20 (OAPI-S1), verified against live reproductions.** `opis/json-schema` **2.6.0 is REMOVED from `require-dev`**: it has two reproduced JSON Schema 2020-12 conformance defects that make it unable to validate real OpenAPI 3.1 documents against the official meta-schema — **(i)** it does not index the `$dynamicAnchor: "meta"`, so `$dynamicRef "#meta"` mis-resolves to the document root (every Schema Object slot is validated as if it were a whole OpenAPI document); **(ii)** `unevaluatedProperties` reports schema-declared property names that are **absent** from the instance. The OAI `schema-base` variant does **not** avoid `$dynamicRef` (it overrides the anchor — defect (i) persists), and **no conformant 2020-12 PHP validator exists**. The **(f)(2) gate therefore runs via ajv in the Node toolchain** (already a sanctioned dev/CI dependency — DECISION W (a)), differential-verified against a conformant reference implementation on valid and invalid documents. **Pinned fixture:** `tests/fixtures/openapi-3.1-meta-schema-pinned.json` — the official OAI 3.1 meta-schema (`$id …/2022-10-07`, source tag 3.1.1) with exactly **four semantics-preserving edits** (`$dynamicRef "#meta"` → `$ref "#/$defs/schema"`; equivalent because the fixture validates as its own root resource, so no outer dynamic scope can retarget the anchor). Pinned, **never fetched at test time**. **Gate mechanics:** `tools/openapi-validator/` (committed `package.json` + `package-lock.json`; `node_modules/` gitignored) runs `validate-openapi.mjs` (Ajv2020 + ajv-formats, `strict:false`) over the pinned fixture; the drift guard calls it via `proc_open`, layered over the PHP structural pre-check which stays the fast-fail. **Environment contract:** node available → gate runs; node missing AND `HSP_REQUIRE_NODE_GATE` unset → the meta-schema assertion is **SKIPPED** with a warning naming the env var; `HSP_REQUIRE_NODE_GATE=1` (CI) AND node missing → **FAIL, never skip**. Completeness / exemption / exclusion / non-circularity stay pure PHP. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.28 | 2026-07-20 | **ADR-055 (f)(1) drift-guard enumeration scoped — resolves the OAPI-S1 drift-guard route-enumeration flag (architect ruling 2026-07-20, "A-modified", OAPI-S1).** The CI drift guard enumerates the **FULL live `hsp/v1` route index** (external ground truth — not the registry, preserving non-circularity), then applies exactly **ONE structural exemption**: routes under the **`hsp/v1/onboarding/` prefix** (authority: DECISION W (e) — the onboarding first-run **admin** surface is outside the published delivery contract; its registrar is gated pre-completion). **Every non-exempted `hsp/v1` route must carry a complete `EndpointDescriptor`** or CI fails. The exemption is a **single prefix frozen in this ADR** — adding any further exempt prefix requires an architect ruling; the guard **hardcodes this one prefix with an ADR-055 (f) citation comment**. Any **future authenticated `hsp/v1` route OUTSIDE the exempted prefix must carry a descriptor** (`auth = authenticated` → excluded from the served document per v1.27; asserted by the exclusion test). **Net today: 13 live `hsp/v1` routes − 6 onboarding = 7 guarded routes** (six content + `openapi.json`), matching the OAPI-S1 seven-route DoD. Adds a named non-circularity test: a fixture route registered on `hsp/v1` **outside** the exempted prefix **without** a descriptor **fails the guard**. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.27 | 2026-07-20 | **ADR-055 (d) scoping ruled — resolves FLAG-OAPI-1 (architect 2026-07-20, OAPI-S0 closeout).** The served `GET /hsp/v1/openapi.json` document describes **PUBLIC endpoints only**; endpoints requiring authentication/capabilities (Doc 9 §22) are **EXCLUDED from the generated document**. Exclusion is driven by the endpoint metadata **auth field** (ADR-055 (c)), **not by route inspection** (registry-driven, consistent with ADR-055 (a)). The **generator endpoint stays public and stateless** — **no capability check inside request-time generation** (consistent with ADR-055 (e)). The OAPI-S1 drift guard (ADR-055 (f)) must additionally assert, positively, that **no non-public-metadata route appears in the generated document** (exclusion test) — reflected in the OAPI-S1 DoD. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.26 | 2026-07-20 | **ADR-055 (OpenAPI Specification, Registry-Generated) — architect ruling 2026-07-20, interstitial OAPI-S1 (inserted BEFORE P1B-S0).** The OpenAPI **3.1** document for the delivery API is **GENERATED at request time from the endpoint metadata registry** (`EndpointProviderInterface` / Doc 12 §15) — **never hand-authored, never derived by reflection/scanning of WP REST routes** (explicit-registration idiom, OPSC-S1 registries; ADR-048/ADR-052). **Single source of truth = the endpoint registrations**: the spec auto-updates because it is derived from the current registrations each time it is served — add/edit/remove a registration and the spec follows with no separate edit. **Additive enrichment** of the endpoint metadata contract (`core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface`): params, request/response schema, auth requirement, cursor-pagination envelope (Doc 9 §13), deprecation status (Doc 9 §26), version, module owner — **core owns the contract** (`core/Contracts/`, Rule 5); **modules own their metadata** (Doc 9 §6). **Exposure:** `GET /hsp/v1/openapi.json` (versioned per Doc 9 §7 — the v1 spec describes v1). **Proposed public** per Doc 9 §22 (Pages/Posts are public) — **flagged for architect decision** on scoping the document to public endpoints only (**FLAG-OAPI-1**). **Generation is request-time + stateless:** NO persistence, NO PG read, NO new handle (DECISION L Ruling 0 untouched), NO `pg_*` wrapper (DECISION E), and **NO involvement of the ADR-054 cron cycle** — the generator never runs inside a processing cycle. **Drift guard (CI):** a test asserts every registered `hsp/v1` REST route has a complete metadata entry **AND** the generated document validates against the OpenAPI 3.1 meta-schema; a route without metadata **fails CI**. Amends Doc 12 §15 (OpenAPI Generation moved Future → in-scope). Inserts interstitial session **OAPI-S1** into IMPLEMENTATION_PLAN §5b **before P1B-S0**; no Phase 1B text altered. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.25 | 2026-07-18 | **DECISION W (f) amended — self-remediating ONB-S2 backfill gates (architect ruling 2026-07-18, ONB-S2).** The two ONB-S2 backfill prerequisite gates (migrations applied; processing pipeline advancing) become **self-remediating in-product** so a **zero-configuration fresh install** completes onboarding with **no manual CLI/engine step** (ADR-054 **Principle 8**). Adds two WPCS-guarded endpoints: **`POST hsp/v1/onboarding/migrate`** — applies the outstanding core + content migrations through the **EXISTING** migration engine over the **DECISION W (e) delegate list** (core migrations + module `getMigrations()` — OPEN-9/Rule 5), a **thin delegator** (`core/Onboarding/MigrationApplier`) with no new engine/DDL/schema/`pg_*` wrapper/handle, gated on the four ONB-S1b environment preflight checks (409 until they pass), re-evaluating `MigrationsAppliedCheck` after; and **`POST hsp/v1/onboarding/spawn-worker`** — a **non-blocking** WP-Cron spawn (`core/Onboarding/WorkerCronSpawner`) so a processing cycle runs and a heartbeat appears, with **NO in-request drain (DECISION W (c) intact)** and a WP-Cron-only warning when `DISABLE_WP_CRON` is set (no supervisor/systemd/daemon/restart wording — ADR-054 §5). Plugin **`activate()`/`upgrade()` (OPEN-9)** attempt pending migrations through the same shared engine **IFF `HSP_PG_*` defined AND PG reachable**, silent no-op otherwise — **activation never fatals on an unconfigured site**. Each gate keeps its **hard block** (action, not bypass); no second repair path. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.24 | 2026-07-17 | **DECISION X (ADR-054 Alignment Rulings — per-cycle identity, heartbeat status set, `WorkerInterface` contract shape, backfill prerequisite) — architect ruling 2026-07-17, resolves FLAG-ALIGN-1 (a)/(b)/(c) + FLAG-ALIGN-2.** Records four rulings enabling the ALIGN-S1/S2 implementation of ADR-054: **(1)** worker identity = **Option A** — each processing cycle mints a **fresh UUIDv7** `worker_id` at cycle bootstrap; `system.worker_heartbeats` rows now represent **processing-cycle executions**, not daemon identities; maintenance prunes stale rows under existing retention; this cardinality is what makes DECISION Q on-demand cycle metrics (cycles_completed / avg_cycle_duration) derivable — resolves FLAG-ALIGN-1 (a). **(2)** heartbeat `status` set = exactly `'running'`/`'idle'` in v1.x (`'processing'` → `'running'`; `'shutdown'` removed — cycles terminate normally) — resolves FLAG-ALIGN-1 (b). **(3)** `WorkerInterface` = **Option A (architectural correction)** — an internal core contract, not module-implemented; `run()`/`shutdown()` are **removed** and the contract expresses exactly **one bounded processing cycle**: execute one cycle honouring configured batch limits + execution-time budget, return a processing result describing the completed cycle — resolves FLAG-ALIGN-1 (c). **(4)** backfill prerequisite = **Option C** — a processing cron event scheduled **AND** a recent processing heartbeat; remediation references **only WP-Cron** (`wp cron event run --due-now` etc.), never supervisor/systemd/daemon/restart — resolves FLAG-ALIGN-2 (implemented in ALIGN-S2). Inserts ALIGN-S1 (Processing Engine cycle + trigger + contract + heartbeat) and a stub ALIGN-S2 (console/metrics reinterpretation + backfill gate + remaining docblocks) into IMPLEMENTATION_PLAN §5b. No schema change; no new PG handle / `pg_*` wrapper (ADR-054 §9 constraints hold). |
| 1.23 | 2026-07-17 | **ADR-054 (Background Processing via WP-Cron Processing Engine) — architect ruling 2026-07-17. Product ruling: HSP v1.x supports ONLY WP-Cron for background execution — no Supervisor, systemd, Docker workers, CLI daemons, or continuously running processes. The execution mechanism changes; nothing else does.** ADR-054 **supersedes the execution-model decision of ADR-024** ("CLI Workers" primary / WP-Cron fallback → **WP-Cron only for v1.x**) and **amends the wording of ADR-035 and ADR-036** where they assumed daemon workers (shared-engine + specialized-strategy model is retained; the *invocation* model changes from supervisor-launched daemon to a bounded cron-triggered cycle; "workers may be restarted/recycled" reframed as "cycles start fresh each cron tick"). ADR-024/035/036 history is **not deleted** — each is annotated below with its superseded/amended status. **Doc 8 rewritten to Version 2.0** ("Background Processing & Execution Architecture"): introduces the Processing Engine model (WP-Cron trigger → relay batch → dispatch batch → projection batch → persist metrics → clean exit; stateless between executions; per-stage max batch sizes + execution-time budget + continuation across cron runs). **Preserved unchanged:** the Outbox→Relay→Dispatcher→Queue→Processing pipeline, per-event execution pipeline (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack), execution context, subscribers/handlers, registry-based resolution, aggregate-version ordering (DECISION J), visibility timeout (OPEN-4/DECISION R), replay (DECISION T), reconciliation (DECISION U), correlation/causation IDs (ADR-037), failure isolation, stateless processing, at-least-once delivery, four-connection topology (DECISION L Ruling 0), heartbeat current-state (DECISION P), metrics/progress without persistence (DECISION Q). **Removed:** long-running workers, supervisors, systemd/Supervisor/container restart policies, worker recycling/startup/shutdown lifecycle, multi-process worker pools, daemon health monitoring, heartbeat-as-liveness (heartbeat is reinterpreted as cycle-freshness/progress). **Concurrency:** overlapping cron cycles are safe via **existing guarantees only** (FOR UPDATE SKIP LOCKED + aggregate versioning + visibility timeout) — **no new locking mechanism introduced.** Health = processing freshness/progress; metrics replace `worker_uptime`/`restart_count` with cycles-completed / avg-cycle-duration / per-stage-throughput / queue-backlog / processing-lag. Recovery = next cron execution + queue durability + visibility timeout + replay + reconciliation. **Implementation class names retained** (`WorkerEngine`, `RelayWorkerStrategy`, `EventWorkerStrategy`, `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`) — defined as processing components invoked by WP-Cron; no rename proposed. Inserts an interstitial architecture session (ARCH-DOC8-V2) into the Session Map. Conflict report (Docs 1/2/3/4/5/7/9/10/11) recorded in the ADR body — no other doc edited by this ruling. |
| 1.21 | 2026-07-16 | **DECISION W (Onboarding & First-Run Backfill) — architect ruling 2026-07-16.** Adopts the Onboarding / First-Run feature into the frozen record. Records six rulings: **(a) UI stack — DECISION V (a) is AMENDED (globally): React + shadcn is adopted as THE admin UI stack** for HSP; build-artifact policy = **commit `dist/` to the repo** (npm build runs in dev/CI only; production deploy is a file copy — matches the CLAUDE.md robocopy step; no node/npm build step on the WordPress host); WPCS security rules (escape/sanitize/capability/nonce) apply at the **REST/ajax endpoints the React app calls**. The already-shipped OPSC-S1..S4 server-rendered PHP Operations Console remains as built (not rewritten by this ruling); all **new** admin UI — including onboarding — is React+shadcn. DECISION V (a)'s "server-rendered PHP + no node toolchain, React deferred to a future ADR" is **superseded** by this decision (this IS that ADR-equivalent ruling); DECISION V (a)'s provider/registry architecture (registries, provider contracts, `OperationsService` seam, ADR-047/048/052/053) is **unchanged** — only the frontend rendering technology changes. **(b) Backfill mechanism** — initial content migration is **full-reconciliation re-emission via `ReconciliationService` (DECISION U)** through the normal outbox→relay→dispatch→worker pipeline; **NO direct WP→PG copy path, no second repair path** (mirrors DECISION V (d): thin delegator + write-spy proof in the implementation DoD). **(c) Queue drain** — a **live worker heartbeat is a HARD PREREQUISITE**: onboarding will not trigger backfill unless a fresh `system.worker_heartbeats` row exists (DECISION P age check); workers drain the pipeline as normal (no in-request tick drain). **(d) Progress** — derived on-demand per DECISION Q (expected-count scan vs processed/projection counts); **zero new PG persistence**. Completion state = a single WP option `hsp_onboarding_state` in MySQL; **no schema change**. **(e) Placement** — onboarding is a lifecycle/setup surface under **`core/Onboarding/`** (NOT `core/Operations/` — keeps DECISION V (j) console-is-observability-only intact); contracts (if any) under `core/Contracts/`; delegates to ratified services only. **(f) Nav gating** — until `hsp_onboarding_state = complete`, the Operations + API Playground admin pages are **not registered/visible**; the onboarding page is the only HSP admin surface. Prerequisite checks (pgsql extension, PG constants in `wp-config`, PG reachable, migration-engine state, PHP version) **hard-block** progression. Inserts **ONB-S1** (preflight + onboarding page + nav gating + completion flag) and **ONB-S2** (backfill trigger + derived live progress + redirect to Operations) into IMPLEMENTATION_PLAN.md §5b, sequenced **before Phase 1B**. Implications table updated; CLAUDE.md SETTLED gains a DECISION W line; CLAUDE.md Coding Standard/folder notes reconciled to the React amendment. |
| 1.20 | 2026-07-15 | **DECISION V (Operations Console Adoption) — ratifies FLAG-PLANOPS1-1..11 (architect ruling 2026-07-15).** Adopts Doc 12 (Admin Operations Console) into the frozen record, conservatively scoped. Records: (a) FLAG-3 B — MVP console is **server-rendered PHP + minimal vanilla JS + WP native admin UI**; **no node/npm/bundler toolchain**; React deferred to a future ADR that must not alter the provider/registry architecture. (b) FLAG-4 A — coding standard settled: **PSR-12 for platform code; WPCS security requirements (escaping, sanitization, capabilities, nonces) at WordPress entry points only.** (c) FLAG-5 A — all console metrics **derived on-demand per DECISION Q** (processing rate, replay status, reconciliation status computed from existing operational data; **zero new persistence**). (d) FLAG-6 A — Replay/Reconcile console actions are **thin delegators** to `ReplayService` (DECISION T/S) and `ReconciliationService` (DECISION U); **no second repair path ever**; OPSC-S4 DoD must include a **write-spy proof** (zero direct `content.*`/`system.*` writes on the action path). (e) FLAG-7 A — **Flush Queue REMOVED** from the action set; any future queue maintenance must be replay-safe, never destructive deletion. (f) FLAG-8 C-modified — **no Restart Workers action**; console provides worker status, heartbeat, restart guidance, and runbook links only; worker lifecycle belongs to the process supervisor. (g) FLAG-10 A — console providers **reuse the delivery `DatabaseConnectionInterface`** (DECISION K); four-handle topology (DECISION L Ruling 0) unchanged; no new `pg_*` wrapper (DECISION E). (h) FLAG-11 A — operations contracts live under **`core/Contracts/Operations/`** (NOT `core/Operations/Contracts/`); Rule 5 holds verbatim; Doc 12 §3 tree superseded on this point. (i) FLAG-2 A — **`core/Operations/`** (lowercase) added to the canonical folder structure as infrastructure; Doc 2's tree amended by this ruling (Doc 2 not edited). (j) Architect philosophy clause (binding scope) — the console is an **observability/diagnostics interface, not an operational control plane**; restarting services/containers, managing OS processes, and infrastructure orchestration are permanently outside the plugin's scope. **FLAG-9 B:** ratifies **ADR-047, ADR-048, ADR-049, ADR-050, ADR-052, ADR-053** as entries below; **ADR-051 recorded as HELD** — not citable as authority — pending incorporation of the FLAG-7/8 rulings. **FLAG-1 A:** Doc 11 roadmap updated to add "Phase 1A – Expanded — Operations Console & Developer Experience" between Phase 1A and Phase 1B. Doc 12 promoted Draft → "Accepted (as amended by DECISION V)"; §21 self-freeze removed. Implications table updated. Inserts **OPSC-S1..S4** into the §5b Session Map. |
| 1.19 | 2026-07-13 | **DECISION U amended (design ratified) — reconciliation detection design fixed for OPS-S3 build.** Adds section "**DECISION U — Ratified Detection Design (v1.19)**": (1) **Comparison signal by mode** — hourly *drift* = source-timestamp/existence comparison (WP `post_modified_gmt` vs projection `updated_at`; existence vs projection presence/`deleted_at`); nightly *incremental* = recent window + **checksum recompute**; weekly *full* = whole corpus + checksum + orphan sweep. (2) **Taxonomy limitation** — WordPress terms carry **no modified timestamp**, so hourly drift for categories is **existence-only**; category field-staleness (e.g. rename/description edit) is caught by the nightly/weekly **checksum recompute**, not hourly. (3) **Direction by mode** — hourly + nightly are **WP→PG only** (missed create/update); the **orphan sweep (PG→WP) runs in full mode only**; missed-**delete** repair latency is therefore **≤ weekly** at MVP (accepted). (4) **Suppression rule (final)** — an aggregate is **IN-FLIGHT (skip repair)** iff a **pending unrelayed `wp_hsp_outbox` row** exists for it **OR** a `system.events` row exists with `aggregate_version > system.aggregate_versions.latest_processed_version`; otherwise WP-newer-than-projection is a **genuine missed capture** (DECISION 1 gap) and is repaired. (5) **Executor = B1** — `ReconciliationWorkerStrategy::execute()` stays a producer-side no-op; cron/CLI invoke `reconcileDrift/Incremental/Full` in the (worker-bootstrapped) process, matching the `ReplayWorkerStrategy` precedent. (6) **New core contract `WpReconciliationSourceInterface`** (Content-module implementation) for detection-side WP reads — symmetric with `ReplayEmitterInterface`, preserves Rule 5; contract-only, no schema change. (7) **Full sweep is unbounded, paged; page size config-driven.** Repair remains DECISION T `ReplayService::replayEntity` ONLY (no direct PG writes; WordPress wins by construction). No schema change, no fifth PG handle, no new `pg_*` wrapper. Implications rows updated. |
| 1.18 | 2026-07-13 | **DECISION U (Reconciliation MVP via DECISION T Re-emission) — ratifies FLAG-GATES3-1 Option A.** Inserts a new session **OPS-S3 "Reconciliation MVP"** into the IMPLEMENTATION_PLAN.md §5b Session Map immediately **before GATE-S3**; **GATE-S3 Depends-on changes from `GATE-S2` to `OPS-S3`**; **GATE-S3 DoD is UNCHANGED**. Reconciliation un-stubs `ReconciliationWorkerStrategy` and repairs delivery drift **only** by re-emission through the normal pipeline via the DECISION T primitive (`ReplayService`/`ReplayEmitterInterface` — synthetic re-emission through `wp_hsp_outbox` with a fresh `wp_hsp_aggregate_counters` version [DECISION 2], flowing relay → dispatch → worker, passing the DECISION J guard naturally). **Direct PostgreSQL projection writes as a repair path are PROHIBITED.** WordPress-wins (ADR-026/ADR-027/ADR-045; CLAUDE.md Rule 1) holds **by construction**: repair reads current WP state via the emitter and reprojects, never writing WP from PG. **Scope:** drift detection, incremental validation, full reconciliation. Reconciliation must detect (a) **missed captures** — a WP entity newer than, or absent from, the delivery state (the DECISION 1 post-commit-gap backstop), and (b) **orphans** — present in delivery but deleted/non-public in WP (repair = re-emit the `.deleted` event → DECISION I tombstone path). **Scheduling:** WP-Cron is authorized for MVP under the CLAUDE.md recovery-jobs carve-out; **workers remain the execution path — cron only triggers.** No new PG handle (DECISION L Ruling 0 topology frozen at four), no new raw `pg_*` wrapper (DECISION E), no direct PG repair writes. **Resolves FLAG-GATES3-1.** Implications table + Session Map amended. |
| 1.17 | 2026-07-12 | **DECISION T (Replay via Projection Repair by Synthetic Re-emission) — ratifies FLAG-OPSS2-1 Option A.** Entity and date-range replay emit a NEW event through `wp_hsp_outbox`, taking a NEW `aggregate_version` from `wp_hsp_aggregate_counters` (DECISION 2 atomic increment), flowing relay → dispatch → worker and passing the DECISION J stale guard **naturally** (new version > stored version). The guard is NOT weakened, bypassed, or made conditional. Historical `system.events` rows are never mutated or re-enqueued — replay **appends**, never rewrites (Doc 5 §26 immutability holds; Rule 3 outbox path holds). The replay emitter reads CURRENT WordPress state per aggregate (ADR-044/ADR-045 — WordPress wins): aggregate exists and is public → emit the existing OPEN-1 `.updated` type; missing or non-public → emit `.deleted` (correctly tombstones entities deleted during an outage window). No new event-type contracts. Date-range mode: `SELECT DISTINCT (aggregate_type, aggregate_id) FROM system.events` within the `[from, to]` window (read via the existing delivery `DatabaseConnectionInterface` handle — no fifth handle, DECISION L Ruling 0), then one synthetic emit per aggregate. Traceability: synthetic events carry `causation_id` referencing the replay operation; one `correlation_id` groups a replay run. This emit-through-outbox repair primitive is the same mechanism future reconciliation (ADR-026/027/045) will build on. **Supersedes** the IMPLEMENTATION_PLAN.md §5b "re-enqueue original event" wording for entity/date-range modes; single-event DLQ replay under DECISION S is unchanged. **Resolves FLAG-OPSS2-1.** No schema change; no new pg_* wrapper; no new PG handle. Implications table updated. |
| 1.16 | 2026-07-11 | OPS-S1 architect rulings (5). **Ruling 0 — Connection Topology Ratified:** the four-connection topology (relay PG, queue/worker runtime, delivery [DECISION K], dispatcher [DECISION L]) is FROZEN as final; no fifth handle ever without a new ADR; heartbeat publication is worker-runtime infrastructure on the existing worker-runtime connection; no new connection class or raw `pg_*` wrapper. Recorded as amendment to DECISION L; **resolves FLAG-P1AS6D-1**. **Ruling 1 — DECISION P (Worker Heartbeat Storage):** single current-state table `system.worker_heartbeats` (upsert per tick, no history); `DatabaseHeartbeatPublisher` implements existing `HeartbeatPublisherInterface`, connection via constructor injection (ADR-012); migration authorized for OPS-S1. **Ruling 2 — DECISION Q (Metrics Without Persistence):** no metrics table/rollups/external telemetry in MVP; derived metrics computed on demand; runtime counters emitted as structured worker log events; "metrics emit" DoD = queryable operational status + structured log output. **Ruling 3 — DECISION R (Visibility-Timeout Recovery Driver):** `MaintenanceWorkerStrategy` drives `requeueTimedOut()`; cadence config-driven, no hardcoded timing. **Ruling 4 — DECISION S (DLQ Replay Lifecycle):** DLQ rows are permanent audit records (never deleted); replay is one PG transaction (verify exists → verify not replayed → DELETE any `system.queue_jobs` row sharing `event_id` → INSERT fresh job attempts=0 → stamp `replayed_at`); passes DECISION J stale guard; WP-CLI surface only. `replayed_at` is **absent** from the OPEN-3 v1.1 DLQ schema (migration 0004) — adding it is authorized within OPS-S1 migration scope. Implications table updated. |
| 1.15 | 2026-06-25 | DECISION O: credential resolution — `define()` constant → `getenv()` fallback → documented default; required-PG-missing fails loud; MySQL derives from WP `DB_*` constants by default; one `CredentialResolver` in `bootstrap/`; provider factories read resolver, not `getenv()` directly; `wp-config.php` uses `define()` for HSP PG credentials (no `putenv()`). |
| 1.14 | 2026-06-25 | DECISION N: delivery REST namespace is `hsp/v1` (vendor-prefixed WP convention). Renames `api/v1` to `hsp/v1` in `ContentRestRegistrar::NAMESPACE` constant, `hsp-blog/lib/api.ts` fetch paths, and `tools/smoke_e2e.php` curl paths. Doc sites reconciled (DECISION F Implements table, IMPLEMENTATION_PLAN.md §4 endpoint bullets and pipeline diagram, Phase 1A DoD, FLAG-P1AS5-1 flag text). |
| 1.1 | 2026-06-21 | OPEN-3, OPEN-4, OPEN-5, OPEN-7: column-type canon (TIMESTAMPTZ / VARCHAR(64) / UUID). DECISION 2: counter storage moved from postmeta/termmeta to dedicated `wp_hsp_aggregate_counters` table. Implications table updated. |
| 1.2 | 2026-06-21 | Timestamp canon scoped by engine (PostgreSQL `TIMESTAMPTZ` vs MySQL `DATETIME`-UTC); type canon bound explicitly to ALL tables including module-owned `content.*`, superseding Doc 3 §9–11. Phase 0 freeze-check wording corrected so MySQL `DATETIME` columns are not flagged as violations. Implications table annotated with MySQL timestamp types and a note that `content.*` tables inherit v1.2 canon with freeze check at Phase 1A DoD. |
| 1.3 | 2026-06-21 | OPEN-6: froze `wp_hsp_outbox` column-level DDL (previously "new table" only). Added `source_updated_at` (was missing — required to populate `system.events` OPEN-5 column). Pinned relay fidelity: `event_id` and `created_at` (capture time) are preserved unchanged from outbox into `system.events`. Implications table MySQL row updated to reference v1.3 frozen DDL. |
| 1.4 | 2026-06-21 | DECISION A: `dead_letter_jobs.payload_snapshot` changed to `NOT NULL`; raw payload must always be preserved. OPEN-8: froze `system.schema_versions`, `system.module_versions`, `system.security_events` DDL (were Doc-3-underspecified). OPEN-9: `ModuleInterface` is the union of declarative discovery + WP lifecycle methods, supersedes Doc 2 §12. DECISION D: `AdapterInterface` adds `bulkPersist()` per Doc 7 §19. |
| 1.5 | 2026-06-22 | DECISION E: shared runtime PostgreSQL connection layer; resolves FLAG-P0S5-1. Consolidation deferred to P0-S7; P0-S6 binding constraint (no new raw `pg_*` wrapper). |
| 1.6 | 2026-06-23 | DECISION E: resolved FLAG-P0S7-1 (Option 1 — Split). Queue collapses fully into `DatabaseConnectionInterface`. Outbox splits by persistence technology: PG delivery path on shared `DatabaseConnectionInterface`; MySQL capture path on a new `MysqlOutboxConnectionInterface` that does NOT extend or reference `DatabaseConnectionInterface`. `OutboxConnectionInterface` and `QueueConnectionInterface` deleted. |
| 1.7 | 2026-06-23 | OPEN-11: Option A — Phase 1A projection is a lossless representation of the canonical model; adapter persists the canonical checksum directly; no second checksum path; divergent projections require a future ADR. Resolves FLAG-P1AS3-1. |
| 1.8 | 2026-06-23 | FLAG-P1AS4-1 resolved (architect ruling): content.entity_taxonomies is a pure join table — (entity_id UUID, taxonomy_id UUID) composite PK only; no timestamps/checksums/metadata unless a future ADR adds relationship attributes. FLAG-P1AS4-2 resolved (architect ruling): system.aggregate_versions uses a monotonic guarded upsert — stored version only ever advances (max(current, incoming)); worker owns stale-event detection; DB guard is defense-in-depth. |
| 1.9 | 2026-06-24 | DECISION F: REST Delivery API contracts — scoped Option A (P1A-S5). Four core contracts added to `core/Contracts/`: `QueryProviderInterface`, `ResourceInterface`, `FilterSet`, `CursorPage`. ADR-038 transport-agnosticism enforced: no WP/HTTP types in contracts, Query Providers, or Resources — WP types confined to REST route registration only. Cursor pagination uses (primary_sort, id) deterministic tiebreaker. status filter constrained to public set {publish} (OPEN-10); non-public values return 400. category filter on /posts resolves via projection-side join (content.taxonomies.slug); never by WP term_id. IMPLEMENTATION_PLAN.md §4 five-bullet undercount flagged (categories/{slug} missing). |
| 1.10 | 2026-06-24 | DECISION H: Worker State Loading — Option B approved; reaffirms ADR-044 (state-sync, not event-sourcing); workers reload current WordPress state via defined WP bootstrap path in worker runtime; event payload enrichment (Option A) rejected (contradicts ADR-044 + reconciliation principle); direct-MySQL reload (Option C) rejected (bypasses WordPress as authoritative access layer). Resolves FLAG-P1AS6-1 (worker state question). DECISION I: Delete Processing — Option C approved; content.*.deleted events follow dedicated tombstone path consuming only event envelope (aggregate identity + metadata); soft-delete projection performed; no reload, no extract, no transform; canonical models and canonical-checksum surface UNCHANGED; OPEN-11 intact; AdapterInterface gains tombstone/soft-delete method (contract change). DECISION J: Stale-Event Guard — amends FLAG-P1AS4-2; Resolve-stage guard is PRIMARY, authoritative stale-event gate; adapter in-txn FOR UPDATE + GREATEST guard is MANDATORY defense-in-depth (Resolve reads outside write txn and cannot close the Resolve→write TOCTOU window alone); authorizes for P1A-S6b: PG read dependency on EventWorkerStrategy, WorkerServiceProvider wiring, Resolve-stage aggregate-version lookup, early termination before handler execution. |
| 1.11 | 2026-06-24 | DECISION K: Delivery Connection Isolation — resolves FLAG-P1AS6A-1. A shared non-FORCE_NEW connection that can libpq-reuse the relay/queue handle is not acceptable where it can undermine the Resolve-stage gate (DECISION J). Delivery reads, Resolve-stage reads, and adapter persistence use one dedicated delivery connection with guaranteed physical separation from relay/queue handles (PGSQL_CONNECT_FORCE_NEW). Sequential reuse within a worker tick is acceptable; cross-sharing with relay and queue-claim handles is prohibited. The binding lives in a new `DeliveryServiceProvider`, not `QueueServiceProvider`. No new raw pg_* wrapper — reuses `PostgresDatabaseConnection`. Constrains DECISION E (v1.6) connection-ownership allocation; satisfies DECISION J (v1.10) Resolve-read isolation requirement. |
| 1.12 | 2026-06-25 | DECISION L: Dispatcher stage — architect ruling 2026-06-25. Dispatcher is a distinct stage in the pipeline (Outbox → Dispatcher → Queue → Worker), implemented as a `WorkerStrategyInterface` on the existing Worker Engine under `core/Events/Dispatcher/`. Dedup via `UNIQUE(event_id)` on `system.queue_jobs` + `ON CONFLICT(event_id) DO NOTHING` on enqueue. Undispatched events claimed by anti-join (`NOT EXISTS (SELECT 1 FROM system.queue_jobs q WHERE q.event_id = e.event_id)`) against `system.queue_jobs`; no dispatch-status column on `system.events`; no watermark. Correct-final-state ordering; no FIFO requirement. <30s SLA unchanged. Dispatcher opens its own dedicated FORCE_NEW handle (`'dispatcher.connection.pgsql'`, via `PostgresDatabaseConnection`) physically distinct from the DECISION K delivery singleton and relay/queue handles; enqueues via `DatabaseQueueProvider::enqueueIdempotent()` (queue-claim handle). No new raw `pg_*` wrapper class (DECISION E). PID-distinctness asserted in integration test. |
| 1.13 | 2026-06-25 | DECISION L clause (g) reconciled: amended amendment-log entry for v1.12 to correctly state dispatcher opens its own FORCE_NEW `'dispatcher.connection.pgsql'` handle (NOT the DECISION K delivery singleton). The full DECISION L text in §DECISION L already stated this correctly (clause g); only the amendment-log summary row was wrong. Raises FLAG-P1AS6D-1: no container binding exposes a relay/queue runtime PG handle separately from the delivery singleton after S6c; the dispatcher's dedicated handle is a connection-topology decision pending architect ratification. |
| 1.14 | 2026-06-25 | DECISION N: delivery REST namespace is `hsp/v1` (vendor-prefixed WP convention). Renames `api/v1` to `hsp/v1` in `ContentRestRegistrar::NAMESPACE` constant, `hsp-blog/lib/api.ts` fetch paths, and `tools/smoke_e2e.php` curl paths. Doc sites reconciled (DECISION F Implements table, IMPLEMENTATION_PLAN.md §4 endpoint bullets and pipeline diagram, Phase 1A DoD, FLAG-P1AS5-1 flag text). |

---

## Table of Contents

1. [Open Items (OPEN-1 through OPEN-11)](#open-items)
2. [Decisions (DECISION 1 through DECISION 3, DECISION A, DECISION D through DECISION J)](#decisions)
3. [Implications Carried into Schema](#implications-carried-into-schema)

---

## Open Items

### OPEN-1 — Event Naming Convention

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 1 §6, Doc 4 §8 (bare-name event examples) |

**Ruling:** All events use fully-qualified `<domain>.<aggregate>.<action>` naming.

MVP event types:
- `content.page.created` / `content.page.updated` / `content.page.deleted`
- `content.post.created` / `content.post.updated` / `content.post.deleted`
- `content.category.created` / `content.category.updated` / `content.category.deleted`

**Rationale:** Namespaced names eliminate collision risk across domains and make routing rules unambiguous without inspecting payload.

---

### OPEN-2 — system.aggregate_versions Table

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §4 |

**Ruling:** Add table `system.aggregate_versions` with primary key `(aggregate_type, aggregate_id)` and columns `latest_processed_version BIGINT` and `latest_processed_at TIMESTAMPTZ`.

**Rationale:** Enables stale-event skipping at the worker level without a full scan of `system.processed_events`.

---

### OPEN-3 — Expanded system.dead_letter_jobs

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 3 §22 |

**Ruling:** `system.dead_letter_jobs` gains four additional columns: `stack_trace TEXT`, `attempt_count INTEGER`, `worker_id UUID`, `payload_snapshot JSONB`.

**Rationale:** Operational debuggability requires the full failure context at the time of terminal failure, not just a message.

> **Amendment (v1.1 — 2026-06-21):** `worker_id` type changed from `TEXT` to `UUID`. Platform-wide column-type canon (supersedes Doc 3): all timestamps use `TIMESTAMPTZ` (bare `TIMESTAMP` drops the UTC offset); all checksums use `VARCHAR(64)` (sha256 is fixed-width); all worker identity columns use `UUID` (consistent with UUIDv7 identity per ADR-015). Workers self-assign a UUIDv7 at startup.

> **Amendment (v1.2 — 2026-06-21):** The v1.1 timestamp canon is engine-scoped. `TIMESTAMPTZ` is a PostgreSQL type and **must not** appear in MySQL migrations. The corrected platform-wide canon is:
>
> - **PostgreSQL timestamp columns** → `TIMESTAMPTZ`. No bare `TIMESTAMP` permitted.
> - **MySQL timestamp columns** (`wp_hsp_outbox.created_at`, `wp_hsp_outbox.relayed_at`, and any future MySQL timestamp columns) → `DATETIME`, written and read as UTC. UTC discipline is enforced at the application layer. MySQL `TIMESTAMP` is acceptable only if UTC auto-normalization is explicitly desired; default to `DATETIME`-UTC.
>
> The checksum canon (`VARCHAR(64)`) and worker-identity canon (`UUID`) are unchanged; both apply only on the PostgreSQL side where those column types are meaningful.
>
> **Scope:** The type canon applies platform-wide to **all** tables, including **module-owned delivery tables** (`content.pages`, `content.posts`, `content.taxonomies`, `content.media`, and any future module projection tables). It supersedes Doc 3 §9–11, which show bare `TIMESTAMP`. Module-owned tables are not enumerated in the Implications table below because they are generated in Phase 1A, but they inherit this canon and are subject to the same freeze rule. Their freeze check occurs at the Phase 1A DoD gate.

> **Amendment (v1.4 — 2026-06-21):** `payload_snapshot` is `NOT NULL` (see DECISION A). If a payload cannot be parsed to structured JSON, the raw captured representation must be persisted in a serializable form rather than omitted. Rationale: every DLQ entry must be self-contained and replayable without access to any external store.

---

### OPEN-4 — system.queue_jobs Claiming Protocol

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §21 |

**Ruling:** `system.queue_jobs` gains columns `worker_id UUID` and `visibility_timeout_at TIMESTAMPTZ`. Job claiming uses `SELECT … FOR UPDATE SKIP LOCKED`. Visibility timeout duration is config-driven. A recovery process requeues jobs whose `visibility_timeout_at` has expired without completion.

**Rationale:** `SKIP LOCKED` eliminates queue-head blocking under concurrent workers; visibility timeout prevents permanent job loss from worker crashes.

> **Amendment (v1.1 — 2026-06-21):** `worker_id` type changed from `TEXT` to `UUID`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-5 — Hybrid Event Store Schema

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 3 §20 |

**Ruling:** `system.events` uses a hybrid layout. The following fields are **first-class columns**: `aggregate_version BIGINT`, `source_updated_at TIMESTAMPTZ`, `checksum VARCHAR(64)`, `correlation_id UUID`, `causation_id UUID`. All remaining metadata stays inside the `payload JSONB` column.

**Rationale:** Promotes the fields needed for indexing, dedup, and traceability to queryable columns while avoiding schema churn for ad-hoc metadata.

> **Amendment (v1.1 — 2026-06-21):** `checksum` type changed from `TEXT` to `VARCHAR(64)`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-6 — Transactional Outbox and Cross-DB Relay

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Docs 3 and 5 |

**Ruling:** The transactional outbox lives in WordPress MySQL as `wp_hsp_outbox`. A `RelayWorkerStrategy` copies rows to `system.events` in PostgreSQL. A row is marked `relayed` on `wp_hsp_outbox` **only after** the PostgreSQL commit succeeds. The MySQL claim query uses `SKIP LOCKED`. `system.events` is the **durable relayed copy**, not the capture point.

**Rationale:** Resolves the cross-database transaction boundary: write durability is achieved via the WP-side outbox; PG-side events are the authoritative relay target for all downstream consumers.

> **Amendment (v1.3 — 2026-06-21):** The original ruling established the outbox's role and relay behaviour but left the column-level DDL unspecified. This amendment freezes it.
>
> The outbox must be a **superset** of every first-class column `system.events` requires (OPEN-5), so the relay is a pure copy with no field reconstruction. Frozen `wp_hsp_outbox` DDL (MySQL, `{$wpdb->prefix}hsp_outbox`):
>
> ```sql
> id                CHAR(36)     NOT NULL,                 -- the event_id; born here
> event_type        VARCHAR(255) NOT NULL,
> event_version     INT          NOT NULL,
> aggregate_type    VARCHAR(100) NOT NULL,
> aggregate_id      VARCHAR(255) NOT NULL,
> aggregate_version BIGINT       NOT NULL,
> source_updated_at DATETIME     NOT NULL,                 -- UTC; populates system.events.source_updated_at
> checksum          CHAR(64)     NOT NULL,
> correlation_id    CHAR(36)     NOT NULL,
> causation_id      CHAR(36)     NULL,                     -- NULL for root events (Doc 8 §19–20)
> payload           JSON         NOT NULL,
> status            ENUM('pending','relayed') NOT NULL DEFAULT 'pending',
> created_at        DATETIME     NOT NULL,                 -- capture time (UTC)
> relayed_at        DATETIME     NULL,
> PRIMARY KEY (id),
> INDEX idx_relay_claim (status, created_at)              -- relay claim path
> ```
>
> **Relay fidelity rules** (`RelayWorkerStrategy`):
>
> - `system.events.id` := `wp_hsp_outbox.id` — the `event_id` is **preserved unchanged**. Do NOT generate a new UUID on relay; dedup in `system.processed_events` is keyed on `event_id`.
> - `system.events.created_at` := `wp_hsp_outbox.created_at` — this is the **capture time**, not the relay time. Relay time is recorded only in `wp_hsp_outbox.relayed_at`.
> - All OPEN-5 first-class columns (`aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`, `causation_id`) copy straight across. Type casts on relay: MySQL `CHAR(36)` → PG `UUID`; MySQL `CHAR(64)` → PG `VARCHAR(64)`.
> - `wp_hsp_outbox.relayed_at` is set to the relay capture time **only after** the PostgreSQL commit succeeds (original OPEN-6 ruling preserved).
>
> **Note on `source_updated_at`:** this field was absent from prior OPEN-6 descriptions but is required by OPEN-5 as a first-class column on `system.events`. Its addition here closes that gap; no other ruling is changed.

---

### OPEN-7 — system.processed_events for Exact-Event Dedup

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 |

**Ruling:** Add table `system.processed_events` with primary key `event_id`, plus columns `checksum VARCHAR(64)` and `processed_at TIMESTAMPTZ`. This table serves exact-event idempotency and is distinct from `system.aggregate_versions` (which serves stale-version skipping).

**Rationale:** Two orthogonal dedup concerns require two distinct mechanisms; conflating them produces incorrect behaviour for out-of-order replays.

> **Amendment (v1.1 — 2026-06-21):** `checksum` type changed from `TEXT` to `VARCHAR(64)`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-8 — Frozen DDL for system.schema_versions, system.module_versions, system.security_events

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §4/§24 |

**Ruling:** Doc 3 §4/§24 described the intent of these three tables but provided no column-level DDL. This entry freezes their DDL. All timestamps are `TIMESTAMPTZ` (v1.2 canon); all checksums are `VARCHAR(64)` (v1.1 canon).

**system.schema_versions** — tracks applied migrations and rollback state (Doc 3 §24):

```sql
id             UUID         NOT NULL,
migration_name VARCHAR(255) NOT NULL,
schema_context VARCHAR(100) NOT NULL,  -- engine-qualified: 'core/mysql', 'core/pgsql', 'content/pgsql', etc.
applied_at     TIMESTAMPTZ  NOT NULL,
rolled_back_at TIMESTAMPTZ  NULL,      -- NULL = currently applied
checksum       VARCHAR(64)  NOT NULL,  -- sha256 of migration file at apply time
PRIMARY KEY (id),
UNIQUE (migration_name, schema_context)
```

**system.module_versions** — tracks module schema version history (Doc 3 §24):

```sql
id             UUID         NOT NULL,
module_name    VARCHAR(100) NOT NULL,  -- e.g. 'content', 'commerce'
schema_version VARCHAR(50)  NOT NULL,  -- semantic version string, e.g. '1.0.0'
applied_at     TIMESTAMPTZ  NOT NULL,
notes          TEXT         NULL,
PRIMARY KEY (id),
UNIQUE (module_name, schema_version),
INDEX (module_name, applied_at DESC)
```

**system.security_events** — infrastructure security audit trail (Doc 3 §4):

```sql
id          UUID         NOT NULL,
event_type  VARCHAR(100) NOT NULL,  -- fully-qualified security.<aggregate>.<action>
severity    VARCHAR(20)  NOT NULL,  -- e.g. 'low', 'medium', 'high', 'critical'
actor_type  VARCHAR(50)  NULL,      -- e.g. 'user', 'worker', 'api_key'; NULL for unauthenticated
actor_id    VARCHAR(255) NULL,      -- platform actor identifier; VARCHAR to support non-UUID actors
ip_address  VARCHAR(45)  NULL,      -- IPv4 or IPv6
metadata    JSONB        NOT NULL,
created_at  TIMESTAMPTZ  NOT NULL,
PRIMARY KEY (id),
INDEX (event_type, created_at)
```

**Rationale:** `actor_id` uses `VARCHAR(255)` rather than `UUID` because the security event log must accommodate unauthenticated actors, API keys, and external identifiers that are not UUIDs. `severity` is a required triage field. `actor_type` disambiguates the actor_id namespace.

---

### OPEN-9 — ModuleInterface Union Shape

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 2 §12 |
| **Adds to** | — |

**Ruling:** `ModuleInterface` is the **union** of declarative discovery methods and WordPress lifecycle methods. Neither set replaces the other; both must be present.

Declarative discovery (used by module registry at boot):
- `getName(): string`
- `getServiceProvider(): ServiceProviderInterface`
- `getMigrations(): array`
- `getEventTypes(): array`

WordPress lifecycle (called by the module registry in order):
- `register(): void` — register DI bindings and WordPress hooks; called before `boot()`
- `boot(): void` — called after all modules have registered; use for cross-module-safe initialization
- `activate(): void` — called on plugin activation (install migrations, seed config, register capabilities)
- `deactivate(): void` — called on plugin deactivation (remove runtime registrations; do NOT drop data)
- `upgrade(): void` — called on plugin version bump (run pending migrations, apply version-specific transforms)

**Rationale:** Discovery and lifecycle solve different problems. Separating them into two interfaces would require the registry to hold two references per module and keep them in sync. The union interface keeps the module as a single cohesive unit while making all registry obligations explicit at the type level. Doc 2 §12 described lifecycle only; this ruling adds the declarative side.

---

### OPEN-11 — Canonical Checksum vs Projection Checksum

| Field | Value |
|---|---|
| **Status** | **Accepted (2026-06-23)** |
| **Raised** | 2026-06-23 — P1A-S3 close |
| **Resolves** | FLAG-P1AS3-1 |

#### Ruling (Option A — Phase 1A projection is a lossless representation of the canonical model)

**Phase 1A projection rule:** The delivery projection contains exactly the delivery fields represented by the canonical model — no canonical field omitted, no derived columns added. Explicitly excluded from Phase 1A projections: precomputed URI variants, search vectors, denormalized aggregates, analytics/ranking columns.

**Checksum rule:** The adapter persists `model.getChecksum()` (the canonical checksum) **directly** as the stored `content.*` checksum. Write-suppression compares the stored `content.*` checksum against the canonical checksum. No second/projection-shaped checksum path is permitted in Phase 1A.

**Scope and limits:** This ruling does NOT establish that all schemas must always mirror canonical models. It establishes that WHERE a projection is a lossless representation of the canonical model, the canonical checksum is the authoritative checksum. When a future projection intentionally diverges (search/analytics/cache/reporting/denormalized read models), that projection becomes responsible for computing and persisting its own projection checksum — and that divergence requires a future ADR before implementation.

**Relationship to DECISION 3:** OPEN-11 clarifies, does not supersede, DECISION 3. DECISION 3's "freshly-computed projection checksum" equals the canonical checksum for Phase 1A precisely because the projection is lossless. The three-op single-PG-transaction rule (projection upsert + `system.processed_events` insert + `system.aggregate_versions` upsert) is unchanged.

---

### FLAG-P1AS4-1 — content.entity_taxonomies Column Shape

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 kickoff |

**Ruling (architect, 2026-06-23):** `content.entity_taxonomies` is a pure join table for Phase 1A — exactly `(entity_id UUID, taxonomy_id UUID)`, composite PK, no timestamps/checksums/metadata unless a future ADR adds relationship attributes.

---

### FLAG-P1AS4-2 — aggregate_versions Upsert Monotonicity

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 kickoff |

**Ruling (architect, 2026-06-23):** `system.aggregate_versions` uses a monotonic guarded upsert — stored version only ever advances (max(current, incoming)). Worker owns stale-event detection; the DB guard is defense-in-depth so aggregate progress can never regress.

---

### FLAG-P1AS4-3 — `bulkPersist()` version guard and event recording

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 close |

**Ruling (architect, 2026-06-23):** `persist()` is the ONLY supported persistence entry point in Phase 1A. `bulkPersist()` stays on `AdapterInterface` as a declared future capability (signature unchanged) but performs NO projection writes in Phase 1A. All three Phase 1A adapters (PageAdapter, PostAdapter, CategoryAdapter) implement `bulkPersist()` as a fail-fast stub: `throw new \LogicException('bulkPersist() is not implemented in Phase 1A.');` — no transaction, no projection write, no partial path. The correct guarded batch path (events + version context, same guarantees as `persist()`) is deferred to a future ADR that lands with the first batch-with-events caller. No reconciliation, replay, or worker path may call `bulkPersist()` in Phase 1A.

---

## Decisions

### DECISION 1 — Near-Atomic Capture + Reconciliation Backstop (ADR-029 Revised)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | ADR-029 (Doc 5) |

**Ruling:** WordPress exposes no universal transaction boundary that can wrap an editorial write and an outbox insert atomically. Therefore: capture writes to `wp_hsp_outbox` immediately **after** the WordPress commit completes. The "never lose a sync" guarantee rests on four pillars in sequence: (1) durable outbox write, (2) at-least-once relay to `system.events`, (3) event replay capability, and (4) periodic reconciliation where WordPress is the system of record (ADR-027, ADR-045). ADR-029's assumption of a true atomic capture is revised away.

**Rationale:** Post-commit outbox write is the only safe option given WordPress's plugin hook architecture; reconciliation closes the narrow gap between WP commit and outbox write.

---

### DECISION 2 — aggregate_version as Per-Aggregate Source Counter (ADR-021 Clarification)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Clarifies** | ADR-021 |

**Ruling (v1.0 — superseded storage only):** ~~`aggregate_version` is a per-aggregate monotonic counter stored in WordPress source metadata: key `_hsp_version` in `wp_postmeta` (for pages and posts) and `wp_termmeta` (for categories). The increment **must** be atomic at the database level (e.g., `UPDATE … SET meta_value = meta_value + 1`). Using `update_post_meta` / `update_term_meta` read-modify-write is prohibited because the race condition produces duplicate version numbers and breaks the stale-skip logic in `system.aggregate_versions`.~~

> **Amendment (v1.1 — 2026-06-21):** The postmeta/termmeta storage in v1.0 is superseded. `wp_postmeta` and `wp_termmeta` have no unique key on `(object_id, meta_key)`, `meta_value` is `LONGTEXT`, and a bare `UPDATE` on a not-yet-existing `_hsp_version` row affects zero rows — reintroducing the exact duplicate-version race this decision was written to prevent. The counter therefore moves to a dedicated MySQL table in the WordPress database:
>
> ```sql
> {$wpdb->prefix}hsp_aggregate_counters (
>   aggregate_type VARCHAR(100) NOT NULL,
>   aggregate_id   VARCHAR(255) NOT NULL,
>   version        BIGINT       NOT NULL,
>   PRIMARY KEY (aggregate_type, aggregate_id)
> )
> ```
>
> Atomic increment + read in one round-trip:
>
> ```sql
> INSERT INTO {$wpdb->prefix}hsp_aggregate_counters
>   (aggregate_type, aggregate_id, version)
> VALUES (?, ?, 1)
> ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1);
> -- then: SELECT LAST_INSERT_ID();
> ```
>
> The returned value is the `aggregate_version` written to the outbox row and relayed into `system.events`. The intent of v1.0 is unchanged (per-aggregate monotonic counter in the WP source DB, genuinely atomic at the SQL level); only the storage location changes.

**Rationale (unchanged):** Application-layer read-modify-write under concurrent saves cannot guarantee uniqueness; a single SQL atomic operation does.

---

### DECISION 3 — Idempotency via Projection Checksum (ADR-025 Implementation)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Implements** | ADR-025 |

**Ruling:** `system.processed_events` is retained. Write-suppression logic compares a **freshly-computed projection checksum** against the stored `content.*` checksum in the target store — **not** the event's own checksum (which is traceability only, not dedup). The worker's three operations — projection upsert, `system.processed_events` insert, and `system.aggregate_versions` upsert — **must** commit inside a single PostgreSQL transaction.

> **See OPEN-11:** For Phase 1A lossless projections, "freshly-computed projection checksum" equals `canonical.getChecksum()`. The three-op transaction rule is unchanged.

**Rationale:** Event-checksum dedup fails for legitimate re-deliveries carrying different event IDs; projection-checksum dedup correctly suppresses writes whose observable output would be identical.

---

### DECISION A — dead_letter_jobs.payload_snapshot NOT NULL

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Amends** | OPEN-3 (v1.4) |

**Ruling:** `system.dead_letter_jobs.payload_snapshot` is `NOT NULL`. If the job payload cannot be parsed to structured JSON at failure time, the raw captured representation must be serialized into a form that can be stored as JSONB (e.g. wrapped as `{"raw": "<escaped string>"}`) rather than omitting it. An adapter that sets `payload_snapshot = NULL` violates this ruling.

**Rationale:** Every DLQ entry must be self-contained and replayable without access to any external store. A NULL payload_snapshot makes root-cause diagnosis and replay impossible, defeating the purpose of the dead letter queue.

---

### DECISION D — AdapterInterface includes bulkPersist()

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Implements** | Doc 7 §19 |

**Ruling:** `AdapterInterface` exposes both `persist(CanonicalModelInterface $model, EventInterface $event): void` and `bulkPersist(array $models): void`. `bulkPersist()` is a **capability declaration**, not a strategy mandate: a conforming adapter may implement it by looping `persist()` internally. Bulk SQL, batch upserts, and single-transaction semantics for bulk operations are implementation-defined and specified at the adapter implementation task, not here.

**Rationale:** Doc 7 §19 requires adapters to support bulk operations for reconciliation, full replay, and bulk import workflows. Specifying the method at the interface level ensures all adapters are capable of serving those callers without requiring callers to know the adapter's implementation strategy.

---

### DECISION E — Shared Runtime PostgreSQL Connection Layer (resolves FLAG-P0S5-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 4 §12; Doc 2 (core infrastructure layout) |
| **Resolves** | FLAG-P0S5-1 |

**Ruling:** Runtime DML subsystems (outbox relay, queue provider, worker infrastructure, and future runtime services) share a single runtime PostgreSQL connection abstraction. The migration engine is explicitly excluded and retains its own migration-specific abstraction (`ConnectionInterface`, `execute(string $sql): void`, DDL-only) — its DDL/lifecycle/error semantics differ and must stay isolated.

Consolidation is deferred to P0-S7. No consolidation occurs during P0-S5 or P0-S6. The three existing `pg_*` wrappers (`PgsqlConnection` [migrations], `PgsqlOutboxConnection`, `DatabaseQueueConnection`) are an accepted temporary duplication, not a permanent pattern.

**P0-S6 constraint (binding):** P0-S6 introduces NO additional raw `pg_*` wrapper class. The worker obtains PostgreSQL access through an existing runtime provider/connection (e.g. via `QueueProviderInterface`, Doc 4 §12), never a new low-level handle.

**P0-S7 authorized scope:** introduce a shared runtime `DatabaseConnectionInterface` (`execute`/`query`/`beginTransaction`/`commit`/`rollback`) + one shared PG implementation under `core/Database/`; collapse `OutboxConnectionInterface` and `QueueConnectionInterface` into it; replace the duplicated runtime wrappers with the shared implementation; the connection layer throws a single infrastructure `DatabaseException`, which subsystems may translate to `QueueException` / `OutboxWriteException` / `WorkerException` at their boundary. Migration engine untouched. This is consolidation only — behaviour, transaction semantics, and test coverage must remain unchanged.

> **Amendment (v1.6 — 2026-06-23 — FLAG-P0S7-1 Option 1 — Split):**
>
> `DatabaseConnectionInterface` is **PostgreSQL-only**. No MySQL connection may implement or extend it.
>
> **Queue (collapse):** `QueueConnectionInterface` is deleted. `DatabaseQueueConnection` and `DatabaseQueueProvider` depend directly on `DatabaseConnectionInterface`. `DatabaseException` is translated to `QueueException` at the `DatabaseQueueConnection` boundary.
>
> **Outbox (split by persistence technology):** `OutboxConnectionInterface` is deleted. The dual-technology outbox path is split into two distinct abstractions:
>
> - **PG delivery path:** `PgsqlOutboxConnection` implements `DatabaseConnectionInterface` directly (same shared layer as queue). `DatabaseException` is translated to `OutboxWriteException` at the `PgsqlOutboxConnection` boundary.
> - **MySQL capture path:** `MysqliOutboxConnection` implements a new `MysqlOutboxConnectionInterface` scoped to `core/Events/Outbox/Connection/`. This interface does NOT extend or reference `DatabaseConnectionInterface` — it is MySQL-only and carries its own `OutboxWriteException` error semantics.
>
> `RelayWorkerStrategy` holds one `MysqlOutboxConnectionInterface` (MySQL capture) + one `DatabaseConnectionInterface` (PG delivery) and coordinates the two explicitly — it does not treat them as one abstraction.
>
> **Rollback semantics (historical, binding):** Both original `PgsqlOutboxConnection::rollback()` (P0-S4, commit `084456a`) and `DatabaseQueueConnection::rollback()` (P0-S5, commit `084456a`) swallowed `pg_query('ROLLBACK')` failures silently — false return was ignored, no exception thrown. `PostgresDatabaseConnection::rollback()` preserves this behaviour exactly.

**Rationale:** Core owns reusable runtime infrastructure; subsystems must not each reinvent it. Capping proliferation at three and consolidating at the freeze gate avoids refactor risk during active implementation while preventing the pattern from entrenching. The split (v1.6) reflects that MySQL and PostgreSQL have fundamentally different connection, transaction, and error models — a single interface spanning both would be a leaky abstraction.

---

### DECISION F — REST Delivery API Contracts (P1A-S5)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Session** | P1A-S5 (2026-06-24) |
| **Authority** | Doc 9 §6 (ownership), §12 (filtering), §13 (pagination), ADR-038 (transport-agnostic), ADR-040 (consumer boundary) |
| **Implements** | Six Phase 1A read endpoints: GET /hsp/v1/pages, /hsp/v1/pages/{slug}, /hsp/v1/posts, /hsp/v1/posts/{slug}, /hsp/v1/categories, /hsp/v1/categories/{slug} |

**Ruling — Option A (scoped):** Four contracts added to `core/Contracts/` to satisfy Doc 9 §6 while keeping scope to what the six read endpoints exercise:

- **`QueryProviderInterface`** — `list(FilterSet): CursorPage` + `findBySlug(string): ?array`. Implementations MUST query delivery projections only (no WordPress reads — ADR-040). Implementations live in modules.
- **`ResourceInterface`** — `toArray(array): array` + `toCollection(array, ?string): array`. Serialization only; no business logic; no internal columns leaked. Implementations live in modules.
- **`FilterSet`** — Immutable value object carrying validated filter parameters (slug, status, categorySlug, publishedAfter, cursor, limit). Built by the REST registration boundary from sanitized request parameters.
- **`CursorPage`** — Immutable value object returned by `list()`; carries `$rows` and opaque `?$nextCursor`.

**Transport-agnosticism (ADR-038 — binding constraint):** No WP_REST_Request, WP_REST_Response, or any HTTP/framework type may appear in Query Providers or Resources. These types are confined to the REST route registration layer (`modules/Content/Rest/ContentRestRegistrar`). This preserves the query and resource layer for future transports (GraphQL, gRPC, etc.) without redesign.

**`TransportContract` and `SecurityContract` deferred:** These are Future/out-of-MVP scope (ADR-038 future transports; Doc 9 §22 authenticated endpoints). Building them now violates the no-Future-Vision rule.

**Cursor pagination design:** Opaque base64url token encoding `{ "s": "<primary_sort_value>", "id": "<uuid>" }`. Seek predicate uses `(primary_sort, id)` composite tiebreaker, proving no skipped or duplicated rows across page boundaries when rows share the primary sort value. Sort keys: `(published_at DESC, id DESC)` for pages/posts; `(name ASC, id ASC)` for categories.

**Default listing behavior:** `WHERE status = 'publish' AND deleted_at IS NULL` (OPEN-10 public set). The `?status=` filter accepts only values in the public set `{publish}`; any other value returns HTTP 400 (do NOT silently coerce). This is validated at the REST boundary, not inside Query Providers.

**Category filter on /posts:** The `?category=` filter resolves by category slug via a projection-side EXISTS subquery (`content.posts → content.entity_taxonomies → content.taxonomies.slug`). Never by WP term_id. Never in the Resource layer. (Architect ruling, P1A-S5.)

**`findBySlug` on missing/soft-deleted row:** returns `null`; REST boundary translates to 404. Empty 200 is prohibited.

**Internal column exclusion (ADR-040):** Resources expose ONLY contract fields. Internal columns (`id UUID`, `source_post_id`, `source_term_id`, `checksum`, `synced_at`, `created_at`, `taxonomy_type`, `*_jsonb` internals unless contractually intended) are never serialized into responses.

**Rationale:** Doc 9 §6 requires core to own API/Query/Serialization/Filtering contracts. Introducing all four contracts now satisfies the architectural principle without violating the MVP scope constraint (no Transport or Security contracts). Keeping WP types out of Query Providers and Resources satisfies ADR-038 without requiring a separate transport abstraction layer at this stage.

---

### DECISION H — Worker State Loading (ADR-044 Reaffirmation)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Resolves** | FLAG-P1AS6-1 (worker state loading question) |
| **Reaffirms** | ADR-044 (stateless workers, state-synchronization model) |

**Ruling — Option B approved:** Workers reload current WordPress state during event processing via a defined WordPress bootstrap path in the worker runtime. The platform is a **state-synchronization** system, not an event-sourcing system (ADR-044). On each event, the worker reads the current authoritative WordPress state and projects it into the delivery store.

**Option A rejected — event payload enrichment:** Enriching the event payload with entity snapshots at capture time is rejected. A captured snapshot represents WordPress state at capture time, not at process time; replaying events with stale snapshots would project outdated state into delivery, contradicting the reconciliation principle (ADR-045, ADR-027). This would also contradict ADR-044 directly.

**Option C rejected — direct-MySQL reload:** Reading WordPress entity state directly from MySQL (bypassing the WordPress object layer) is rejected. WordPress is the authoritative access layer for its own data; a second raw-MySQL path in handlers would introduce a second persistence dependency, bypass WordPress caching, and require handlers to stay synchronized with WordPress schema internals. The WordPress bootstrap path in Option B already provides safe, authoritative entity access.

**Operational bootstrap details:** The exact WP bootstrap sequence within the worker runtime (e.g., which WP functions are available, how `wp-load.php` is invoked, which hooks fire in the worker process) is an operational concern. This detail is deferred to Doc 10 / an ops-focused session. It must not be resolved by assumption in handler code.

**Rationale:** State-synchronization means each event is an instruction to "sync this aggregate" — the handler fetches current state from WordPress and overwrites the projection. This is the correct model for a CMS sync platform where WordPress is system of record. Payload enrichment couples event schema to the handler's data requirements, bloating the event store and preventing the platform from being used for replay-to-current-state scenarios.

---

### DECISION I — Delete Processing via Tombstone Path

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Resolves** | FLAG-P1AS6-1 (delete processing question) |
| **Amends** | DECISION D (AdapterInterface — new method added) |
| **Consistent with** | OPEN-11 (canonical models and checksum surface unchanged) |

**Ruling — Option C approved:** `content.*.deleted` events follow a **dedicated tombstone path** that is structurally separate from the create/update path. The tombstone path:

1. Consumes only the **event envelope** (aggregate type + aggregate ID + event metadata). No WordPress state reload occurs.
2. Performs a **soft-delete projection**: sets `deleted_at = now()` on the target `content.*` row (consistent with DECISION F's `WHERE deleted_at IS NULL` filter invariant).
3. Does **not** invoke the Extractor, Transformer, or canonical model pipeline. There is no WordPress entity to reload — it may have been permanently deleted or transitioned to a non-public state.
4. Records the tombstone in `system.processed_events` and updates `system.aggregate_versions`, inside the same single-PostgreSQL-transaction rule (DECISION 3 three-op atomicity — projection upsert → `system.processed_events` insert → `system.aggregate_versions` upsert).

**Canonical models and OPEN-11 checksum surface: UNCHANGED.** The tombstone path never computes a new canonical checksum and never writes the `checksum` column on the target row. The stored checksum from the last create/update event is preserved as-is. OPEN-11 remains fully intact.

**AdapterInterface contract change:** `AdapterInterface` gains a tombstone/soft-delete method:

```php
public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void;
```

This is an additive contract change. All existing adapter implementations (PageAdapter, PostAdapter, CategoryAdapter) must implement it. The method sets `deleted_at` on the target row inside a single-PG transaction covering all three DECISION 3 ops. If the target row does not exist (e.g., the create event was never processed), the tombstone is a no-op for the projection write but still records in `system.processed_events` and updates `system.aggregate_versions`.

**Rationale:** Routing delete events through the same extract→transform→persist pipeline is unsound: WordPress state may not be available (the post may have been permanently deleted), and the canonical model for a soft-deleted entity is undefined. A dedicated tombstone path with a minimal contract keeps the delete semantic explicit, avoids phantom WordPress reads, and preserves the clean separation between the create/update pipeline and the delete semantic.

---

### DECISION J — Stale-Event Guard: Resolve-Stage Primary + In-Txn Defense-in-Depth (Amends FLAG-P1AS4-2)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Amends** | FLAG-P1AS4-2 (aggregate_versions monotonicity ruling, v1.8) |
| **Consistent with** | DECISION 3 (three-op single-PG-transaction) |

**Ruling:** The stale-event guard operates at two layers. Both layers are **mandatory**. Their roles are distinct and non-interchangeable.

**Layer 1 — Resolve-stage guard (PRIMARY, authoritative gate):** Before invoking any handler, `EventWorkerStrategy` performs a PostgreSQL read to fetch the current `latest_processed_version` from `system.aggregate_versions` for the event's aggregate. If the incoming event's `aggregate_version` ≤ the stored `latest_processed_version`, the event is **stale**: the strategy terminates early (before handler execution), marks the job complete on the queue (not as a failure), and records the skip in telemetry. This is the authoritative stale-event decision point.

**Layer 2 — Adapter in-txn FOR UPDATE + GREATEST guard (MANDATORY, defense-in-depth):** The existing adapter-side guard (in-transaction `FOR UPDATE` lock on `system.aggregate_versions` + `GREATEST(latest_processed_version, incoming)` upsert, per FLAG-P1AS4-2 ruling) is retained and remains mandatory. It closes the **Resolve→write TOCTOU window**: the Resolve read (Layer 1) occurs outside the write transaction; a concurrent worker processing a higher-version event for the same aggregate could commit between the Resolve read and the adapter's write. The `GREATEST()` guard ensures the stored version can only advance, even if two workers race on the same aggregate in the window between Resolve and write.

**Authorizations for P1A-S6b (binding):**

- `EventWorkerStrategy` may take a PostgreSQL read dependency (via `DatabaseConnectionInterface` or a dedicated aggregate-version query abstraction) for the Resolve-stage lookup.
- `WorkerServiceProvider` is authorized to wire the aggregate-version query dependency into `EventWorkerStrategy` via constructor injection (ADR-012 compliant — no service-locator calls).
- The Resolve-stage reads `system.aggregate_versions` using a non-locking SELECT (no `FOR UPDATE` at Resolve time — the lock is taken only inside the adapter's write transaction at Layer 2).
- Early termination at the Resolve stage does NOT mark the job as a DLQ failure. It is a successful no-op: the event was already superseded by a later version.

**Rationale:** FLAG-P1AS4-2 (v1.8) established the monotonic GREATEST guard as defense-in-depth. However, it left the primary stale-event gate undefined — it said "worker owns stale-event detection" without specifying where in the EventWorkerStrategy pipeline the detection occurs. This ruling fixes the gate at the Resolve stage (Step 4 of the Doc 8 §7 pipeline: Claim→Load→Validate→**Resolve**→Execute→Commit→Ack), which is the earliest safe point after the event is validated but before any handler work begins. Terminating at Resolve avoids unnecessary WordPress state reloads (DECISION H) for events that are already stale.

---

### DECISION K — Delivery Connection Isolation (Resolves FLAG-P1AS6A-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6c |
| **Resolves** | FLAG-P1AS6A-1 |
| **Constrains** | DECISION E (v1.6) — connection-ownership allocation |
| **Satisfies** | DECISION J (v1.10) — Resolve-read isolation requirement |

**Ruling:**

**(a) Non-FORCE_NEW shared connection not acceptable for delivery/Resolve reads.**  
A `DatabaseConnectionInterface` binding opened with a plain `pg_connect()` (no `PGSQL_CONNECT_FORCE_NEW`) that could libpq-reuse the relay handle (`outbox.connection.pgsql`) or any other transactional handle in the same PHP process is not acceptable. PHP libpq returns a pooled handle for identical DSN strings; if that handle coincides with a handle under an open transaction (relay OR queue claim), the delivery connection would be reading inside that transaction — violating the isolation required by DECISION J's Resolve-stage stale check.

**(b) One dedicated delivery connection with guaranteed physical separation.**  
Delivery reads (REST query providers), Resolve-stage stale-event reads (`EventWorkerStrategy`), and adapter persistence (all three Content adapters) use exactly **one** dedicated `DatabaseConnectionInterface` binding that is opened with `PGSQL_CONNECT_FORCE_NEW`. This guarantees a distinct physical libpq link from the relay handle (`outbox.connection.pgsql`, `MysqliOutboxConnection`) and the queue-claim handle (`queue.connection.pgsql`, `DatabaseQueueConnection`). Sequential reuse of the same delivery connection within a worker tick — for both the Resolve-stage read and the subsequent adapter write transaction — is **acceptable and intended** (DECISION J Layer 1 reads outside the write transaction; sequential use on one link is not sharing). Cross-sharing with the relay handle or the queue-claim handle is **prohibited**.

**(c) Binding lives in DeliveryServiceProvider; no new raw pg_* wrapper.**  
The `DatabaseConnectionInterface` singleton binding is removed from `QueueServiceProvider` and relocated to a new `core/Container/Definitions/DeliveryServiceProvider`. `DeliveryServiceProvider` reuses the existing `PostgresDatabaseConnection` class — no new raw `pg_*` wrapper class is introduced (DECISION E constraint preserved). `DeliveryServiceProvider` is registered in `ContainerBuilder` before `WorkerServiceProvider` and `ContentServiceProvider`, which are its consumers.

**Rationale:** The Resolve-stage stale-event gate (DECISION J Layer 1) is the PRIMARY correctness gate — it reads `system.aggregate_versions` before handler invocation. If that read executes on a connection that shares a libpq link with an open relay transaction, the read may observe uncommitted relay state or be blocked. `PGSQL_CONNECT_FORCE_NEW` eliminates this risk unconditionally. The precedent for FORCE_NEW on the queue-claim path was established in P0-S5 (FLAG-P0S5-1); this decision applies the same discipline to the delivery/Resolve path.

---

### DECISION L — Dispatcher Stage: system.events → system.queue_jobs (Architect Ruling 2026-06-25)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S6d |
| **Authority** | Doc 1 §157/§272; Doc 4 §3 (Outbox→Dispatcher→Queue→Worker); Doc 2 §16 (`core/Events/Dispatcher/`); Doc 11 §24 (<30s SLA); DECISION E v1.6 (no new pg_* wrapper); DECISION K v1.11 (delivery connection) |

**Ruling:**

**(a) Dispatcher is a distinct pipeline stage.** The resolved pipeline is: `wp_hsp_outbox` → `RelayWorkerStrategy` → `system.events` → **Dispatcher** → `system.queue_jobs` → `EventWorkerStrategy` → projection. The Dispatcher is responsible solely for moving relayed events from `system.events` into the queue; it does not process or transform events.

**(b) Implementation as WorkerStrategyInterface.** The Dispatcher is implemented as `DispatcherWorkerStrategy` under `core/Events/Dispatcher/`, plugged into the existing Worker Engine (one `WorkerEngine` instance driven by `DispatcherWorkerStrategy`). No new worker engine infrastructure is introduced.

**(c) Claim model — anti-join, no watermark, no status column.** Each tick selects undispatched events via:
```sql
SELECT e.id, e.event_type, e.queue_name, e.aggregate_type, e.aggregate_id
FROM   system.events e
WHERE  NOT EXISTS (
    SELECT 1 FROM system.queue_jobs q WHERE q.event_id = e.id
)
FOR UPDATE SKIP LOCKED
LIMIT  N
```
No `dispatch_status` column is added to `system.events` (frozen schema — OPEN-6). No watermark / high-water-mark pointer is maintained. The `NOT EXISTS` anti-join is the authoritative undispatched check.

**(d) Dedup via UNIQUE(event_id) + ON CONFLICT DO NOTHING.** A new forward migration adds `UNIQUE(event_id)` to `system.queue_jobs`. `DatabaseQueueProvider` gains `enqueueIdempotent()` (separate from `enqueue()` to avoid breaking the existing interface): it executes `INSERT … ON CONFLICT(event_id) DO NOTHING`. `completed` rows are retained in `system.queue_jobs` (status update, not DELETE), so the UNIQUE constraint permanently blocks re-dispatch of already-completed events — this is the intended invariant.

**(e) Queue name.** Hardcoded to `'content'` for Phase 1A — all MVP events are content-domain events. Multi-queue routing (event_type-prefix → partition) is not in any frozen doc or the P1A-S6d authority. A future ADR must authorize it before a second domain partition is introduced.

**(f) Ordering.** No FIFO guarantee. The anti-join selects available events; ORDER BY `e.created_at ASC` provides approximate arrival order. Correct-final-state semantics hold regardless of dispatch order.

**(g) Connection constraints.** The Dispatcher is relay/queue-side system DML and MUST NOT use the DECISION K delivery handle (`DatabaseConnectionInterface` singleton, `DeliveryServiceProvider`). It opens its own dedicated FORCE_NEW handle bound as `'dispatcher.connection.pgsql'` (registered in `DispatcherServiceProvider`), following the same pattern as DECISION K. This guarantees the dispatcher handle is physically distinct from both the delivery handle and the relay/queue handles. No new raw `pg_*` wrapper class is introduced (DECISION E constraint is on wrapper classes, not on additional `pg_connect()` calls; `PostgresDatabaseConnection` is an existing class). The dispatcher enqueues via `DatabaseQueueProvider::enqueueIdempotent()`, which uses the queue-claim handle (`queue.connection.pgsql`).

**(g) SLA.** The <30s end-to-end SLA (Doc 11 §24) is unchanged. The Dispatcher adds one hop (system.events → queue_jobs) that must complete within the SLA budget.

**Rationale:** The gap between relay and queue was always implicit in the architecture (Doc 4 §3) but never implemented. Making it a `WorkerStrategyInterface` reuses the existing engine/heartbeat/shutdown infrastructure. Anti-join dedup is the simplest correct model: no state to track on the events table, no new columns, no watermark drift risk. UNIQUE(event_id) provides the database-level idempotency guarantee.

> **Amendment (v1.16 — 2026-07-11 — Ruling 0: Connection Topology Ratified; resolves FLAG-P1AS6D-1):**
>
> The four-connection PostgreSQL topology is **FROZEN as final**:
>
> 1. **Relay handle** — `outbox.connection.pgsql` (relay-side capture/copy path).
> 2. **Queue/worker runtime handle** — `queue.connection.pgsql` (queue-claim + worker runtime path).
> 3. **Delivery handle** — `DatabaseConnectionInterface` singleton, FORCE_NEW, `DeliveryServiceProvider` (DECISION K).
> 4. **Dispatcher handle** — `dispatcher.connection.pgsql`, FORCE_NEW, `DispatcherServiceProvider` (DECISION L clause (g)).
>
> This is the complete and final set. **No fifth handle may ever be introduced without a new ADR.** The fourth (dispatcher) handle is accepted as a pragmatic, ratified extension of the DECISION E (v1.6) temporary-duplication allowance — it is no longer "pending ratification." Consolidation of the four handles remains a future-ADR concern, not an OPS-S1 concern.
>
> **Heartbeat publication is worker-runtime infrastructure**, not a delivery or dispatcher concern. It uses the **existing worker-runtime connection** (handle 2 above), injected into `DatabaseHeartbeatPublisher` via constructor (ADR-012). This introduces **no new connection**: it does not add a fifth handle, does not create a new connection class, and does not add a new raw `pg_*` wrapper. See DECISION P.
>
> **FLAG-P1AS6D-1 is resolved by this ratification** — answer (a) "yes, the fourth FORCE_NEW handle is accepted"; the topology is frozen at four, and DECISION L now records it explicitly.

---

### DECISION P — Worker Heartbeat Storage (Ruling 1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 8 §15 (heartbeat field intent); ADR-012 (constructor injection); ADR-015 (UUIDv7 worker identity); OPEN-3 v1.1/v1.2 type canon |
| **Resolves** | FLAG-OPSS1-1 (heartbeat persistence) |

**Ruling:** Worker heartbeats persist to a **single current-state table** — one row per worker, **upserted per tick**. There is **no history table**.

**`system.worker_heartbeats` — frozen DDL:**

```sql
worker_id         UUID        NOT NULL,   -- UUIDv7, self-assigned at worker startup (ADR-015)
worker_type       TEXT        NOT NULL,   -- e.g. 'event', 'dispatcher', 'maintenance', 'relay'
status            TEXT        NOT NULL,   -- e.g. 'running', 'idle', 'stopping'
last_heartbeat_at TIMESTAMPTZ NOT NULL,   -- updated every tick; heartbeat-age crash detection reads this
started_at        TIMESTAMPTZ NOT NULL,   -- worker process start time
PRIMARY KEY (worker_id)
```

The upsert (`INSERT … ON CONFLICT (worker_id) DO UPDATE SET status = …, last_heartbeat_at = …`) advances the current-state row each tick. A monitor detects a crashed worker by `last_heartbeat_at` age (Doc 8 §15). All timestamps are `TIMESTAMPTZ` (v1.2 canon); `worker_id` is `UUID` (v1.1 canon).

**Publisher:** `DatabaseHeartbeatPublisher` implements the **existing** `HeartbeatPublisherInterface` (introduced in P0-S6; currently satisfied by `NullHeartbeatPublisher`). It receives its PostgreSQL connection via **constructor injection** (ADR-012 — no service-locator call). The connection is the **worker-runtime handle** (DECISION L Ruling 0), not the delivery or dispatcher handle.

**Migration authorization:** A new migration creating `system.worker_heartbeats` is **explicitly authorized for OPS-S1**. This is the formal amendment that lifts the freeze objection recorded in FLAG-OPSS1-1: the table is now a frozen contract in this document and in the Implications table below.

**Rationale:** The DoD requires heartbeat to be "visible … and updated per tick." Current-state-only storage satisfies visibility and crash detection with the minimal schema; a history table adds unbounded growth and retention concerns for no MVP benefit. Reusing `HeartbeatPublisherInterface` avoids new contract surface — only the null implementation is swapped for a database-backed one.

---

### DECISION Q — Metrics Without Persistence (Ruling 2)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 8 §27 (metric minimum set — intent); MVP scope (no OpenSearch/telemetry backend); observability-in-core |
| **Resolves** | FLAG-OPSS1-2 (metric source of truth) |

**Ruling:** MVP introduces **no metrics table, no rollup tables, and no external telemetry backend** (statsd, Prometheus, OpenSearch, etc.). Operational metrics are served two ways:

1. **Derived metrics — computed on demand from PostgreSQL.** Queue depth, DLQ depth, oldest-pending age, and worker count are computed by aggregate query at read time over the existing tables (`system.queue_jobs`, `system.dead_letter_jobs`, `system.worker_heartbeats`). No column is added to any frozen table; no counter is persisted for these.
2. **Runtime counters — emitted as structured worker log events.** Processed / retry / failure / replay counts are emitted as **structured log output** from the worker runtime. They are not stored in a metrics table and do not require a schema change.

**DoD definition:** The OPS-S1 DoD term **"metrics emit"** is defined as: **queryable operational status (on-demand PostgreSQL aggregates) + structured log output (runtime counters).** This is the acceptance bar — no persisted metric store is owed.

**Rationale:** A metrics table would require either a frozen-schema change (`system.queue_jobs` gaining per-job timing columns — a freeze conflict) or a new table with its own retention story, neither justified at MVP. Derived-on-demand + structured logs give operators the required visibility with zero new persistent schema and no conflict with the frozen queue/DLQ contracts. FLAG-OPSS1-2's "no producer" concern is resolved by defining the producer as on-demand queries plus log emission, not a counter sink.

---

### DECISION R — Visibility-Timeout Recovery Driver (Ruling 3)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | OPEN-4 (visibility timeout; config-driven duration; requeue on expiry); Doc 8 (worker strategies); ADR-012 |
| **Resolves** | FLAG-OPSS1-3 (crash→requeue runtime driver) |

**Ruling:** `MaintenanceWorkerStrategy` is the **runtime driver** for `DatabaseQueueProvider::requeueTimedOut()`. It is un-stubbed in OPS-S1 (scope: `core/Workers/`) to invoke `requeueTimedOut()` on the maintenance tick, reviving jobs whose `visibility_timeout_at` has expired without completion.

**Cadence is configuration-driven** with a sensible default; **no hardcoded timing values** in the strategy. The cadence config key is defined alongside the existing visibility-timeout config (OPEN-4 established that the timeout duration is config-driven; the recovery cadence follows the same discipline).

The recovery/requeue loop uses the **worker-runtime connection** (DECISION L Ruling 0) — the same handle the queue provider already uses — not a new handle.

**Rationale:** `requeueTimedOut()` already exists and is tested; the only gap was a runtime owner. `MaintenanceWorkerStrategy` is the natural home (its own stub comment already anticipated this). Config-driven cadence keeps operational tuning out of code and consistent with the OPEN-4 config-driven-timeout precedent.

---

### DECISION S — DLQ Replay Lifecycle (Ruling 4)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 4 §24 (single-event replay); DECISION A (DLQ self-contained/replayable); DECISION J (Resolve-stage stale guard); DECISION L clause (d) (UNIQUE(event_id) on `system.queue_jobs`); OPEN-3 v1.1 (DLQ schema) |
| **Resolves** | FLAG-OPSS1-4 (replay entry point + DLQ tooling surface) |
| **Amends** | OPEN-3 (adds `replayed_at` to `system.dead_letter_jobs`) |

**Ruling:**

**(a) DLQ rows are permanent audit records — never deleted.** Replay does not delete the DLQ row. The row is preserved as an audit trail; replay marks it, it does not remove it.

**(b) Replay executes in ONE PostgreSQL transaction** with these steps, in order:
1. **Verify** the DLQ row exists.
2. **Verify** it has not already been replayed (`replayed_at IS NULL`).
3. **DELETE** any `system.queue_jobs` row sharing the same `event_id`. This is mandatory: DECISION L clause (d) retains `completed`/`dead_lettered` rows in `system.queue_jobs`, and `UNIQUE(event_id)` means a naive re-enqueue would `ON CONFLICT DO NOTHING` — a silent no-op. Clearing the prior job row first is what makes the fresh insert take effect.
4. **INSERT** a fresh `system.queue_jobs` job for the event with **`attempts` reset to 0**.
5. **Stamp** `replayed_at` on the DLQ row.

**(c) Replay passes the Resolve-stage stale guard (DECISION J).** The re-enqueued event re-enters the pipeline through the normal queue/claim path. If the aggregate is already at or beyond the event's version, the Resolve-stage guard acks the job with **zero projection writes** — this is **correct behavior**, not an error. Replay never writes projections directly.

**(d) Surface: WP-CLI only.** The operational surface is `hsp dlq list | inspect | replay`. **No admin UI** is built in OPS-S1 (this sidesteps the still-TBD WPCS/coding-standard decision at the WP admin boundary).

**(e) `replayed_at` schema addition — authorized.** `replayed_at TIMESTAMPTZ NULL` is **absent** from the OPEN-3 v1.1 DLQ schema (verified against migration `0004_create_system_dead_letter_jobs.sql`, which carries only the four OPEN-3 delta columns). Adding it is **explicitly authorized within the OPS-S1 migration scope** as a forward migration (must not edit frozen migration 0004). This ruling is the formal amendment to OPEN-3; the Implications table below is updated accordingly.

**Rationale:** Permanent DLQ rows preserve the audit trail DECISION A requires. The single-transaction delete-then-insert closes the `UNIQUE(event_id)` no-op trap identified in FLAG-OPSS1-4: without clearing the prior job row, replay would silently do nothing. Resetting `attempts` to 0 gives the replayed job a full retry budget. Relying on the DECISION J guard to no-op an already-current aggregate keeps replay idempotent and projection-safe. WP-CLI-only avoids coupling replay to the unresolved WP-admin coding-standard question.

---

### DECISION T — Replay via Projection Repair by Synthetic Re-emission (Ratifies FLAG-OPSS2-1 Option A)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-12 |
| **Session** | OPS-S2 (pre-implementation ruling) |
| **Authority** | Doc 4 §24 (replay modes); DECISION 1 (near-atomic capture via outbox); DECISION 2 (per-aggregate atomic counter); DECISION J (Resolve-stage stale guard); DECISION S (single-event DLQ replay); ADR-044/ADR-045 (stateless workers, WordPress wins); Doc 5 §26 (event immutability); CLAUDE.md Rule 3 (outbox path), Rule 5 (module isolation) |
| **Resolves** | FLAG-OPSS2-1 |
| **Supersedes** | IMPLEMENTATION_PLAN.md §5b "re-enqueue original event" wording — for entity/date-range replay modes only |

**Ruling — Option A approved:** Entity and date-range replay is **projection repair via synthetic re-emission**. It does not re-enqueue or mutate any historical event.

**1. MECHANISM.** Entity replay (given `aggregate_type, aggregate_id`) and date-range replay each emit a **NEW** event through `wp_hsp_outbox` (Rule 3 holds), taking a **NEW** `aggregate_version` from `wp_hsp_aggregate_counters` via the DECISION 2 atomic increment. The synthetic event flows through the normal pipeline: relay → `system.events` → dispatch → `system.queue_jobs` → worker. Because the new version is strictly greater than `system.aggregate_versions.latest_processed_version`, it passes the DECISION J Resolve-stage stale guard **naturally**. The guard is **not** weakened, bypassed, or made conditional. Historical `system.events` rows are **never** mutated or re-enqueued — replay **appends**, never rewrites, so Doc 5 §26 immutability holds. (This is the exact inverse of the FLAG-OPSS2-1 conflict: a re-enqueue of the *original* version would be `<=` stored and get acked with zero writes; a fresh higher-versioned synthetic event reprojects correctly.)

**2. SEMANTICS (WordPress wins — ADR-044/ADR-045).** The replay emitter reads **current** WordPress state per aggregate at emit time:
- aggregate **exists and is public** (public set = `{publish}`, OPEN-10) → emit the existing OPEN-1 `.updated` type (`content.{page|post|category}.updated`);
- aggregate **missing or non-public** → emit the existing OPEN-1 `.deleted` type.

No new event-type contracts are introduced. This correctly **tombstones** entities that were deleted or unpublished during an outage window: replaying them projects the current (absent) reality, not a stale snapshot. Categories have no `publish` status; a category is "public" iff its term currently exists.

**3. DATE-RANGE MODE.** Enumerate affected aggregates with `SELECT DISTINCT aggregate_type, aggregate_id FROM system.events WHERE created_at >= :from AND created_at < :to`, then perform one synthetic emit per distinct aggregate (semantics per point 2). The window is half-open `[from, to)`. The `system.events` read reuses the **existing delivery `DatabaseConnectionInterface` handle** (DECISION K) — the same handle the Dispatcher and Resolve-stage already use to read `system.events`/`system.aggregate_versions`. **No fifth PG handle is opened** (DECISION L Ruling 0 — topology frozen at four). No new raw `pg_*` wrapper.

**4. TRACEABILITY.** Each synthetic event carries a `causation_id` referencing the replay operation, and all synthetic events from one replay run share a single `correlation_id`. This makes a replay run auditable and distinguishable from organic edits in `system.events`.

**5. CONVERGENCE.** This emit-through-outbox repair primitive is the **same mechanism** future reconciliation (ADR-026/ADR-027/ADR-045) will use to repair the delivery side from WordPress. Reconciliation sessions build on DECISION T rather than inventing a second repair path.

**Scope boundaries.** Single-event DLQ replay under DECISION S is **unchanged** (it re-enqueues a never-processed dead-lettered event whose version is still ahead of the guard). Full Replay (Doc 4 §24) is out of MVP gate scope (Phase 3). Module isolation (Rule 5): the replay orchestration/discovery lives in `core/` and depends on a **core-owned `ReplayEmitterInterface`**; the Content module implements it (WP-state read + `.updated`/`.deleted` decision + outbox emit via the existing `EventProviderInterface`/`OutboxWriter`). Core never imports the module.

**Rationale:** The FLAG-OPSS2-1 conflict was that re-enqueuing the *original* event version cannot reproject an already-current aggregate (DECISION J acks it with zero writes). A fresh synthetic event with a new counter version dissolves the conflict: it honors the outbox path (Rule 3), reloads current state (ADR-044), advances the version monotonically so the guard passes without modification, and leaves the historical event log immutable (Doc 5 §26). It requires no schema change and no new connection. Reading current WP state (not a stored snapshot) means replay after an outage converges the projection to reality — including deletions — which is precisely the reconciliation guarantee.

---

### DECISION U — Reconciliation MVP via DECISION T Re-emission (Ratifies FLAG-GATES3-1 Option A)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-13 |
| **Session** | GATE-S3 flag resolution (architect ruling) |
| **Authority** | Doc 11 §9–10 (Operability Validation); ADR-026 (drift detection / incremental validation / full reconciliation schedule); ADR-027, ADR-045 (WordPress wins divergence); DECISION 1 (near-atomic capture + reconciliation backstop); DECISION T (synthetic re-emission repair primitive); DECISION I (tombstone path); DECISION J (Resolve-stage stale guard); DECISION L Ruling 0 (four-connection topology frozen); DECISION E (no new `pg_*` wrapper); CLAUDE.md Rule 1 (WordPress source of truth), recovery-jobs WP-Cron carve-out |
| **Resolves** | FLAG-GATES3-1 |
| **Amends** | IMPLEMENTATION_PLAN.md §5b Session Map (inserts OPS-S3; re-points GATE-S3 Depends-on) |

**Ruling — Option A approved.** A reconciliation build session is inserted before GATE-S3. Reconciliation is un-stubbed and validated, then GATE-S3 runs against real behavior.

**1. SESSION-MAP CHANGE.** A new session **OPS-S3 "Reconciliation MVP"** is inserted in the §5b Session Map **immediately before GATE-S3**. **GATE-S3 `Depends-on` changes from `GATE-S2` to `OPS-S3`.** **GATE-S3 DoD is UNCHANGED** — it still validates all three Operability criteria (worker health / failure detection within one heartbeat cycle; DLQ payload snapshot + stack trace; reconciliation executes with WordPress-wins repair). GATE-S1/GATE-S2 are unaffected.

**2. REPAIR MECHANISM — DECISION T re-emission ONLY.** Reconciliation repairs the delivery side **exclusively** by re-emission through the normal pipeline via the DECISION T primitive (`core/Replay/ReplayService` → core-owned `ReplayEmitterInterface` → module `ContentReplayEmitter`): a synthetic event is emitted through `wp_hsp_outbox` taking a fresh `wp_hsp_aggregate_counters` version (DECISION 2), flowing relay → `system.events` → dispatch → `system.queue_jobs` → worker, and passing the DECISION J Resolve-stage stale guard **naturally** (new version > stored). **Direct PostgreSQL projection writes as a repair path are PROHIBITED.** Reconciliation never writes `content.*`, `system.processed_events`, or `system.aggregate_versions` directly — only the worker pipeline does, exactly as for organic edits. This is the "reconciliation sessions build on DECISION T rather than inventing a second repair path" commitment recorded in DECISION T point 5.

**3. WORDPRESS WINS BY CONSTRUCTION (ADR-026/ADR-027/ADR-045).** Because repair is re-emission and the emitter reads **current** WordPress state (exists+public → `.updated`; missing/non-public → `.deleted`, DECISION T point 2), the delivery side is always repaired **to** WordPress and WordPress is **never** written from PG. WordPress-wins is therefore structural, not a runtime branch that could be inverted.

**4. SCOPE — three detection modes.** Reconciliation covers **drift detection**, **incremental validation**, and **full reconciliation** (ADR-026). Across these modes it must detect and repair both divergence classes:
- **(a) Missed captures** — a WordPress entity that is newer than, or absent from, the delivery state. This is the **DECISION 1 backstop**: the narrow window between WP commit and the post-commit outbox write can drop a capture; reconciliation closes it. Repair = re-emit → the aggregate reprojects to current WP state.
- **(b) Orphans** — an aggregate present in the delivery projection but **deleted or non-public** in WordPress. Repair = re-emit; the emitter observes the aggregate is missing/non-public and emits the `.deleted` event, driving the **DECISION I tombstone path** (soft-delete `deleted_at`), so the orphan is correctly tombstoned rather than left as a stale published row.

**5. SCHEDULING.** WP-Cron is **authorized for MVP** to *trigger* reconciliation, under the CLAUDE.md recovery-jobs carve-out (WP-Cron is a fallback for recovery/safety jobs, never the primary execution path). **Workers remain the execution path** — WP-Cron only enqueues/triggers a reconciliation pass; the actual detection scan and all repair re-emission run on the worker runtime, not inside the cron request. The ADR-026 cadences (hourly drift / nightly incremental / weekly full) map onto WP-Cron schedules for MVP; migrating the trigger to systemd/external scheduling later requires no repair-path change (it is a trigger swap only).

**6. CONSTRAINTS (binding).** No new PostgreSQL handle (DECISION L Ruling 0 — topology frozen at four; reconciliation reuses existing runtime handles). No new raw `pg_*` wrapper class (DECISION E). No new event-type contracts (reuses OPEN-1 `.updated`/`.deleted`). Module isolation (Rule 5): reconciliation orchestration/discovery lives in `core/`; the WordPress-state read and emit decision live in the Content module behind `ReplayEmitterInterface`; core never imports a module. Any drift-detection query, entry-point (CLI/cron) shape, and false-positive-suppression mechanism is a **design detail to be ratified in the OPS-S3 design step** before implementation — this DECISION authorizes the session and fixes the repair mechanism, not the query shapes.

**Rationale:** FLAG-GATES3-1 was a Session-Map sequencing conflict: GATE-S3 required reconciliation to *execute* before any session built it. Option A resolves it in the way most consistent with the frozen record — DECISION T already established the emit-through-outbox repair primitive and explicitly reserved it as the mechanism future reconciliation would build on (DECISION T point 5). Constraining repair to that single primitive keeps WordPress-wins structural, avoids a second repair path, and requires no schema change or new connection. Deferring the query/entry-point/false-positive details to the OPS-S3 design step keeps this ruling to the architectural commitments (session insertion, repair mechanism, WP-wins, scope, scheduling) without prematurely fixing implementation shape.

#### DECISION U — Ratified Detection Design (v1.19)

The OPS-S3 design was reviewed and ratified in-chat with the following binding design decisions. These fix the detector; the repair mechanism (DECISION T re-emission only) and all constraints in points 1–6 above are unchanged.

**(D1) Comparison signal by mode.** Detection runs in three modes mapping onto the ADR-026 cadences:

| Mode | Cadence | Detection signal | Direction |
|---|---|---|---|
| **drift** | hourly | Source-timestamp / existence comparison. Posts/pages: WP `post_modified_gmt` vs projection `updated_at`; existence vs projection presence & `deleted_at`. Categories: **existence-only** (see D2). No checksum recompute. | WP→PG |
| **incremental** | nightly | Recent window (config horizon) + **checksum recompute** (deep field equality — catches silent drift a timestamp missed). | WP→PG |
| **full** | weekly | Whole corpus + checksum + **orphan sweep** (PG→WP). Unbounded, paged; page size config-driven. | WP→PG **and** PG→WP |

**(D2) Taxonomy limitation.** WordPress terms carry **no modified timestamp** (there is no `term_modified` analogue to `post_modified_gmt`). Therefore hourly drift for categories is **existence-only**: it detects a missing projection row for a live term (missed create) and a live projection row for a deleted term is an orphan (full mode only). Category **field** staleness (a rename, description edit) is invisible to the hourly timestamp pass and is caught instead by the **nightly/weekly checksum recompute**. This is an accepted MVP latency, recorded so it is not mistaken for a detector bug.

**(D3) Direction by mode (missed-delete latency).** Hourly and nightly are **WP→PG only** — they detect missed **creates/updates** (a WP entity newer than, or absent from, delivery). The **orphan sweep (PG→WP)** — a projection row with no live/public WP entity — runs in **full mode only**. Consequently a missed **delete** (a WP entity deleted or unpublished without a `.deleted` event ever emitted) is repaired at a latency of **≤ one week** at MVP. Accepted: the `.deleted` capture path (OPEN-10 transitions + `after_delete_post`) is the primary delete mechanism; the weekly orphan sweep is the backstop, and the DECISION 1 gap for deletes is narrow.

**(D4) Suppression rule (final — false-positive guard).** Before repairing any aggregate that WP→PG comparison flags as "WP newer than / absent from delivery", the detector must **skip** it (treat as IN-FLIGHT, not drift) iff **either**:
1. a **pending unrelayed `wp_hsp_outbox` row** exists for the aggregate (`status = 'pending'`), **OR**
2. a **`system.events` row** exists for the aggregate with `aggregate_version > system.aggregate_versions.latest_processed_version` (captured/relayed but not yet projected).

If neither holds, the newer-WP state was **never captured** — a genuine DECISION 1 missed capture — and is repaired via re-emission. This spans the whole pipeline: (1) covers the capture-not-yet-relayed window (MySQL side); (2) covers the relayed-not-yet-processed window (PG side). The narrow residual race (WP edited between the detector's WP read and PG read) is self-healing — at worst one redundant synthetic emit, collapsed by the DECISION J guard and idempotent upsert; no correctness exposure.

**(D5) Executor = B1.** `ReconciliationWorkerStrategy::execute()` stays a **producer-side no-op** (`return false`), exactly like `ReplayWorkerStrategy` under DECISION T. Reconciliation is triggered by WP-Cron / WP-CLI, which invoke `reconcileDrift()` / `reconcileIncremental()` / `reconcileFull()` on the strategy; the detection scan and all repair re-emission run **on the worker-bootstrapped process**, not by claiming a `system`-queue job. No `system` reconcile job type is introduced (avoids inventing queue-routing surface at MVP).

**(D6) New core contract `WpReconciliationSourceInterface`.** Detection-side WordPress reads (list aggregate IDs by type, fetch `post_modified_gmt`/existence/public-status, and — for checksum modes — the data needed to recompute the projection checksum) live behind a new **core-owned `WpReconciliationSourceInterface`**, implemented in the Content module (`modules/Content/Reconciliation/`). Symmetric with `ReplayEmitterInterface`; core never imports the module (Rule 5). Contract-only — **no schema change**.

**(D7) Full sweep bound + paging.** Full reconciliation is **unbounded** (whole corpus) but **paged**; the page size is **config-driven** (following the DECISION R cadence-config precedent — no hardcoded batch size). All modes page WP IDs in chunks and fetch paired projection rows per chunk in one PG read; no cross-DB join (Rule 8).

**Connections (unchanged from points 1–6):** PG reads (`content.*`, `system.aggregate_versions`, `system.events`) use the existing **delivery `DatabaseConnectionInterface`** handle; the pending-outbox suppression read (D4 clause 1) uses the WP `$wpdb`/outbox read path in the worker bootstrap. No fifth handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E).

---

### DECISION V — Operations Console Adoption (Ratifies FLAG-PLANOPS1-1..11)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-15 |
| **Session** | OPSC-S0 (docs-only ratification) |
| **Authority** | Architect ruling 2026-07-15 (FLAG-PLANOPS1-1..11); PLAN-OPS-1 audit (`docs/notes/DOC12-ADOPTION-AUDIT.md`); Doc 12 (Admin Operations Console); DECISION K (delivery connection), DECISION L Ruling 0 (four-handle topology), DECISION E (no new `pg_*` wrapper), DECISION P (heartbeat current-state), DECISION Q (metrics without persistence), DECISION S/T (replay), DECISION U (reconciliation); DECISION 1 + Rule 4 (never lose a sync); CLAUDE.md Rules 1–8 |
| **Resolves** | FLAG-PLANOPS1-1, -2, -3, -4, -5, -6, -7, -8, -9, -10, -11 |
| **Adopts** | Doc 12 (as amended by this decision) — promoted Draft → "Accepted (as amended by DECISION V)" |
| **Amends** | CLAUDE.md folder structure + Coding Standard; Doc 2 folder tree (Doc 2 not edited — amended by this ruling); Doc 11 roadmap; IMPLEMENTATION_PLAN.md §5b Session Map (inserts OPSC-S1..S4) |

**Ruling.** The HSP Operations Console (Doc 12) is adopted into the frozen architecture, conservatively scoped per the architect's 2026-07-15 rulings. Doc 12 is authoritative for the console's registry/provider architecture; where Doc 12 conflicts with this decision, **this decision wins** (precedence, line 3). The eleven flags are resolved as follows.

**(a) Frontend stack (FLAG-3 B).** The MVP console is **server-rendered PHP + minimal vanilla JavaScript + the WordPress native admin UI**. **No node/npm/bundler toolchain is introduced**; no `package.json`, no CI build step, no shipped JS/TS bundle. Doc 12 §10's "React UI" and its Console State Store / Refresh Coordinator become simple server-rendered pages with lightweight JS polling. React remains available only via a **future ADR** that **must not alter the provider/registry architecture** ratified here. Doc 12 §3's `Assets/`/`UI/` subtree is reduced accordingly (no build config).

> **⚠ AMENDED by DECISION W (v1.21, 2026-07-16).** Clause (a) is **superseded going forward**: React + shadcn is now adopted as **THE admin UI stack** (DECISION W is the "future ADR" this clause anticipated). Build-artifact policy = **commit `dist/` to the repo** (npm build in dev/CI only; production deploy is a file copy — no host build step); WPCS security applies at the REST/ajax endpoints the React app calls. **The already-shipped OPSC-S1..S4 server-rendered PHP Operations Console is NOT rewritten by this** — it remains as built; only **new** admin UI (including onboarding) is React+shadcn. The **provider/registry architecture** ratified in this clause and in clauses (g)–(j) — registries, provider contracts, the `OperationsService` seam, ADR-047/048/052/053, the read-only/observability-only philosophy — is **unchanged**; DECISION W changes rendering technology only. See DECISION W.

**(b) Coding standard (FLAG-4 A).** The platform coding standard is settled: **PSR-12 for all platform code**; **WPCS security requirements — escaping, sanitization, capability checks, nonces — apply only at WordPress entry points** (admin pages, form handlers, REST registration, `$wpdb` calls). This confirms the IMPLEMENTATION_PLAN.md §3 wording and lifts the "TBD / do not enforce" hold. DECISION S clause (d) deliberately shipped CLI-only to avoid this boundary; it is now resolved and the admin boundary is open.

**(c) Metrics derived on-demand (FLAG-5 A).** All console metrics are **derived on-demand per DECISION Q** — **zero new persistence**. Processing rate, replay status, and reconciliation status are computed from existing operational data (e.g. rolling-window queries over `system.queue_jobs.processed_at`; last-run summaries derived from existing rows/logs). **No metrics table, no rollups, no time-series store** may be introduced. Doc 12 §12's "Processing Rate / Replay Progress / Reconciliation Status" tiles are point-in-time derivations, not persisted progress surfaces.

**(d) Actions are thin delegators; no second repair path (FLAG-6 A).** The Replay and Reconcile console actions are **thin delegators** to `core/Replay/ReplayService` (DECISION T/S) and `core/Reconciliation/ReconciliationService` (DECISION U). They **never** open a second repair path and **never** write `content.*` / `system.*` projections directly — repair is re-emission only, exactly as for organic edits and reconciliation (DECISION T point 5 / DECISION U point 2). **OPSC-S4's DoD must include a write-spy proof**: zero direct `content.*`/`system.*` writes on the action path, mirroring the GATE-S3 reconciliation evidence.

**(e) Flush Queue removed (FLAG-7 A).** **Flush Queue is removed from the action set.** A destructive queue flush discards pending/failed jobs and violates at-least-once (Rule 4), "never lose a sync" (DECISION 1), and the never-drop-a-sync anti-pattern. Any future queue-maintenance action must be **replay-safe** (e.g. requeue timed-out jobs via DECISION R, or move stuck jobs to the DLQ), **never destructive deletion**.

**(f) No Restart Workers action (FLAG-8 C-modified).** The console provides **worker status, heartbeat, restart guidance, and runbook links only** — **no Restart Workers action**. A wp-admin PHP request cannot control a systemd/Supervisor-managed process; worker lifecycle belongs to the **process supervisor**. The console surfaces DECISION P heartbeat state and links to the operational runbook; it does not attempt lifecycle control.

**(g) Provider PG reads reuse the delivery handle (FLAG-10 A).** Console providers running in the wp-admin request read `system.*` / `content.*` through the existing **delivery `DatabaseConnectionInterface`** (DECISION K). This opens **no fifth handle** — the four-handle topology (DECISION L Ruling 0) is unchanged — and introduces **no new raw `pg_*` wrapper** (DECISION E).

**(h) Operations contracts location (FLAG-11 A).** Operations contracts live under **`core/Contracts/Operations/`** (a namespace under the existing contracts root), **not** `core/Operations/Contracts/`. This keeps **CLAUDE.md Rule 5 ("modules depend on `core/Contracts/` only") true verbatim** when a module (e.g. `modules/Content/Operations/`) provides a widget / diagnostics / metrics implementation. Doc 12 §3's `Core/Operations/Contracts/` tree is **superseded** on this point.

**(i) `core/Operations/` folder (FLAG-2 A).** **`core/Operations/`** (lowercase, matching the repo/namespace convention `HSP\Core\Operations\`) is added to the canonical folder structure as **core infrastructure** (housing Admin, Registries, Providers, Services, Diagnostics, and the server-rendered UI). Doc 12's `Core/` uppercase casing is corrected to `core/`. Doc 2's folder tree is amended by this ruling; **Doc 2 is not edited** (the amendment lives here).

**(j) Philosophy — observability, not a control plane (binding scope).** The Operations Console is an **observability and diagnostics interface, not an operational control plane.** Restarting services or containers, managing OS processes, and infrastructure orchestration are **permanently outside the plugin's scope.** Every current and future console session is bound by this: the console reports and diagnoses; it does not operate the infrastructure. State-changing actions are limited to the platform's own re-emission primitives (replay, reconcile) that route through the ratified services.

**ADR ratification (FLAG-9 B).** ADR-047, ADR-048, ADR-049, ADR-050, ADR-052, ADR-053 are ratified as entries below (each consistent with Doc 12 as amended by this decision). **ADR-051 (Operational Actions) is HELD** — recorded but **not citable as authority** by any session — until the FLAG-7 (no Flush Queue) and FLAG-8 (no Restart Workers) rulings are incorporated into its text. No ADR number collides (prior ceiling was ADR-046).

**Doc 11 (FLAG-1 A).** Doc 11's roadmap is updated to add **"Phase 1A – Expanded — Operations Console & Developer Experience"** between Phase 1A (Blog MVP) and Phase 1B (Content Enhancement).

**Doc 12 status.** Doc 12 is promoted from Draft to **"Accepted (as amended by DECISION V — see ARCHITECTURE_DECISIONS.md)"**; its §21 self-freeze sentence is removed (freezing is this record's act, not the doc's). No other content edits are made to Doc 12 — all supersessions live in this decision, not in doc rewrites.

**Rationale.** The console's architectural value is the registry/provider discipline and the delegation-only action model, not a specific frontend framework. Server-rendered PHP delivers both MVP nav items (Operations dashboard + API Playground) with zero new toolchain and the smallest possible admin-boundary surface, letting the operational architecture be validated before any richer-client investment. Constraining actions to the ratified re-emission primitives keeps WordPress-wins structural and prevents a second repair path. Removing Flush Queue and Restart Workers keeps the platform's never-lose-a-sync guarantee and the supervisor-owned worker lifecycle intact. Reusing the delivery handle preserves the frozen four-handle topology. Placing contracts under `core/Contracts/Operations/` preserves Rule 5 verbatim with no rule edit.

---

### DECISION W — Onboarding & First-Run Backfill

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-16 |
| **Session** | ONB-S0 (docs-only ratification; OPSC-S0 pattern) |
| **Authority** | Architect ruling 2026-07-16; DECISION U (reconciliation = re-emission repair via `ReconciliationService`); DECISION T (re-emission primitive); DECISION V (Operations Console adoption — clause (a) amended here, clauses (b)–(j) unchanged); DECISION P (worker heartbeat current-state); DECISION Q (metrics/progress without persistence); DECISION K (delivery handle), DECISION L Ruling 0 (four-handle topology), DECISION E (no new `pg_*` wrapper); DECISION 1 + Rule 4 (never lose a sync); CLAUDE.md Rules 1–8 |
| **Amends** | **DECISION V (a)** (frontend stack — React+shadcn adopted globally); CLAUDE.md folder structure (`core/Onboarding/`), Coding Standard note (React admin UI), SETTLED (adds a DECISION W line); IMPLEMENTATION_PLAN.md §5b Session Map (inserts ONB-S1, ONB-S2 before Phase 1B) |

**Ruling.** The HSP Onboarding / First-Run experience is adopted into the frozen architecture with the six rulings below. Where this decision conflicts with DECISION V, **this decision wins** (precedence, line 3) — specifically for clause (a) (frontend stack).

**(a) UI stack — DECISION V (a) AMENDED: React + shadcn adopted for the admin UI.** DECISION V (a) deferred React to "a future ADR that must not alter the provider/registry architecture." **This decision is that ruling.** React + shadcn is adopted as **THE admin UI stack** for HSP going forward.
- **Build-artifact policy: commit `dist/` to the repo.** The npm/bundler toolchain runs in **dev/CI only**; the compiled JS/CSS bundle is committed under a `dist/` (build output) directory and the production deploy is a **plain file copy** (consistent with the CLAUDE.md session-close robocopy step). **No node/npm build step runs on the WordPress host.** `package.json` and the toolchain live in the repo for dev/CI; production carries only the built assets.
- **WPCS boundary:** WPCS security rules (output escaping, input sanitization, capability checks, nonces) apply at the **REST/ajax endpoints the React app calls** (the JSON boundary), not inside the React render tree. This extends DECISION V (b) to the new client transport: the client is untrusted; every server endpoint it calls sanitizes input, checks capability, and verifies the nonce.
- **Scope:** The **already-shipped OPSC-S1..S4 server-rendered PHP Operations Console is NOT rewritten by this decision** — it remains as built. Only **new** admin UI — including the onboarding surface (ONB-S1/S2) — is built in React+shadcn. The **provider/registry architecture** ratified by DECISION V (a) and clauses (g)–(j) (registries, provider contracts, the `OperationsService` seam, ADR-047/048/052/053, and the read-only/observability-only console philosophy) is **unchanged**; DECISION W changes rendering technology only. A future migration of the Operations Console itself from PHP to React is permitted but out of this decision's scope and requires its own session.

> **Styling addendum (architect-ruled 2026-07-16, ONB-S1a STEP 0).** For the React/shadcn admin stack, the **dark theme is the DEFAULT** (shadcn `.dark` token strategy). It is applied **on the plugin mount root only** — never on the `wp-admin` `<body>`. Tailwind preflight/base styles MUST be scoped to the mount container (prefix or scoped-selector strategy) so wp-admin's own styles are untouched; a wp-admin page outside the mount must be visually unaffected. The already-shipped server-rendered OPSC console keeps its current styling until a future console-migration session.

**(b) Backfill mechanism — full-reconciliation re-emission via `ReconciliationService` (DECISION U); no direct copy path.** The initial content migration (first-run population of the delivery projections from existing WordPress content) is performed as a **full reconciliation** (`ReconciliationService::reconcileFull()`, DECISION U) that re-emits every in-scope aggregate through the **normal outbox → relay → dispatch → worker pipeline** via the DECISION T primitive. **There is NO direct WP→PG copy path and NO second repair path.** Onboarding is a **thin delegator** to the ratified `ReconciliationService` exactly as the OPSC-S4 console actions delegate to it (DECISION V (d)); the implementation DoD (ONB-S2) **must include a write-spy proof**: zero direct `content.*` / `system.*` writes on the backfill path — projections are written only by the worker pipeline, exactly as for organic edits. WordPress-wins holds by construction (DECISION U point 3).

**(c) Queue drain during onboarding — a live worker heartbeat is a HARD PREREQUISITE.** Onboarding will **not** trigger the backfill unless a **fresh `system.worker_heartbeats` row exists** (DECISION P current-state row; freshness judged by `last_heartbeat_at` age against the same config threshold the Worker Status provider uses). Workers drain the outbox→relay→dispatch→queue pipeline **as normal** (they are the execution path — CLAUDE.md, DECISION U point 5). **There is no in-request tick drain** — the wp-admin/onboarding request never runs the worker engine inline; it only enqueues (via re-emission) and then reports derived progress. If no live worker is detected, the backfill trigger is blocked and the operator is shown worker-status + runbook guidance (never a Restart Workers action — DECISION V (f); the supervisor owns worker lifecycle).

**(d) Progress — derived on-demand (DECISION Q); completion state is one WP option; zero schema change.** Onboarding progress is **derived on demand** per DECISION Q: an expected-count scan (in-scope WordPress aggregate counts by type) compared against processed/projection counts (`content.*` projection row counts and/or `system.processed_events`), computed at read time. **No new PG persistence** — no progress table, no rollups, no time-series store. The single durable completion signal is a WordPress option **`hsp_onboarding_state`** stored in **MySQL** (WP options table); it is **not** a schema migration and adds no table/column. Its values track the onboarding lifecycle (e.g. `pending` → `preflight_ok` → `backfilling` → `complete`); the exact value enum is an ONB-S1 design detail.

**(e) Placement — `core/Onboarding/`, delegating to ratified services only.** Onboarding is a **lifecycle/setup surface**, distinct from the observability console. It lives under **`core/Onboarding/`** (core infrastructure; `HSP\Core\Onboarding\`), **not** under `core/Operations/`. This keeps DECISION V (j) intact — the Operations Console remains observability/diagnostics only, and onboarding (which *triggers* a state change via backfill) is not folded into the console's read-only surface. Any onboarding contracts live under **`core/Contracts/`** (Rule 5). Onboarding **delegates to ratified services only** (`ReconciliationService`, the migration engine, the Worker Status / heartbeat read path); it opens no PG handle of its own — provider-style reads reuse the delivery `DatabaseConnectionInterface` (DECISION K), no fifth handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E).

**(f) Nav gating & hard-blocking prerequisite checks.** Until `hsp_onboarding_state = complete`:
- The **Operations** and **API Playground** admin pages are **not registered / not visible** (the `AdminPageController` menu registration is gated on the completion flag); the **onboarding page is the only HSP admin surface**.
- **Prerequisite checks hard-block progression** (the operator cannot advance/trigger backfill until they pass): (1) the **`pgsql` PHP extension** is loaded; (2) the **PG connection constants** are defined in `wp-config.php` (DECISION O `HSP_PG_*`); (3) **PostgreSQL is reachable** (a live connection succeeds); (4) the **PHP version** meets the platform minimum. A failed prerequisite is a **hard block** with remediation guidance, not a warning.

> **⚠ AMENDED (v1.22, 2026-07-17 — architect ruling).** The **"required core + content migrations applied" check is moved OUT of the ONB-S1b environment-preflight and INTO ONB-S2 as a backfill prerequisite.** Rationale: the environment preflight (ONB-S1b) validates the *host environment* (extension, constants, PG reachable, PHP version) — the four checks above. Whether the delivery schema is migrated is a **content/data-readiness** concern that gates the **backfill** step (ONB-S2), not host readiness; ONB-S2 already opens the delivery handle and drives `ReconciliationService`, so the migration-state check (`system.schema_versions` / `system.module_versions`, OPEN-8 read path) is evaluated there as a hard block immediately before backfill. This changes **when** the check runs (ONB-S2, not ONB-S1b), not **whether** — it remains a hard block via the same delivery-handle read path (DECISION K reuse; no new handle/wrapper). The `MigrationsAppliedCheck` implementation shipped in ONB-S1b is retained for ONB-S2 to reuse. ONB-S1b therefore ships **four** preflight checks; ONB-S2 adds the migration check to its backfill-gate set. See the Session Map ONB-S1b / ONB-S2 rows.

> **⚠ AMENDED (v1.23, 2026-07-18 — architect ruling; self-remediating gates).** The two ONB-S2 backfill prerequisite gates — **(1) migrations applied** and **(2) processing pipeline advancing** (the DECISION X (4) Option-C worker gate) — become **self-remediating in-product**, so a **zero-configuration fresh install** completes onboarding with **NO manual CLI/engine step** (ADR-054 **Principle 8** — Zero-Configuration Operation; "an operator installs the plugin and synchronization begins"). This changes how a blocked gate is *satisfied*, **not whether it blocks** — each gate remains a **hard block** and merely gains an in-product action; nothing is projected until the gate genuinely passes (no bypass, no weakening).
>
> - **Migrations-applied gate → `POST hsp/v1/onboarding/migrate`.** A new WPCS-guarded endpoint (nonce + capability + sanitize at the JSON boundary — DECISION W (a) / V (b)) that **applies the outstanding core + content migrations through the EXISTING migration engine** (`MigrationRunner`) over the **DECISION W (e) delegate list** (core migrations + module migrations collected via the module registry's declarative `getMigrations()` — OPEN-9; Rule 5 — core imports no module migration class). It is a **thin delegator** (`core/Onboarding/MigrationApplier`): **no new engine, no new DDL, no new schema, no new `pg_*` wrapper** (DECISION E — the migration engine keeps its own DDL abstraction), **no new PG handle** (DECISION L Ruling 0). **Guarded on the four ONB-S1b environment preflight checks** (pgsql ext, PG constants, PG reachable, PHP version) — a failing env preflight returns 409; the migrations gate (`MigrationsAppliedCheck`) is re-evaluated after and returned. Idempotent (the engine skips already-applied migrations).
> - **Processing-pipeline gate → `POST hsp/v1/onboarding/spawn-worker`.** A new WPCS-guarded endpoint (`core/Onboarding/WorkerCronSpawner`) that ensures the processing-cycle cron is scheduled and issues a **NON-BLOCKING WP-Cron spawn** (`spawn_cron()` loopback) so a bounded Processing Engine cycle runs and a heartbeat appears. **There is NO in-request tick drain — DECISION W (c) is intact**: the cycle runs **only inside WP-Cron execution**, never inline in the admin request. When `DISABLE_WP_CRON` is set, no spawn is issued and an explicit **WP-Cron-only** warning is surfaced (`wp cron event run --due-now`) — **never** supervisor / systemd / daemon / "restart the worker" wording (ADR-054 §5; DECISION V (f)).
> - **Plugin lifecycle (OPEN-9).** `Application::activate()` / `upgrade()` (and the module `activate()`/`upgrade()` hooks) **attempt the pending migrations through the same shared engine IFF the `HSP_PG_*` constants are defined AND PostgreSQL is reachable**, and are a **silent no-op otherwise** — **activation must never fatal on an unconfigured site** (the connection-free `PgConstantsCheck` gates first; the applier catches all failures). This is one migration path (the shared engine), not a second one; the onboarding migrate endpoint is the in-product path for sites configured *after* activation.
>
> The self-remediation actions are **thin delegators to ratified infrastructure** (the migration engine; the processing-cycle cron) — they introduce **no second repair path**, honour the DECISION W constraints verbatim (no new handle / `pg_*` wrapper / schema / event-type; no in-request drain), and keep the gates' hard-block semantics. Recorded as an architect ruling for ONB-S2 (2026-07-18). See the Session Map ONB-S2 row and `core/Onboarding/`.

**Constraints (binding).** No new PostgreSQL handle (DECISION L Ruling 0 — topology frozen at four; onboarding reuses existing runtime/delivery handles). No new raw `pg_*` wrapper (DECISION E). No new event-type contracts (backfill reuses the OPEN-1 `.updated`/`.deleted` re-emission via DECISION U). No schema migration (completion state is a WP option; progress is derived). No second repair path (DECISION U point 2 / DECISION V (d)). Module isolation (Rule 5): onboarding orchestration lives in `core/`; any WordPress-state read reuses the existing `WpReconciliationSourceInterface` (DECISION U D6) / `ReplayEmitterInterface` (DECISION T) contracts implemented in the Content module — core never imports a module.

**Rationale.** Reusing `ReconciliationService` full-reconciliation for the initial backfill means first-run population and steady-state drift repair share **one** code path and one correctness proof (the DECISION U write-spy / WordPress-wins guarantees), rather than inventing a bespoke bulk-copy that would be a second repair path and a fresh source of divergence. Requiring a live worker before backfill keeps "workers are the execution path" true and avoids long-running admin requests and the coupling of wp-admin to worker execution. Deriving progress and storing only a single WP option honors DECISION Q's zero-new-persistence discipline and needs no migration. Gating the console behind onboarding completion and hard-blocking on prerequisites prevents an operator from reaching a half-configured console that would surface confusing errors (e.g. PG unreachable). Adopting React+shadcn for the admin UI is the architect's chosen resolution of DECISION V (a)'s deferred frontend question; committing `dist/` keeps the production deploy a file copy (no host toolchain) while giving the richer client, and confining WPCS to the JSON endpoints the client calls keeps the security boundary exactly where untrusted input crosses into the platform.

---

### DECISION X — ADR-054 Alignment Rulings (Per-Cycle Identity, Heartbeat Status, `WorkerInterface` Contract, Backfill Prerequisite)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-17 |
| **Session** | ALIGN-S1 (recorded at session STEP 0; ruling given as architect ruling 2026-07-17) |
| **Authority** | Architect ruling 2026-07-17; ADR-054 (WP-Cron Processing Engine — authoritative execution model); Doc 8 v2.0 §8/§9/§15/§16/§24/§25; DECISION P (heartbeat current-state schema — reused verbatim); DECISION Q (metrics/progress without persistence); DECISION W (c) (backfill live-heartbeat prerequisite — reinterpreted here); DECISION R (config-driven cadence precedent); ADR-012 (constructor injection) |
| **Resolves** | FLAG-ALIGN-1 (a)/(b)/(c); FLAG-ALIGN-2 |
| **Amends** | `core/Contracts/WorkerInterface.php` surface (Phase-0-frozen contract — corrected here, not by mid-implementation choice); Doc 8 v2.0 heartbeat-identity + status-set interpretation (confirms §8/§16) |

The ALIGN-S0 audit (`docs/ADR054-IMPLEMENTATION-AUDIT.md`) surfaced four needed-ruling items that gate the ADR-054 alignment-implementation work. The architect ruled as follows.

**(1) Worker identity = Option A — fresh UUIDv7 per cycle (resolves FLAG-ALIGN-1 (a)).** Each WP-Cron **processing cycle mints a FRESH UUIDv7** `worker_id` at cycle bootstrap (Doc 8 v2.0 §24). A `system.worker_heartbeats` row therefore represents a **processing-cycle execution**, NOT a long-lived daemon identity. The table **accumulates one row per recent cycle** (per `worker_type` stage); maintenance may **prune stale rows under existing retention** (an age sweep — no new schema, no history table beyond what DECISION P already allows as current-state rows keyed by the per-cycle UUID). This cardinality is precisely what makes the ADR-054 §6/§17 reinterpretation coherent: `worker_count` = distinct processing-component rows that heartbeated within the freshness window (cycles/stages that ran recently), and the ADR-054 §17/§27 **cycles_completed / avg_cycle_duration** metrics become **derivable on demand with zero persistence** (DECISION Q) by counting/averaging recent-cycle rows. (The prior implementation's "self-assigned once at construction … never changes during the worker lifetime" is superseded.)

**(2) Heartbeat status set = `'running'`/`'idle'` only (resolves FLAG-ALIGN-1 (b)).** In v1.x the heartbeat `status` value set is **exactly** `'running'` and `'idle'` (Doc 8 v2.0 §16). `'processing'` → **`'running'`**; **`'shutdown'` is removed** (a cycle terminates normally at its batch/budget boundary — there is no shutdown signal to record). The `system.worker_heartbeats` **schema is unchanged** (DECISION P not re-migrated); only the emitted `status` string values change.

**(3) `WorkerInterface` contract shape = Option A — architectural correction (resolves FLAG-ALIGN-1 (c)).** `WorkerInterface` is an **internal core contract, not a module-implemented one** (no module implements it; it is core-owned processing infrastructure). ADR-054 §8 retains the class *names* but the contract *surface* is a Phase-0-frozen contract whose disposition is an architect ruling. **Ruling: `run()` and `shutdown()` are REMOVED.** The corrected contract expresses **exactly one bounded processing cycle**: execute one cycle, honour the configured per-stage batch limits + execution-time budget, and **return a processing result describing the completed cycle** (stages run, batch counts, whether the budget was hit, whether any work was done). The exact method signature + result type are the implementation session's to name; the **intent is fixed** by this ruling. This is an architectural *correction* of a contract that encoded the superseded daemon lifecycle — not a new feature.

**(4) Backfill prerequisite = Option C — scheduled cron event AND recent heartbeat (resolves FLAG-ALIGN-2).** Under the cycle model, the DECISION W (c) "live worker heartbeat is a hard prerequisite" for backfill is satisfied by **BOTH**: (i) the **processing-cycle WP-Cron event is scheduled** (`wp_next_scheduled` truthy — cron is set up to advance the pipeline) **AND** (ii) a **recent processing heartbeat** exists (a cycle ran within the offline threshold — the existing DECISION P age read). Remediation text references **only WP-Cron** — "ensure WP-Cron is firing", "run `wp cron event run --due-now`", "check the processing cron is scheduled" — and **never** supervisor / systemd / daemon / "restart the worker" (there is no supervised process under ADR-054 §5). The `BackfillService` no-in-request-drain design and the re-emission repair path are **unchanged**; only the gate's meaning + remediation change. **This ruling is implemented in ALIGN-S2** (the `BackfillGate` realignment), recorded here now.

**Maintenance recovery cadence under the cycle model (records the DECISION R reinterpretation — ALIGN-S1).** DECISION R (v1.16, OPS-S1) shipped the visibility-timeout recovery sweep on a **config-driven** cadence, throttled by an in-memory `$lastSweepAt` gate keyed on `config/worker.php` → `maintenance.recovery_interval_seconds` (default 30 s). That in-memory gate assumed a continuously-ticking daemon and does **not** survive a per-cron-cycle process exit (every fresh cycle would see `$lastSweepAt = null` and always sweep). Under ADR-054 the maintenance sweep therefore runs **once per processing cycle**, and the **cadence is the processing cron interval** (`config/worker.php` → `processing.interval_seconds`, itself config-driven — the DECISION R config-driven property is **preserved**, only relocated from a per-strategy key to the cron cadence). Consequently **`maintenance.recovery_interval_seconds` is now inert** — retained in the config file for back-compat/operator reference but **read by no code** (superseded). No new persistence, no schema change (DECISION Q / ADR-054 §9). DECISION R's *driver* (`MaintenanceWorkerStrategy` invoking `requeueTimedOut()`) and *config-driven* discipline are both intact; only the throttle mechanism changed from an in-process interval gate to the cron-cycle cadence.

**Constraints (binding — inherited from ADR-054 §9).** No new locking mechanism; no fifth PG handle; no new `pg_*` wrapper; **no schema change / no migration** (heartbeat schema reused verbatim per DECISION P; completion/progress unchanged per DECISION W (d)); the per-event pipeline, DECISION 3 commit, SKIP LOCKED claiming, visibility timeout, replay, and reconciliation are preserved verbatim.

**Rationale.** Fresh-UUID-per-cycle is the only identity model consistent with heartbeats-as-cycle-freshness (ADR-054 §5): a stable per-stage id would give one current-state row and no per-cycle history, forcing new persistence or log-derived metrics for cycles_completed/avg_cycle_duration — both prohibited by DECISION Q. Reducing the status set to `running`/`idle` matches "a cycle either ran or its heartbeat is stale" (§16) and removes the daemon-only `shutdown` state. Removing `run()`/`shutdown()` from `WorkerInterface` corrects a contract that mandated the daemon lifecycle ADR-054 abolished; returning a cycle result gives the trigger + tests a bounded, inspectable outcome. Requiring both a scheduled cron event and a recent heartbeat for backfill is the faithful cycle-model translation of "a worker is draining" — a heartbeat alone could be a one-off manual cycle with no recurring schedule, and a scheduled event alone could be firing into a stalled runtime; together they mean "the pipeline is actually being advanced on a cadence."

---

### ADR-047 — Operations Console as Core Infrastructure

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §1, §3, §19 |

The Operations Console is a **core infrastructure** subsystem, not a collection of ad-hoc WordPress admin pages. Core owns the console's infrastructure — registries, providers, services, aggregation, and rendering — under `core/Operations/`; modules own their implementations (pages, widgets, diagnostics, metrics, actions, endpoints) behind core-owned contracts. Module→module dependencies are prohibited (Rule 5); future extraction of the console remains possible. As amended by DECISION V: the subtree is `core/Operations/` (lowercase); its contracts live under `core/Contracts/Operations/`; the MVP is server-rendered PHP with no node toolchain.

### ADR-048 — Registry-Driven Administration

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §4, §5 |

Console capabilities are discovered through **explicit-registration registries** (Page, Navigation, Widget, Action, Asset), mirroring the platform's existing event/adapter registry model (no reflection, no hardcoding). Registries discover *capabilities*; providers supply *runtime data*. Nothing about the console's pages, widgets, endpoints, or actions is hardcoded in core.

### ADR-049 — Unified Diagnostics

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §11, §12 |

Diagnostics are contributed by **Diagnostics Providers** (Health, Metrics, Configuration/Environment Validation, Version, Warnings, Recommendations) with a common severity scale (OK/Info/Warning/Error/Critical). **Historical health storage is out of scope**; the console reports **current operational state only.** Consistent with DECISION P (single current-state heartbeat row) and DECISION Q (no metrics persistence). As amended by DECISION V: all metric-bearing diagnostics are derived on-demand — zero new persistence.

### ADR-050 — Delivery API Validation

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §15 |

The API Playground validates the delivery API by exercising the published `hsp/v1` endpoints (DECISION N/F) from the admin UI: Endpoint Explorer, Request Builder, Request Execution, Response Viewer, driven by registered endpoint metadata. Execution hits the **live delivery API contract** (Rule 6 — consumers depend on the API contract, not internal schemas). Out-of-MVP display categories (Commerce/Search) are placeholders and must not pull WooCommerce/OpenSearch into scope.

### ADR-051 — Operational Actions

| Field | Value |
|---|---|
| **Status** | **HELD — recorded, NOT citable as authority** (per DECISION V / FLAG-9 B) |
| **Source** | Doc 12 §17 |
| **Blocked on** | Incorporation of FLAG-7 (Flush Queue removed) and FLAG-8 (no Restart Workers) into this ADR's text |

ADR-051 governs registry-driven Operational Actions. It is **HELD**: Doc 12 §17 as written still lists **Flush Queue** and **Restart Workers**, both of which DECISION V removes (FLAG-7 A / FLAG-8 C-modified). Until this ADR's text is revised to (1) drop Flush Queue and any destructive-flush semantic, (2) drop Restart Workers in favour of status + heartbeat + runbook links, and (3) constrain the surviving Replay/Reconcile actions to thin delegators over the DECISION T/U services (FLAG-6 A), **no session may cite ADR-051 as authority.** OPSC-S4 cites DECISION V (d)+(e)+(f) directly, not ADR-051.

### ADR-052 — Registry-Driven Operations Console

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §20 (ADR-052) |

All Operations Console capabilities are discovered through registries and provider contracts. Core never hardcodes pages, widgets, endpoints, diagnostics, metrics, or operational actions. (Reaffirms ADR-048 at the whole-console level.)

### ADR-053 — Operations Console is Read-Only by Default

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §20 (ADR-053) |

The console is **observational by default.** State-changing functionality is implemented **only** as registered Operational Actions protected by capability checks, confirmation, and audit — and, per DECISION V (d)+(j), only as thin delegators to the platform's re-emission primitives. This keeps the console a diagnostics interface (DECISION V (j)); administrative operations remain explicit, discoverable, and auditable.

---

### ADR-054 — Background Processing via WP-Cron Processing Engine (Supersedes ADR-024; Amends ADR-035/ADR-036)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-17 |
| **Session** | ARCH-DOC8-V2 (architecture-only) |
| **Authority** | Product ruling 2026-07-17 (HSP v1.x supports ONLY WP-Cron for background execution); Doc 8 v2.0; ADR-024 (execution model — superseded here); ADR-035 (shared engine + strategies — amended here); ADR-036 (stateless workers — amended here); ADR-037 (traceability — preserved); OPEN-4 (visibility timeout / SKIP LOCKED claiming); DECISION 3 (three-op single-PG-transaction commit); DECISION J (Resolve-stage stale guard); DECISION L Ruling 0 (four-connection topology); DECISION P (heartbeat current-state); DECISION Q (metrics without persistence); DECISION R (config-driven recovery cadence); DECISION T/U (replay/reconciliation via re-emission); CLAUDE.md Rules 3/4/8 |
| **Supersedes** | **ADR-024** (execution-model decision) |
| **Amends** | **ADR-035**, **ADR-036** (daemon-worker wording only); Doc 8 (rewritten to v2.0) |
| **Preserves** | ADR-037; the full event pipeline; DECISION 3/J/L/P/Q/R/T/U guarantees |

**Product ruling (authoritative).** HSP **v1.x supports ONLY WP-Cron** for background execution. There are **no Supervisor, systemd, Docker workers, CLI daemons, or continuously running processes** in the v1.x execution model. **The execution *mechanism* changes; nothing else does** — the event pipeline, per-event processing pipeline, idempotency, ordering, replay, reconciliation, and tracing are all unchanged.

**1. EXECUTION MODEL — WP-Cron Processing Engine.** Background processing is advanced by a **WP-Cron-triggered Processing Engine** that runs **one bounded, stateless cycle per invocation** and exits cleanly: `WP-Cron tick → bootstrap → relay batch → dispatch batch → projection batch → maintenance (as scheduled) → persist operational metrics → clean exit`. Each stage is a **bounded batch** (config-driven max size per stage; `config/worker.php` keys `processing.relay_batch_size` / `dispatch_batch_size` / `projection_batch_size`), not a loop-to-empty. A cycle carries a config-driven **execution-time budget** (`processing.cycle_time_budget_seconds`) set well inside the environment's PHP `max_execution_time`; on reaching the budget the engine stops claiming new work, finishes the in-flight event's single transaction (DECISION 3), records metrics, and exits. WP-Cron may itself be driven by a system cron invoking `wp cron event run --due-now` for reliable cadence — that is a *trigger* for WP-Cron, not a daemon; each invocation still runs exactly one bounded cycle and exits.

**2. STATELESS BETWEEN EXECUTIONS + CONTINUATION.** A cycle holds no state between invocations. All continuation state is the **residual durable state** already in the pipeline: unrelayed `wp_hsp_outbox` rows (`status='pending'`), undispatched `system.events` (absent from `system.queue_jobs`), and unclaimed/expired `system.queue_jobs`. A backlog larger than one cycle drains across **successive** cron executions — the next tick re-derives exactly what remains from those tables. This extends ADR-036 (stateless workers) to "stateless between cron executions."

**3. CONCURRENCY — overlapping cron cycles are safe via EXISTING guarantees only; NO new locking mechanism.** Two cron cycles that overlap are safe because of three mechanisms already frozen in the architecture, and **no new lock (no cron mutex, no advisory lock, no WP transient single-flight) is introduced**:
- **`FOR UPDATE SKIP LOCKED`** on every claim (relay `wp_hsp_outbox` — OPEN-6; dispatch anti-join — DECISION L (c); projection `system.queue_jobs` — OPEN-4). Two overlapping cycles claim **disjoint** job sets and neither blocks the other (proven for concurrent claimants in GATE-S2 criterion 1). Duplicate dispatch is a no-op via `UNIQUE(event_id)` + `ON CONFLICT DO NOTHING` (DECISION L (d)).
- **Aggregate-version ordering** (DECISION J) — Resolve-stage non-locking SELECT + in-transaction `FOR UPDATE` + `GREATEST()` monotonic upsert closes the Resolve→write TOCTOU window if two cycles touch the same aggregate; a superseded event is acked with zero writes.
- **Visibility timeout** (OPEN-4 / DECISION R) — a cycle **killed mid-batch** by `max_execution_time` (or deploy/VPS restart) before the DECISION 3 commit leaves **no partial projection** (single-transaction rollback); the claimed job's `visibility_timeout_at` expires and `MaintenanceWorkerStrategy` requeues it, and the **next** cycle re-claims and reprojects. A committed-but-unacked job re-delivered next cycle is absorbed idempotently (DECISION J guard / `processed_events` dedup).

**Explicit gap check:** the two required scenarios (two overlapping cycles claiming from the same queue; a cycle killed mid-batch recovering via visibility timeout on the next cycle) are **fully covered** by SKIP LOCKED + aggregate versioning + visibility timeout + DECISION 3 atomicity. **No gap requiring a new locking mechanism was found.** If a future workload proves a gap, a new DECISION is required before any lock is added — it must not be improvised.

**4. SCALING.** Throughput scales by **cron frequency + per-stage batch size**, not by a worker pool. Overlap being safe (point 3) means a tighter cron schedule increases throughput without corruption. The <30s sync SLA and <60s queue-lag target are met by tuning cadence and batch size.

**5. HEALTH = PROCESSING FRESHNESS/PROGRESS (not daemon liveness).** There is no daemon to be up/down. Health is the freshness and progress of cycles, derived on demand (DECISION P/Q): last cron execution, last successful cycle, per-stage last-run, queue depth, oldest pending job, processing lag. A **stalled pipeline** = heartbeat age exceeds a config threshold **while** queue depth is non-zero (work exists but no recent cycle advanced it). The DECISION P `system.worker_heartbeats` current-state row and its age-check mechanism are reused **verbatim** (no schema change); only the interpretation changes from "a daemon crashed" to "cycles are not advancing." A missing heartbeat is NOT a dead daemon to restart — there is nothing to restart; it means "cron may not be firing" → runbook.

**6. METRICS.** `worker_uptime` and `restart_count` are **removed** (no process uptime; nothing restarts). Replaced by: cycles completed, avg cycle duration, per-stage throughput, queue backlog, processing lag, oldest-pending-job age — all derived on demand or emitted as structured logs (DECISION Q; no metrics table). `worker_count` is reinterpreted as the count of distinct processing-component rows that heartbeated within the freshness window (cycles/stages that ran recently), not a live-daemon population.

**7. RECOVERY.** Next cron execution (re-derives residual durable work) + queue durability + visibility timeout (DECISION R) + retries (ADR-022) + DLQ (OPEN-3/DECISION A/S) + replay (DECISION T) + reconciliation (DECISION U). A gap in cron firing delays processing but **loses no event** — all continuation state is durable. This is the exact recovery set of Doc 8 v1.0 §26 with "next cron execution" replacing "supervised worker restart" as the primary re-drive.

**8. NAMING (no rename in v1.x).** The implementation class names `WorkerEngine`, `RelayWorkerStrategy`, `EventWorkerStrategy`, `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`, `WorkerStrategyInterface`, `WorkerExecutionContext`, and `HeartbeatPublisherInterface` are **retained** and defined in Doc 8 v2.0 as **processing components invoked by WP-Cron**, not daemons. ADR-054 authorizes this naming continuity; **no rename is proposed** (churn-only). The `system.worker_heartbeats` table and its `worker_id`/`worker_type`/`status`/`last_heartbeat_at`/`started_at` columns are unchanged (DECISION P); `worker_id` is now a per-cycle UUIDv7 processing-component identity. **Table-name continuity (architect ruling, Doc 8 v2.0 §15):** `system.worker_heartbeats` is retained in v1.x for implementation continuity and migration stability even though it now represents processing-cycle health; a **future major version** may add a schema migration renaming it to `system.processing_heartbeats` (or equivalent). No rename/migration is done in v1.x.

**8b. PRODUCT OBJECTIVE — Zero-Configuration Operation (Doc 8 v2.0 Principle 8 + §2b "Why WP-Cron?").** The product goal behind this ruling is elevated to an explicit architectural principle: the platform must operate immediately after plugin activation **without OS services, external supervisors, container orchestration, or manual infrastructure configuration** — install from the WordPress Plugins screen, activate, and synchronization begins. WP-Cron is the *only* supported execution mechanism in v1.x precisely because it is what makes this achievable on any host that runs WordPress. Future versions may add execution drivers via their own ADRs **without changing the processing architecture** (the engine and every guarantee here are trigger-agnostic).

**9. WHAT ADR-054 DOES NOT CHANGE.** The Outbox→Relay→Dispatcher→Queue→Processing pipeline; the per-event pipeline (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack); DECISION 3 three-op single-PG-transaction commit; DECISION J stale guard; DECISION L Ruling 0 four-connection topology (**no fifth handle; no new `pg_*` wrapper** — a cron cycle uses the same runtime/delivery handles); DECISION P heartbeat schema; DECISION Q derived-metrics discipline; DECISION T/U re-emission repair; ADR-037 correlation/causation IDs; at-least-once + idempotent redelivery (ADR-036 correctness); module isolation (Rule 5). **No schema migration** is owed by this ruling (config keys only). No new event contracts.

**Relationship to CLAUDE.md.** CLAUDE.md's "Workers run under systemd / Supervisor / container runtime in production; WP-Cron is a fallback only" statement is a Build/Test/Run note that **conflicts with this product ruling** and is flagged for update in the conflict report (§ below); ADR-054 (this record) wins by precedence (line 3). Reconciliation's existing WP-Cron *trigger* authorization (DECISION U point 5) is consistent with — and generalized by — this ruling: under v1.x, WP-Cron is the trigger for **all** background processing, not only recovery jobs.

**Rationale.** A CMS-sync plugin deployed on ordinary WordPress hosting cannot assume a process supervisor; requiring systemd/Supervisor/daemon workers (ADR-024) narrows the deployable environments and adds an operational surface the plugin cannot own. A bounded WP-Cron cycle runs everywhere WordPress runs, exits before `max_execution_time`, and — because every correctness guarantee the pipeline relies on (SKIP LOCKED, aggregate versioning, visibility timeout, single-transaction commit) is independent of *how* processing is triggered — delivers the same at-least-once, ordered, idempotent, replayable, reconcilable behaviour as a daemon would, with overlap as a throughput lever rather than a hazard. Retaining the class names avoids a churn-only rename while Doc 8 v2.0 redefines them semantically.

---

#### ADR-054 Conflict Report — daemon / long-running-worker assumptions in other docs (report only; those docs are NOT edited by this ruling)

The following sections assume long-running/CLI-daemon workers, external process supervision, or worker-liveness health/metrics, and **conflict with ADR-054 (WP-Cron only, v1.x)**. Per precedence (line 3), ADR-054 wins; these are recorded for a future doc-reconciliation session. No fix is applied here beyond Doc 8 (v2.0) and this record.

**Doc 1 — Technical Architecture Specification** (`docs/01-…`)
- §"Core Platform" (line ~208) and the pipeline diagram (line ~280) name **"Worker Infrastructure"/"Worker"** as the execution component. *Severity: LOW — nomenclature only; "Worker" is the retained processing-component name (ADR-054 §8). No daemon/supervisor assumption stated. Reconcile terminology only.*

**Doc 2 — Plugin Folder Structure & Code Organization** (`docs/02-…`)
- §19 "Worker Infrastructure" (line ~482): `core/Workers/` subtree `Consumers/ Scheduling/ Recovery/ Monitoring/ Contracts/`, and `WorkerInterface.php` (line ~187). *Severity: LOW — folder/interface names retained (ADR-054 §8). `Scheduling/`+`Recovery/` are actually consistent with a cron-triggered model. No worker "entry point"/daemon binary is defined in Doc 2 (the brief's "worker entry points in Doc 2" concern resolves to these structural names — no `bin/worker` daemon script exists). Reconcile terminology only.*

**Doc 3 — Database Design & Persistence Architecture** (`docs/03-…`)
- §21 `system.queue_jobs` (line ~838) includes `started_at`, and Doc 3 has **no `worker_heartbeats` table** of its own (the heartbeat schema the brief refers to lives in Doc 4 §19 / Doc 8 v1.0 §15 and was frozen by DECISION P). *Severity: LOW — `system.queue_jobs.started_at` is per-job claim time, fully compatible with cron cycles; the DECISION P heartbeat schema is unchanged (reinterpreted, not re-schema'd). No conflict requiring a Doc 3 edit; noted for completeness because the brief flagged "worker heartbeat schema in Doc 3."*

**Doc 4 — Queue & Event Processing Architecture** (`docs/04-…`)
- **§20 ADR-024 "Worker Execution Model"** (line ~684): *Primary = CLI Workers (WP-CLI / Supervisor / Systemd); Fallback = WP-Cron.* **DIRECT CONFLICT** — ADR-054 inverts this (WP-Cron only for v1.x). *Severity: HIGH. This is the decision ADR-054 supersedes.*
- **§19 "Worker Heartbeats"** (line ~654): workers *publish* `worker_id/status/current_job/memory_usage/started_at/last_heartbeat_at`. *Severity: MEDIUM — heartbeat is reinterpreted as per-cycle current-state (DECISION P / ADR-054 §5); "workers publish continuously" wording conflicts. `current_job`/`memory_usage` are daemon-liveness framing.*
- §30 Approval Checklist "CLI Worker Strategy" (line ~1049) and §29 "Horizontal Scaling" (line ~1016, "Worker 1..N consume concurrently"). *Severity: MEDIUM — horizontal *process* scaling superseded by cron-frequency+batch scaling (ADR-054 §4); overlapping cycles preserve the concurrency guarantee.*

**Doc 5 — Event Architecture & Contract Design** (`docs/05-…`)
- No daemon/supervisor/worker-liveness assumptions found. Event immutability (§26) is preserved. *No conflict.*

**Doc 7 — Adapter Architecture & Delivery Projection Design** (`docs/07-…`)
- §19 "Bulk Operations" / `bulkPersist()` (lines ~640–679) references reconciliation/replay bulk paths. *Severity: NONE — orthogonal to execution model; `bulkPersist()` is a fail-fast stub in Phase 1A (FLAG-P1AS4-3) and unrelated to trigger. No conflict.*

**Doc 9 — Delivery API & Consumption Architecture** (`docs/09-…`)
- No worker/daemon/supervisor assumptions found (consumer-path doc). *No conflict.*

**Doc 10 — Operations, Deployment & Runtime Architecture** (`docs/10-…`) — **most conflict-heavy**
- **§7 "Worker Execution Strategy"** (line ~232): *"CLI workers are required … running under systemd / Supervisor / Container Runtime"; "WP-Cron is not the primary execution mechanism."* **DIRECT CONFLICT** with ADR-054. *Severity: HIGH.*
- **§24 "Worker Availability" target 99.9%** (line ~768). **CONFLICT** — there is no persistent worker to have an availability figure; replace with a **processing-freshness / cycle-cadence** target (ADR-054 §5). *Severity: HIGH.*
- **§27 Deployment Tooling Boundary** (line ~836): supported assets include **"systemd Templates", "Supervisor Templates", "Worker Launch Scripts"** (lines ~849/851/859). **CONFLICT** — these presuppose supervised daemons; not authorized under v1.x. *Severity: HIGH.*
- **§28 Infrastructure Compatibility Matrix** (line ~882): "Supported With Limitations: Shared Hosting **only when … Long-running workers possible**" (line ~907) and "Unsupported: **Environments Without Process Supervision**" (line ~916). **CONFLICT** — ADR-054 makes shared hosting *without* supervision a first-class target; "process supervision" must not be an unsupported-gating requirement. *Severity: HIGH.*
- §4 Topology C "Horizontally Scaled — Multiple Workers" (line ~160) and §5 Topology Migration (line ~180). *Severity: MEDIUM — reframe "multiple workers" as cron-frequency/batch scaling.*
- §20 "Worker Monitoring" (line ~660): `uptime`, `heartbeat_age` as worker-liveness. *Severity: MEDIUM — `uptime` removed; `heartbeat_age` reinterpreted as cycle-freshness (ADR-054 §5–6).*
- §23 Alerting "Worker Offline" (line ~723). *Severity: MEDIUM — becomes "processing stalled: heartbeat stale while queue non-empty."*
- §26 Operational Runbooks "Worker Failure" (line ~810). *Severity: LOW — becomes "cron not firing / processing stalled" runbook.*

**Doc 11 — Development Roadmap & Platform Evolution Strategy** (`docs/11-…`)
- §"Scalability Validation — Multiple Worker Processes" (line ~489). *Severity: LOW — this gate criterion is satisfied by concurrent claimants (GATE-S2, already PASS); reframe as "overlapping cron cycles / concurrent claimants." No re-gate needed — the SKIP LOCKED proof already covers it.*
- Header dependency "Document 8 — Worker Architecture & Execution Model" (line ~17). *Severity: LOW — update title to "Background Processing & Execution Architecture" when Doc 11 is next revised.*
- Phase 3 "Operational Hardening / Improved Monitoring / Alerting" (line ~588). *Severity: NONE — compatible; monitoring/alerting reframed per ADR-054 §5–6.*

**CLAUDE.md (project instructions)** — not one of Docs 1–11 but authoritative and conflicting:
- "Workers run under **systemd / Supervisor / container runtime** in production. WP-Cron is a fallback only (recovery jobs, safety checks) — never the primary execution path." **DIRECT CONFLICT** with the product ruling. *Severity: HIGH — flagged for update; ADR-054 wins by precedence (line 3). Also: the Build/Test/Run table's "Run worker (production) — WP-CLI command — TBD" row should be reframed to a WP-Cron trigger.*
- **IMPLEMENTATION_PLAN.md §5b / §4** (Operability & Scalability validation) and any worker-launch operational notes similarly assume daemons; flagged for the same future reconciliation session.

*Recommendation:* a follow-up docs-only reconciliation session should apply ADR-054's wording to Doc 4 §19/§20, Doc 10 §7/§24/§27/§28/§20/§23, Doc 11's Doc-8 title + Scalability wording, and CLAUDE.md's worker-execution note. This session edits **only** Doc 8 (→ v2.0), this ADR record, and STATUS.md, per the ARCH-DOC8-V2 scope.

> **APPLIED 2026-09-05 (DOC-RECON-S1, docs-only) — FLAG-DOC8V2-1 CLOSED.** The reconciliation above
> was carried out: **Doc 4 → v1.1** (header amendment note; §19 heartbeat semantics banner; §20
> ADR-024 status flipped to *SUPERSEDED by ADR-054* with the original decision/reasoning retained
> verbatim as history; §29 horizontal scaling reframed as overlapping cycles; §30 checklist item
> corrected), **Doc 10 → v1.1** (header amendment note + Doc-8 title; §4/§5 topologies; §7 rewritten
> to WP-Cron-only with the v1.0 text retained as quoted history; §20 `uptime` removed and
> `heartbeat_age` reframed as cycle freshness; §23 "Worker Offline" → "Processing Stalled"; §24
> "Worker Availability 99.9%" → processing-freshness target; §26 "Worker Failure" → "Processing
> Stalled / Cron Not Firing"; §27 systemd/Supervisor templates + worker launch scripts removed in
> favour of an optional system-cron trigger example; §28 shared hosting without CLI/supervision
> promoted to first-class supported and the CLI/supervision unsupported-gates replaced by
> `pgsql`/PostgreSQL/WP-Cron-trigger gates), **Doc 11 → v1.1** (header amendment note + Doc-8 title;
> Scalability Validation "Multiple Worker Processes" → concurrent claimants / overlapping cycles;
> Operations Console "Restart Workers" note de-supervisored). **CLAUDE.md** was already reconciled
> by the 2026-07-20 rewrite (no daemon wording remains). No ADR was re-opened and no new ruling was
> made — ADR-054 was already authoritative; this only propagated its wording. No production or test
> code was touched.

---

#### Superseded / Amended ADR status (history preserved — recorded here, not deleted from Docs 4/8)

The original ADR bodies remain in **Doc 4** (ADR-024) and **Doc 8 v1.0** (ADR-035, ADR-036); Doc 8 is rewritten to v2.0 and carries the amended ADR-035 wording inline. Their status is recorded authoritatively here:

- **ADR-024 — Worker Execution Model (CLI Workers primary; WP-Cron fallback).** Status: **SUPERSEDED by ADR-054** (2026-07-17). The v1.x execution model is **WP-Cron only**; CLI-daemon-primary is no longer the ruling. ADR-024's text is retained in Doc 4 §20 as history and must not be cited as current authority for the execution mechanism.
- **ADR-035 — Shared Worker Engine + Specialized Worker Strategies.** Status: **AMENDED by ADR-054** (2026-07-17). The shared-engine + specialized-strategy structure is **retained**; only the *invocation model* is amended (supervisor-launched daemon → bounded WP-Cron-triggered cycle). See Doc 8 v2.0 §5 for the amended wording.
- **ADR-036 — Stateless Worker Design.** Status: **AMENDED (extended) by ADR-054** (2026-07-17). Stateless-worker correctness is **retained and strengthened** to "stateless **between cron executions**." The consequence list "workers may be restarted / recycled / replaced / horizontally scaled" is reframed: there is no daemon to restart/recycle — each cron cycle starts fresh and exits; recycling is a removed no-op concept (Doc 8 v2.0 §13). Horizontal *process* scaling is replaced by cron-frequency + batch-size scaling (Doc 8 v2.0 §10).
- **ADR-037 — Event Traceability (correlation/causation IDs).** Status: **PRESERVED unchanged** by ADR-054.

---

### ADR-055 — OpenAPI Specification, Registry-Generated

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-20 |
| **Session** | OAPI-S1 (interstitial — inserted BEFORE P1B-S0) |
| **Authority** | Doc 12 §15 (endpoint metadata registry — API Playground); Doc 9 §6 (module API ownership), §7 (versioning from day one), §13 (cursor pagination), §22 (public + authenticated endpoints), §26 (contract lifecycle / deprecation); ADR-048 / ADR-052 (registry-driven, explicit registration); ADR-050 (delivery API validated by exercising the published contract); ADR-038 (transport-agnostic contracts — no HTTP/framework types); Rule 5 (module isolation; core owns contracts); Rule 6 (consumers depend on the API contract only); DECISION N/F (`hsp/v1` namespace + REST contracts); DECISION E (no new `pg_*` wrapper); DECISION L Ruling 0 (four-connection topology frozen); ADR-054 (WP-Cron Processing Engine — the generator is NOT part of a processing cycle) |
| **Preserves** | The endpoint metadata registry (`EndpointProviderInterface`/`EndpointDescriptor`), the four-connection topology, the ADR-054 execution model, and every delivery-API contract (DECISION F/N) — all unchanged in substance; the enrichment is additive |

**Ruling.** HSP publishes an **OpenAPI 3.1** description of the delivery API, and it is **generated from the endpoint metadata registry**, not written by hand and not discovered by scanning WordPress.

**(a) Generated from the registry — never hand-authored, never reflection/scan-derived.** The OpenAPI 3.1 document is produced by a generator that consumes the endpoint metadata supplied through `EndpointProviderInterface` (Doc 12 §15). It is **not** a checked-in hand-authored `openapi.json`, and it is **not** derived by reflecting over or enumerating registered WP REST routes (`rest_get_server()->get_routes()` or equivalent). This preserves the OPSC-S1 **explicit-registration idiom** (ADR-048/ADR-052): the platform describes exactly what modules have deliberately registered as endpoint metadata, nothing implicit. A WP route that exists but carries no registered metadata is a **gap to be surfaced** (see (f)), never silently reverse-engineered into the spec.

**(b) Single source of truth = the endpoint registrations.** Because the document is derived from the current registrations **at serve time**, it **auto-updates**: adding, editing, or removing an endpoint registration changes the served spec with **no separate edit** and no regeneration step to forget. There is exactly one place endpoint truth lives — the registrations — and both the API Playground (OPSC-S3) and this document read from it.

**(c) Additive enrichment of the endpoint metadata contract.** The endpoint metadata contract (`core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface`, today five fields: method, route, namespace, displayGroup, description) is **additively enriched** — no field removed, no existing consumer broken — to carry what an OpenAPI 3.1 Operation Object needs:
- **parameters** (path + query params, with types) — including the DECISION F filters (`slug`/`status`/`published_after`/`category`) and the cursor parameter;
- **request/response schema** (JSON Schema fragments for the resource shapes — Rule 6 published-contract shapes, not internal `content.*`/canonical schemas);
- **auth requirement** (public vs authenticated — Doc 9 §22);
- **cursor-pagination envelope** (`data` + `next_cursor` — Doc 9 §13 / DECISION F `CursorPage`), so paginated list operations describe the envelope, not a bare array;
- **deprecation status** (Doc 9 §26 Supported → Deprecated → Removed lifecycle → OpenAPI `deprecated: true`);
- **version** (the contract version the operation belongs to — Doc 9 §7);
- **module owner** (which module registered the endpoint — Doc 9 §6).

**Ownership (Rule 5 holds verbatim).** **Core owns the contract** — the enriched `EndpointDescriptor`/`EndpointProviderInterface` live under `core/Contracts/Operations/`, and the generator lives in core. **Modules own their metadata** — each module populates the enriched descriptors for its own endpoints (Doc 9 §6; e.g. `modules/Content/Operations/ContentEndpointProvider`), depending on `core/Contracts/` only. Core never imports a module; core never hardcodes an endpoint.

**(d) Exposure.** The document is served at **`GET /hsp/v1/openapi.json`**, versioned per Doc 9 §7 — **the `v1` document describes the `v1` contract** (a future `v2` contract gets `/hsp/v2/openapi.json`). The route is registered on the `hsp/v1` namespace (DECISION N) through the normal REST registration boundary; WPCS security rules (capability/nonce/sanitization/escaping) apply at that registration per DECISION V (b) / DECISION W (a) exactly as for any HSP REST endpoint.

**Scoping — ruled (architect 2026-07-20; resolves FLAG-OAPI-1).** The served `/hsp/v1/openapi.json` document describes **PUBLIC endpoints only.** Endpoints requiring authentication or capabilities (Doc 9 §22) are **EXCLUDED from the generated document** — an unauthenticated caller discovers only the public consumer-facing contract (Rule 6). The **exclusion is driven by the endpoint metadata auth field** (the enriched `auth requirement` from (c)), **not by route inspection** — the generator filters descriptors on their declared auth requirement, consistent with (a) (registry-driven, never route-scan-derived). The **generator endpoint itself remains public and stateless**: there is **no capability check inside request-time generation** (consistent with (e) — the endpoint is a synchronous public REST read; it neither authenticates the caller nor branches its output on caller identity). Since all six `hsp/v1` endpoints are public today, the v1 MVP document describes all of them; the filter takes effect the moment any authenticated `hsp/v1` endpoint is registered, keeping it out of the public document with no further ruling. The OAPI-S1 drift guard (f) additionally asserts, positively, that **no non-public-metadata route appears in the generated document** (exclusion test).

**(e) Request-time, stateless, no infrastructure coupling.** Generation runs **at request time** and holds **no state between requests**. It performs **NO persistence** (no spec table, no cache table, no rollups — the document is computed on demand from the registrations, mirroring the DECISION Q derived-on-demand discipline), **NO PostgreSQL read** (endpoint metadata comes from the in-process registry, not from `system.*`/`content.*`), **NO new connection handle** (DECISION L Ruling 0's four-connection topology is untouched), and **NO new `pg_*` wrapper** (DECISION E). It is **NOT part of the ADR-054 processing cycle** — the generator is a synchronous REST read served in the web request; the WP-Cron Processing Engine never invokes it and it never runs inside a bounded cycle. No schema migration and no new event contract are owed by this ruling.

**(f) Drift guard (CI).** A test enforces registry⇄route consistency and document validity:
1. **Every non-exempted registered `hsp/v1` REST route has a complete metadata entry.** The set of **live `hsp/v1` routes** (the external REST index — the ground truth, never the registry, so the assertion cannot be circular) is enumerated, the **structural exemption** below is subtracted, and the remainder is compared against the set of registered endpoint descriptors; a non-exempted route present in the REST index but missing (or incompletely enriched) in the registry **fails CI**. This is the one place route enumeration is permitted — as an **assertion that the explicit registrations are complete**, never as the generation source (which would violate (a)).

   **Structural exemption (ruled 2026-07-20, "A-modified" — v1.28).** Exactly **one** prefix is exempt from the completeness assertion: **`hsp/v1/onboarding/`**. Authority: DECISION W (e) — the onboarding first-run **admin** surface is deliberately outside the published delivery contract (Rule 6 treats the delivery API as the consumer contract; onboarding is a gated pre-completion admin surface, not a delivery endpoint). The exemption is a **single prefix frozen in this ADR**: the guard **hardcodes this one prefix** with an ADR-055 (f) citation comment, and **adding any further exempt prefix requires an architect ruling**. Any **future authenticated `hsp/v1` route OUTSIDE the exempted prefix must still carry a descriptor** (`auth = authenticated`), which keeps it out of the served document via the (d) auth-field filter (asserted by the exclusion test in (3)) — the exemption exempts a route from *needing a descriptor*, not from the public-only *document* scoping. **Net today: 13 live `hsp/v1` routes − 6 `onboarding/` = 7 guarded routes** (six content + `openapi.json`).
2. **The generated document validates against the OpenAPI 3.1 meta-schema.** The produced document is validated against the **pinned official OAS 3.1 meta-schema** so a malformed or non-conformant document fails CI. **Validator (ruling D, v1.29):** the gate runs via **ajv in the Node toolchain** (`tools/openapi-validator/validate-openapi.mjs`; Node is a sanctioned dev/CI dependency — DECISION W (a)), layered over a PHP structural pre-check (the fast-fail). `opis/json-schema` was evaluated and **rejected** — two reproduced 2020-12 conformance defects (dynamic-anchor indexing; `unevaluatedProperties` false-positives) make it unable to validate real OpenAPI 3.1 documents; no conformant PHP 2020-12 validator exists. The fixture is `tests/fixtures/openapi-3.1-meta-schema-pinned.json` (official meta-schema, `$id …/2022-10-07`, four semantics-preserving `$dynamicRef "#meta"` → `$ref "#/$defs/schema"` edits), **pinned and never fetched at test time**. **Environment contract:** node available → gate runs; node missing without `HSP_REQUIRE_NODE_GATE` → the meta-schema assertion SKIPS with a warning; `HSP_REQUIRE_NODE_GATE=1` (CI) with node missing → FAIL.
3. **Exclusion test (v1.27).** No endpoint whose metadata marks it non-public appears in the generated document — asserted positively with a fixture non-public descriptor (public-only scoping per (d)).

   A **non-circularity** assertion accompanies (1): a fixture route registered on `hsp/v1` **outside** the exempted prefix **without** a descriptor **fails the guard** — proving the guard reads the external route index, not the registry it is checking. **Enumeration funnel (omission-proof):** the guard collects the live index by driving the SAME boot path production uses — core REST registrars through the single `core/Rest/RestRegistrarRegistry` list that `headless-sync.php` iterates, and each module's real `boot()` (which hooks its own registrar onto `rest_api_init`) — then fires `rest_api_init`. Nothing is hand-listed in the test, so a registrar added to production but not to a test array cannot exist; adding a core REST registrar in that one list makes it visible to both production and the guard automatically.

A non-exempted route without metadata therefore cannot merge green — the registry stays the authoritative, complete description of the published contract.

**Rationale.** Hand-authored API specs drift the moment an endpoint changes; reflection-derived specs describe whatever WordPress happens to expose, including internals, and defeat the explicit-registration guarantee the console is built on. Deriving the document from the same endpoint registry the API Playground already uses (Doc 12 §15) gives one source of truth, an always-current spec, and a CI guard that makes "an endpoint without a described contract" a build failure — all without new persistence, a new PG handle, or any coupling to the background-processing execution model.

---

### DECISION N — Delivery REST Namespace: `hsp/v1`

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S7 |
| **Authority** | Doc 9 §7 (versioned REST prefix); WP REST API convention (vendor-prefixed namespaces) |

**Ruling:** The WordPress REST namespace for all HSP delivery endpoints is `hsp/v1`.

- `hsp` is the vendor prefix — unambiguous, collision-safe under the WP REST convention where namespaces take the form `vendor/vN`.
- `v1` is the contract version. Future breaking changes in the API contract must go to `v2`; additive non-breaking changes stay in `v1`.
- The namespace is defined in exactly **one** place: `ContentRestRegistrar::NAMESPACE = 'hsp/v1'`. All `register_rest_route()` calls reference this constant. No literal namespace string may appear elsewhere in PHP code.
- Consumer clients (`hsp-blog/lib/api.ts`) and smoke-test tooling (`tools/smoke_e2e.php`) must use the `hsp/v1` path prefix in all fetch/curl calls.

**Supersedes:** The prior `api/v1` string used in `ContentRestRegistrar::NAMESPACE` (P1A-S5 through P1A-S6). `api/v1` was an un-prefixed placeholder; it is replaced by this ruling and must not appear anywhere in the codebase.

**Rationale:** WordPress REST namespaces are conventionally vendor-prefixed (`wc/v3`, `wp/v2`, etc.). A bare `api/v1` prefix is not vendor-scoped and risks collision with other plugins registering the same namespace, or with future WP core endpoints. `hsp/v1` is unique to this platform and communicates both ownership and contract generation at a glance.

---

### DECISION O — Credential Resolution and Configuration Precedence (P1A-S8)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S8 |
| **Authority** | CLAUDE.md (config/ holds no business logic; bootstrap owns env loading); ADR-012 (constructor injection only); DECISION H (wp-config defines are available in the worker process because the worker loads WordPress); DECISION E/K/L (connection topology frozen — this decision changes credential SOURCE only) |

**Ruling:**

**(a) Credential precedence — define → getenv → default.**  
For every HSP credential key, the resolution order is:
1. `defined('HSP_*') ? constant('HSP_*') : null` — `wp-config.php` `define()` constants (highest precedence; the idiomatic WP way to configure plugins)
2. `getenv('HSP_*')` — environment variable fallback (Docker / CI / legacy `putenv()` callers)
3. Documented default (empty string / well-known port number), or **hard failure** for required credentials that have no safe default

**(b) Required PostgreSQL credentials fail loud.**  
`HSP_PG_HOST`, `HSP_PG_USER`, `HSP_PG_PASSWORD`, and `HSP_PG_DBNAME` are required. If any resolves to an empty/null value from both sources (define + getenv) and no meaningful default exists, `CredentialResolver` throws a `\RuntimeException` with a clear diagnostic message naming the missing credential. Silent defaults that produce a broken but non-fatal connection string are prohibited for these four keys. `HSP_PG_PORT` defaults to `5432` and is not required.

**(c) MySQL inherits WordPress DB_* constants by default.**  
`CredentialResolver` derives MySQL connection parameters from the WordPress native constants `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` that WordPress sets in `wp-config.php` before any plugin runs. An optional `HSP_MYSQL_*` override path exists (`HSP_MYSQL_HOST`, `HSP_MYSQL_PORT`, `HSP_MYSQL_NAME`, `HSP_MYSQL_USER`, `HSP_MYSQL_PASSWORD`) but is unused by default. HSP does NOT duplicate WP DB credentials in `wp-config.php` — the resolver reads `DB_*` directly from WP constants when no `HSP_MYSQL_*` override is present.

**(d) One resolver; provider factories read the resolver.**  
All credential resolution runs through a single `HSP\Bootstrap\CredentialResolver` class. The four runtime PostgreSQL provider factories (Outbox, Queue, Delivery, Dispatcher) and the Relay MySQL factory each receive the resolver via constructor injection (ADR-012 compliant) and call its methods rather than reading `getenv()` or the config array directly for credential values. No provider factory may call `getenv()` directly for DB credentials.

**(e) Test DSN injection preserved.**  
Integration tests that inject raw `pg_connect()` DSNs continue to do so directly. The resolver is for runtime provider factories only. No integration test is rewired to the resolver.

**(f) wp-config.php uses define(), not putenv(), for HSP PostgreSQL credentials.**  
Local development sets HSP PG credentials via `define('HSP_PG_HOST', '127.0.0.1')` etc. in `wp-config.php`. The prior `putenv()` approach is replaced. No real secrets are committed — the `wp-config.php` local-dev block carries only the local Docker credentials used in the development environment. The credential key names are `HSP_PG_HOST`, `HSP_PG_PORT`, `HSP_PG_DBNAME`, `HSP_PG_USER`, `HSP_PG_PASSWORD`.

**(g) Connection topology is unchanged.**  
This decision changes only the credential source. The four FORCE_NEW handles established by DECISION K/E/L remain exactly as-is. FLAG-P1AS6D-1 stays Open and is not touched. No handle is merged, removed, or added.

**Rationale:** `define()` constants are the idiomatic WordPress mechanism for plugin configuration. Using `getenv()` as the primary source required callers (including CI) to duplicate secrets in both `wp-config.php` and environment variables. Centralising resolution in one class with explicit precedence eliminates that duplication, makes the configuration surface visible at a glance in `wp-config.php`, and ensures required credentials fail loudly rather than producing a mystery connection error at `pg_connect()` time.

---

### OPEN-10 — Unpublish Transition Capture: event action and projection model for post_status leaving the public set

| Field | Value |
|---|---|
| **Status** | **Resolved — P1A-S1 close (2026-06-23)** |
| **Raised** | 2026-06-23 — P1A-S1 review |
| **Resolved** | 2026-06-23 — architect ruling, implemented in P1A-S1 |
| **Blocks** | ~~`HookWiring::onTransitionPostStatus` guard completion~~ — resolved |

#### Ruling (Option A — public-set membership, `*.deleted` on exit)

**Public set = `{publish}` only.** `draft`, `auto-draft`, `pending`, `private`, `future`, `inherit`, `trash` are all non-public.

**Approved transition matrix (implemented in `HookWiring::onTransitionPostStatus`):**

| Old status | New status | Event emitted |
|---|---|---|
| non-public | `publish` | `content.{type}.created` (entry) |
| `publish` | `publish` | `content.{type}.updated` (in-set) |
| `publish` | non-public (any) | `content.{type}.deleted` (exit) |
| non-public | non-public | NO event |

`wp_trash_post` is suppressed when `transition_post_status` already handled the post_id in the same request (transition is authoritative for trash). `after_delete_post` always emits `*.deleted` independently (permanent hard-delete path, no overlap with transition).

**Sub-question rulings:**
1. Option A governs all exit transitions for MVP.
2. `private` is NOT in the public set — `publish → private` emits `*.deleted`.
3. `future` is NOT in the public set — `publish → future` emits `*.deleted`; when the cron fires and status moves to `publish`, that transition emits `*.created`.
4. `wp_trash_post` and `after_delete_post` remain as separate wired hooks. `wp_trash_post` is suppressed by the `$handledByTransition` guard when `transition_post_status` already fired (avoiding double-emit for a trash action). `after_delete_post` is NOT suppressed (it is the hard-delete path, fires independently of transition for permanent deletes from the trash screen).
5. Sub-question 5 (Option B adapter branching) is moot — Option A was chosen.

#### Problem statement (retained for context)

`HookWiring::onTransitionPostStatus` previously bailed on every transition whose `$newStatus !== 'publish'`. This dropped four WordPress post-status changes that are not trash operations and are not caught by `wp_trash_post` or `after_delete_post`: `publish → draft`, `publish → pending`, `publish → private`, `publish → future`. The result was a lost sync — a stale published row in the delivery projection with no delete event emitted.

---

### DECISION Y — PostgreSQL Full-Text Search Deferred from Phase 1B to Phase 5

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-05 |
| **Session** | P1B-S0 (Phase 1B planning) |
| **Authority** | Product decision 2026-09-05 (scope owner); Doc 11 §7 (Phase 1B deliverables), §14 (Phase 5 — Search Expansion), §17 (Search Roadmap); Doc 9 §14/§15 (search architecture, provider-based); Doc 3 §27 (search & indexing strategy) |
| **Resolves** | The Phase 1B/Phase 5 placement of PostgreSQL full-text search |
| **Amends** | Doc 11 §7 (v1.1 → v1.2 — "PostgreSQL Search" removed from the Phase 1B deliverable list and from the §7 "Search Queries" validation item); IMPLEMENTATION_PLAN.md §5 Phase 1B/Phase 5 pointers. **Nothing else** |

**Ruling.** **PostgreSQL full-text search is NOT a Phase 1B deliverable.** Phase 1B — Content
Enhancement is: **featured images, media synchronization, tags, basic ACF, and pagination**.
Search — the PostgreSQL provider included — is delivered in **Phase 5, Search Expansion**
(Doc 11 §14), which already states that PostgreSQL Search remains supported.

**Explicitly unchanged.** The **§17 Search Roadmap ordering stands**: PostgreSQL Search still
comes first, before the search provider contract and any OpenSearch/Typesense provider — only
its *phase placement* moved. **Doc 9 §14/§15** (`SearchProviderInterface`, provider-based search
strategy), **Doc 3 §27** (search & indexing strategy, `tsvector`), and the Doc 5/6/7
search-projection references are **untouched**: they describe Phase 5+ material and were never
Phase 1B commitments. No search contract, schema, index, migration or endpoint is created,
renamed, or removed by this decision — Phase 1B simply does not open the topic.

**Why it is recorded rather than edited in.** Doc 11 is frozen and listed "PostgreSQL Search"
among the Phase 1B deliverables. A scope change against a frozen document requires an explicit
ruling here (precedence: this document wins), with the superseded line retained under a banner
in Doc 11 §7 rather than deleted — the same treatment DOC-RECON-S1 applied to the ADR-054
sibling-document reconciliation.

**Consequence for planning.** The IMPLEMENTATION_PLAN §5b Phase 1B session map authored in
P1B-S0 contains **no search session**, and no Phase 1B session DoD may introduce a `tsvector`
column, a full-text index, or a search endpoint. A Phase 1B row that would benefit from search
must instead rely on the existing DECISION F filters and cursor pagination.

---

### DECISION Z — Lazy PostgreSQL Connections at the Container Boundary

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-05 |
| **Session** | LAZYPG-S1 (interstitial, inserted before P1B-S0) |
| **Authority** | ONB-S1b OBSERVATION ("lazy-connection ruling pre-Phase-1B", carried forward in STATUS.md); DECISION E (v1.6 — shared runtime PG connection layer, no new `pg_*` wrapper, boundary error translation); DECISION K (v1.11 — delivery connection isolation); DECISION L Ruling 0 (four-handle topology, frozen); ADR-012 (constructor injection); ADR-054 Principle 8 (the platform must not fatal on an unconfigured site) |
| **Resolves** | The ONB-S1b eager-connection observation; retires the "lazy-connection ruling pre-Phase-1B" carry-forward |
| **Amends** | Nothing frozen. No contract, schema, migration, event, or handle topology changes — only *when* a libpq link is opened |

**Ruling.** The four runtime PostgreSQL handles are opened **lazily, on first real use**, not at
container-resolution time. Each service-provider factory hands a **connector `\Closure`** to its
connection wrapper instead of an already-open handle; `PostgresDatabaseConnection` accepts either
form, invokes the connector at most once, memoizes the handle, and translates any connect failure
to `DatabaseException` at that boundary (subsystems keep translating it onward to
`OutboxWriteException` / `QueueException` per DECISION E v1.6). `rollback()` on a connection that
was never opened is a no-op — it must not dial the socket to undo a transaction that cannot exist.

**Why.** `PostgresDatabaseConnection` previously required an already-open handle, so all four
providers called `pg_connect()` inside their singleton factory and let a raw `\RuntimeException`
escape when PostgreSQL was unreachable or unconfigured. On the delivery handle this was
user-visible: `ContentModule::boot()` correctly defers registrar construction to `rest_api_init`,
but that hook fires on **every REST request to the site** — `wp/v2` and the block editor
included — and building the registrar resolves the query providers, which opened the socket.
An unreachable PostgreSQL therefore fatalled **every REST request**, not just `hsp/v1`. The
capture path had already been hotfixed this exact way (`MysqliOutboxConnection` takes a connector
closure); this ruling applies the same idiom to the PostgreSQL side, where all four callers route
through one class.

**Explicitly unchanged.** Still **four** handles, each still opened with its existing flags —
`PGSQL_CONNECT_FORCE_NEW` on delivery, queue-claim and dispatcher; no flag on the relay handle
(DECISION K isolation and DECISION L Ruling 0 topology are untouched). **No fifth handle, no new
`pg_*` wrapper class** (DECISION E), no persistence, no schema change, no contract change. The
migration engine's DDL-only `ConnectionFactory` is out of scope and keeps connecting eagerly —
it is only reached from explicit migrate actions, which already gate on reachability
(DECISION W (f)).

**Precedence note.** DECISION **Y** is reserved for the P1B-S0 Phase-1B search deferral; this
ruling landed first and took the next free letter after it.

---

### DECISION AA — Shared Taxonomy Projection Per Owning Domain (FLAG-TAXSCHEMA-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-06 |
| **Session** | FLAG-TAXSCHEMA-1 (interstitial, after P1B-S5 / before Phase 2) |
| **Authority** | Architect ruling 2026-09-06 answering FLAG-TAXSCHEMA-1 (raised P1B-S3 closeout); Rule 2 (transform before persist); Rule 5 (module isolation); P1A-S4 ruling on `content.entity_taxonomies` (pure join table) |
| **Resolves** | FLAG-TAXSCHEMA-1 — one shared `content.taxonomies` table with a `taxonomy_type` discriminator, or a table per taxonomy? |
| **Amends** | Nothing frozen. The P1A-S4 pure-join ruling on `content.entity_taxonomies` is **upheld, not amended** (splitting the term table would have forced it open — that is precisely the cost this ruling declines to pay). No contract, event, handle or column changes; index shapes only |

**Ruling.** Taxonomies project into **one shared table per owning domain/module**, told apart by
a `taxonomy_type` discriminator. For the Content module that is `content.taxonomies` +
`content.entity_taxonomies`, carrying `category`, `post_tag` and any future Content taxonomy
(`genre`, `topic`, `location`, …). **Not** one physical table per taxonomy. **Not** one
platform-wide taxonomy table shared across domains.

A new Content taxonomy is therefore **new data, not a new table and not a migration**.

**Domain boundary.** The shared model is **per domain**, never platform-wide. Future WooCommerce
concepts do **not** belong in `content.taxonomies` merely because WordPress happens to store them
through the taxonomy API; Commerce gets its own projection model (`commerce.*`), designed when the
Commerce module is designed and **not invented during Content work**. The governing rule:

> Same domain + genuinely equivalent taxonomy semantics = shared taxonomy table.
> Different domain or meaningfully different business semantics = domain-specific projection.

**Query rule (the cost of the shared table, and the thing that must be enforced).** When the
taxonomy type matters, a query must identify **both the taxonomy type and the term identity** —
`WHERE taxonomy_type = 'category' AND slug = 'technology'`. A read keyed on the globally-unique
`source_term_id` is already unambiguous and needs no discriminator; **every other read of the
shared table needs one**. This is not bookkeeping: the same forgotten predicate has produced five
defects — three in P1B-S3 (`?category=` matching a tag, `/categories/{slug}` resolving a tag, and
the sibling class still open as FLAG-PAGESLUG-1) and two found while applying this ruling (the
operations console counting tags as categories; the onboarding backfill counting tags toward the
projected category total, which could declare convergence while categories were still missing).
`tests/Unit/Content/TaxonomyQueryRuleTest.php` enforces the rule statically so the class is
**detectable** rather than merely known.

**Index shapes.** Indexes are designed around the **actual delivery query paths**:
`content.taxonomies (taxonomy_type, slug)`; `content.entity_taxonomies (entity_id, taxonomy_id)`
(the existing PK) and `(taxonomy_id, entity_id)`. The pre-existing single-column
`(slug)` and `(taxonomy_type)` indexes are **dropped** — no query looks a term up by slug alone,
and `(taxonomy_type)` is a strict prefix of the composite. `(taxonomy_type, parent_id)` is named
in the ruling but **deliberately not created**: no delivery query filters, orders or joins
taxonomies by parent today, so it would be an index nothing reads, paid for on every write. It
ships with the first endpoint that walks the term tree.

**Explicitly rejected, and why.** *Table per taxonomy* was rejected on architectural scalability,
not runtime: with the right indexes PostgreSQL resolves a taxonomy type + slug without scanning
every taxonomy row, whereas each new taxonomy would otherwise cost a migration, a table, adapter
and query-provider logic, and — because `taxonomy_id` would no longer identify which table it
points into — either a **second** table per taxonomy for the join or a discriminator on the join
table, reintroducing the rejected pattern one level down and reopening the frozen P1A-S4 ruling.
**No partitioning and no table-per-taxonomy optimisation** without profiling that demonstrates a
real problem plus a future ruling authorising it.

**Explicitly unchanged.** `content.taxonomies` and `content.entity_taxonomies` keep their columns,
keys and constraints; `content.entity_taxonomies` remains a **pure join table** (composite PK only,
no timestamps, no checksum, no metadata — P1A-S4). No new handle (DECISION L Ruling 0), no new
`pg_*` wrapper (DECISION E), no event or contract change, no persistence added.

---

### DECISION AB — Sync-Latency SLA: 20s Shipped Cadence + Out-of-Band Trigger Obligation (FLAG-P1BS0-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-06 |
| **Session** | FLAG-P1BS0-1 (interstitial, after Phase 1B close) |
| **Authority** | Product/architect ruling 2026-09-06 answering FLAG-P1BS0-1 (raised P1B-S0, measured P1B-S5); PRD §Performance / §Success Criteria (<30s sync SLA); ADR-054 §4/§5/§23 (cadence + batch size are the only throughput levers; WP-Cron is the only mechanism); Principle 8 (zero configuration) |
| **Resolves** | FLAG-P1BS0-1 — the shipped 60s cycle cadence cannot meet the PRD <30s sync SLA, and no test measures sync latency |
| **Amends** | Nothing frozen. ADR-054 is **not** reopened: no daemon, no supervisor, no worker pool, no in-request drain. Doc 10 §7's "Reliable Cadence (optional, recommended)" is **narrowed in effect, not contradicted** — still optional for function, now required for the SLA |

**Ruling — options (a) + (c) together, both halves required. (b) and (d) are rejected.**

**(1) The shipped default is `processing.interval_seconds = 20`** (was 60), with
`ProcessingCronRegistrar::DEFAULT_INTERVAL_SECONDS` tracking it so the code fallback cannot
contradict the config. The P1B-S5 measurement makes this free rather than a trade-off: the whole
pipeline (outbox → relay → dispatch → project → readable) costs **0.06s** for a single edit and
**~6–9s** for a saturated 200-event batch, i.e. **~0.2% of sync latency against the cadence's
~99.8%**. Worst case becomes **≈20.1s** (≈26–29s at a saturated 200-batch — the burst regime, where batch size is the other lever) inside the 30s SLA, with the cycle still
finishing far inside `cycle_time_budget_seconds = 20`. Overlapping cycles remain safe by the
existing guarantees only (`FOR UPDATE SKIP LOCKED` + aggregate versioning + visibility timeout +
the DECISION 3 atomic commit) — ADR-054 §3 adds no lock and none is added here. The change
propagates on the next firing: `wp_reschedule_event()` resolves the interval by **schedule name**
from `cron_schedules`, so there is **no migration and no re-scheduling step**.

**(2) The SLA carries an explicit operator obligation, because (1) alone cannot deliver it.**
WordPress's own request-triggered cron refuses to spawn more often than `WP_CRON_LOCK_TIMEOUT` —
**60s by core default**, enforced in `spawn_cron()` (*"Don't run … more than once every 60 sec."*).
A 20s *schedule* therefore still fires at best every 60s on a default site, and less than that on
a quiet one, since WP-Cron only runs on traffic at all. **`wp cron event run` defines `DOING_CRON`
and bypasses that path**, so the <30s SLA holds **only** where an out-of-band trigger invokes
`wp cron event run --due-now` at **≤ 20s** (system cron is minute-granular — use three offset
entries at `sleep 0/20/40`, paired with `DISABLE_WP_CRON`). This remains a **trigger, not a
daemon**: one bounded cycle per invocation, then exit (ADR-054 §5/§23). Recipe:
`docs/notes/PERFORMANCE-BRIEF.md`.

**(3) Consequently the <30s SLA is a supported *deployment property*, not an unconditional
platform guarantee.** Without the trigger the platform still operates with **zero configuration**
(Principle 8) — content syncs, nothing is broken, nothing needs starting; only the SLA is unmet.
Any document restating the SLA must state the trigger condition with it.

**(4) The SLA is now asserted, not aspirational.**
`ProcessingCycleIntegrationTest::test_end_to_end_sync_latency_through_one_cycle` reads
`interval_seconds` from the **shipped config file** (it must never be restated in the test — the
P1B-S5 version hardcoded 60 and would have gone silently stale) and **asserts the worst case is
under 30s**. Raising the interval past the SLA fails the suite and requires a new ruling.

**Rejected.**
- **(b) Restate the SLA's measurement basis** (p50, or "from cycle start"). The measurement removed
  the reason to concede anything: the pipeline is 0.2% of the number. Redefining a product
  commitment to fit a config default we could simply change is backwards.
- **(d) Emit `spawn_cron()` on capture.** The same 60s `WP_CRON_LOCK_TIMEOUT` makes it **ineffective
  for the stated purpose** — it cannot produce sub-60s cadence on the very sites that need it —
  while adding a loopback HTTP request to every content save and edging toward the in-request drain
  DECISION W (c) prohibits. The capture path stays free of cron concerns; `spawn_cron()` remains
  used **only** by the onboarding remediation endpoint (`core/Onboarding/WorkerCronSpawner.php`).

**Explicitly unchanged.** No schema, no migration, no new persistence, no contract change, no new
PG handle (DECISION L Ruling 0) and no `pg_*` wrapper (DECISION E). Batch sizes and
`cycle_time_budget_seconds` are untouched — cadence was the whole problem.
`heartbeat.offline_after_seconds = 60` is untouched and stays correct (it is now three missed
cycles rather than one, a strictly more forgiving staleness threshold; DECISION P/X (4) gates are
unaffected).

---

### DECISION AC — Reconciliation and Backfill Cover Every Supported Aggregate (FLAG-RECON-COVERAGE-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Session** | FLAG-RECON-COVERAGE-1 (interstitial, after Phase 1B close) |
| **Authority** | Scope-owner directive 2026-09-07 to resolve FLAG-RECON-COVERAGE-1 (raised while applying DECISION AA); DECISION U (reconciliation modes; repair is re-emission only); DECISION W (b)/(d) (backfill IS `reconcileFull()`; progress derived on demand); DECISION AA (shared-table query rule); Rule 1 (WordPress is source of truth) |
| **Resolves** | FLAG-RECON-COVERAGE-1 — `media` and `tag` were implemented in the reconciliation SOURCE and the replay emitter but absent from the consuming projection maps, so no reconcile mode touched them and a fresh install never backfilled them |
| **Amends** | Nothing frozen. DECISION U's mode set, DECISION T's re-emission primitive, DECISION W (b)'s "backfill = full reconciliation" and DECISION Q's derive-on-demand rule are all unchanged — this closes a coverage gap inside them. It DOES change what "converged" means (see (2)) |

**Ruling — options (a) + (c) together, as the flag recommended. (b) is rejected.**

**(1) Coverage completed — three lists, no new mechanism.** `media` and `tag` join the three
consuming maps that had been carrying a three-of-five list:
`ReconciliationService::PROJECTION` (`media` → `content.media`/`source_post_id`; `tag` →
`content.taxonomies`/`source_term_id` scoped `taxonomy_type = 'post_tag'`),
`BackfillReader::PROJECTION` and `BackfillProgress::TYPES`. Everything upstream was already
built — `WpReconciliationSource` and `ContentReplayEmitter` have handled all five aggregates
since P1B-S3 — so this adds **no** worker, adapter, extractor, transformer, event, migration or
repair path. The DECISION AA scoping applied in the FLAG-TAXSCHEMA-1 session is the
**prerequisite** that makes the `tag` entry safe: without a `taxonomy_type` predicate the
`category` and `tag` orphan passes would each claim the other's rows.

**(2) Convergence semantics change, deliberately and in the strict direction.** Onboarding now
counts media and tags toward both expected and projected, so a site that previously flipped
**complete** with zero media and zero tags projected now stays **in progress** until they land.
This is the correct reading of DECISION W (d): the backfill re-emits all five aggregates, so the
completion signal must score all five. Nothing that was converged becomes un-converged in a
misleading way — a site reporting complete with unprojected media was reporting a state that was
never true.

**(3) The seam is GUARDED, not documented.** The failure mode here was silence: `reconcile()`
`continue`s past any supported type with no PROJECTION entry, so the omission produced no error,
no log line and no failing test — only absent content. `tests/Unit/Reconciliation/AggregateCoverageTest.php`
now asserts that every type in `WpReconciliationSource::AGGREGATE_TYPES` has an entry in all
three consuming lists **and** is re-emittable by `ContentReplayEmitter`. It is a **unit** test on
purpose: `Phase1BValidationTest`'s producing-side assertion is integration-only and self-skips
without a live database, which is precisely the condition under which a new aggregate would be
added. A **runtime** throw was rejected as the guard — core's map is core-owned while the
supported-type list is module-owned, so a module shipping an aggregate core does not yet project
would fatal every cron cycle rather than fail a build.

**Rejected — (b) deliberately scope the backfill to page/post/category.** It would make media and
tags permanently hook-only: never repaired on drift, never present on a fresh install until an
editor happens to re-save each attachment and term. That contradicts Rule 1 (reconciliation
repairs the delivery side to match WordPress) for two thirds of Phase 1B's shipped aggregates,
and the cost of the alternative is three list entries.

**Explicitly unchanged.** No schema, no migration, no new persistence (DECISION Q holds — progress
stays derived on demand), no contract change, no new PG handle (DECISION L Ruling 0), no `pg_*`
wrapper (DECISION E), no second repair path (repair is still exclusively
`ReplayService::replayEntity` — DECISION T). Reconciliation modes, cadences and page sizes are
untouched. The scan cost of a full reconcile grows by the attachment and tag corpora, which is
the point.

---

### DECISION AD — Hierarchical Page Addressing by Full Ancestor Path (FLAG-PAGESLUG-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Session** | FLAG-PAGESLUG-1 (interstitial, after Phase 1B close) |
| **Authority** | Architect ruling 2026-09-07 answering FLAG-PAGESLUG-1 (raised P1B-S3 closeout; defect present since P1A-S5); project goal that headless sites preserve WordPress permalink structure as closely as reasonably practical; Doc 9 §7 (versioning), §26 (contract lifecycle); DECISION F (delivery contracts); OPEN-11 (lossless projection, no derived columns); Rule 6 (consumers depend on API contracts, not WP internals) |
| **Resolves** | FLAG-PAGESLUG-1 — `/hsp/v1/pages/{slug}` looked up a bare leaf slug while WordPress scopes page slug uniqueness per PARENT, so a specific nested page could not be addressed at all and a bare slug could return the wrong page |
| **Amends** | Nothing frozen. DECISION F's `QueryProviderInterface` is **untouched** — hierarchical lookup is an additive capability contract, not a redefinition. OPEN-11's derived-column exclusion is **upheld, not waived** (ruling 3). Scope is Pages only: posts, taxonomies and media addressing are unchanged |

**Ruling — path-based lookup, resolved at read time, with a deprecated one-segment compatibility arm.**

**(1) The canonical page identity is the FULL ANCESTOR PATH.** The single-page contract becomes
`GET /hsp/v1/pages/{path}`, where `{path}` carries one or more hierarchical slug segments —
`/pages/about`, `/pages/about/team`, `/pages/company/about/team`. A multi-segment request MUST
resolve the exact hierarchy: `/pages/about/team` resolves the page whose ancestry is about → team
and nothing else, so `/pages/wrong-parent/team` is a **404 even when `/about/team` exists**. Two
pages sharing the leaf slug `team` are thereby independently and deterministically addressable,
which is the whole defect. This is a **widening of the same endpoint** — the parameter is renamed
`slug` → `path` in both the route and the descriptor, the ADR-055 route count is unchanged, and no
second page-addressing API is introduced.

**(2) A one-segment request means the top-level page — with a DEPRECATED v1 fallback.** The
long-term semantic of `/pages/team` is "the top-level page whose path is `team`", never "any page
anywhere whose leaf slug happens to be `team`". But the leaf behaviour is currently *supported*,
and Doc 9 §26 prohibits direct removal, so `hsp/v1` resolves in this order: exact path first; a
**multi**-segment miss is a 404 with **no leaf fallback ever**; a **one**-segment miss falls back to
the existing deterministic leaf lookup (`ORDER BY parent_id, id`). A top-level exact match
therefore always wins before the fallback can fire. That fallback is **explicitly deprecated
behaviour**, not the page model; its removal follows Supported → Deprecated → Removed at a formal
contract transition, not in this session. No new transport-level deprecation convention was
invented for it — the project has none, so the deprecation is recorded in the contract, the code
and the tests.

**(3) Read time, not a stored column.** No `path` / `uri` / `permalink` column on `content.pages`,
**no migration**, no path cache, no new persistence — and no synthetic descendant fan-out when a
parent slug changes. A stored path duplicates hierarchical state: renaming a parent changes every
descendant's URI although no descendant was edited, and WordPress emits no event for them, so a
stored path would need descendant-invalidation machinery and would still leave a stale window
(hourly drift reconciliation is timestamp-only and would not even see it — the child's
`post_modified_gmt` never moved — so repair would wait for nightly incremental). The projection
already holds the authoritative relationship — `source_post_id`, `parent_id`, `slug` — so the path
is derived per request and a parent rename is correct the moment the parent's own row updates. This
also keeps OPEN-11's "no precomputed URIs" exclusion intact rather than arguing it.

**(4) One query, cycle- and depth-safe.** A recursive CTE walks **up** from every live page carrying
the requested leaf slug, prepending each ancestor's slug, and the outer filter keeps the branch that
reached the root and reconstructed exactly the requested path. Index-backed at both ends — the
anchor on `idx_content_pages_slug`, each ancestor hop on `uq_content_pages_source_post_id` — with
no N+1 walk and no WordPress read at delivery time (ADR-040).
`PageQueryProvider::MAX_ANCESTOR_DEPTH` is a **defensive bound, not a hierarchy limit**: a parent
cycle can never reach `parent_id = 0`, so bounding the recursion is what makes a corrupt hierarchy
terminate.

**(5) The public-set predicate applies to the REQUESTED page only.** `status = 'publish' AND
deleted_at IS NULL` gates the leaf. **Ancestors are structural path components**, contributing a
slug and nothing else, so a published child under an unpublished or soft-deleted parent stays
addressable — and that parent remains unretrievable through its own address. Applying the public
predicate up the chain would make a page disappear because of its parent's visibility, which is not
how WordPress hierarchy behaves.

**(6) Sanitization is per segment.** `sanitize_title()` strips `/` and would silently collapse
`about/team` into `aboutteam`, so the path is split on `/`, each segment sanitized with the same
slug sanitizer every other route uses, and the segments rejoined. Leading and trailing separators
are trimmed (a WordPress permalink carries them). A malformed path — an empty internal segment, or
a segment that sanitizes away to nothing — is rejected with **HTTP 400** before any lookup.
Traversal is blocked twice over: the route's character class admits no `.`, and `sanitize_title('..')`
is the empty string. The per-segment character policy is otherwise **unchanged**; the broader
Unicode/encoded-slug question is deliberately untouched.

**(7) An additive core capability contract, not a redefinition.**
`core/Contracts/HierarchicalQueryProviderInterface::findByPath(string): ?array` is new and
core-owned; `QueryProviderInterface::findBySlug()` keeps its meaning for posts, categories, tags and
media, which are flat and whose slugs are unique within their own projections. `PageQueryProvider`
implements both; the REST registrar's page slot is typed as the **intersection** of the two, so the
compatibility arm is visible in the signature and narrows to the hierarchical contract alone once
the fallback retires. Core owns the contract, the Content module owns the implementation — Rule 5
unchanged.

**(8) No parent filter.** Neither `?parent=<id>` nor `?parent=<slug>` is added. The first would make
WordPress numeric post IDs the public addressing contract (Rule 6); the second is ambiguous the
moment the parent is itself nested. Path addressing already solves both, recursively.

**Consumer guidance.** A headless front end should address pages by the WordPress-style page path it
already holds: a front-end route of `/about/team` requests `/hsp/v1/pages/about/team`, not
`/pages/team`. This introduces **no general permalink engine** and does not touch post permalink
architecture.

**Known limit, flagged not worked around (FLAG-PAGEPATH-ANCESTOR-1).** An ancestor that has **never
been published** has no `content.pages` row at all — `HookWiring` emits only when one side of a
status transition is in the public set (OPEN-10) — so its slug is unknown to the projection and a
published descendant beneath it cannot have its path reconstructed. Ruling 5 is implemented exactly
as written and holds for every ancestor that has ever been published (rows are soft-deleted, never
removed); the never-published case is a projection-coverage question, not a lookup one, and is
raised as its own flag rather than resolved here.

**Explicitly unchanged.** No schema, no migration, no new persistence, no new PG handle (DECISION L
Ruling 0), no `pg_*` wrapper (DECISION E), no WordPress read on the delivery path (ADR-040), no
change to posts / taxonomies / media addressing, no change to the listing endpoints or cursor
pagination. One behaviour outside the ruling's letter did change and is recorded here:
`PlaygroundRequestExecutor` encoded the whole path-parameter value with `rawurlencode()`, which
would have turned `about/team` into `about%2Fteam` and 404'd in the Operations Console playground;
encoding is now applied per segment, which is byte-identical for every single-slug route.

---

### DECISION AE — Unprojected Page Ancestors Are a Documented Limit, Not a Defect (FLAG-PAGEPATH-ANCESTOR-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Session** | FLAG-PAGEPATH-ANCESTOR-1 (interstitial, immediately after DECISION AD) |
| **Authority** | Scope-owner directive 2026-09-07 to resolve FLAG-PAGEPATH-ANCESTOR-1; **empirical verification against live WordPress** (see Evidence); DECISION AD ruling 5 (ancestors are structural); OPEN-10 (public set / capture model); ADR-040 (no WordPress read on the consumer path) |
| **Resolves** | FLAG-PAGEPATH-ANCESTOR-1 — a published page whose ancestor has no `content.pages` row cannot have its path reconstructed |
| **Amends** | Nothing. OPEN-10 is **upheld** — this ruling exists precisely to avoid changing it. DECISION AD is unchanged; ruling 5 is confirmed correct as implemented |

**Ruling — option (a), accept and document. (b) is deferred to a future OPEN-10 ruling; (c) was
never viable.** The flag as raised over-stated the problem, and the correction is the substance of
this decision.

**Evidence — what WordPress actually does.** The flag assumed a published child under a
never-published parent has a real address (`/about/team`) that HSP fails to serve. Verified against
live WordPress, that is true in only one narrow sub-case:

| Sub-case | WordPress | HSP |
|---|---|---|
| Draft parent, **no explicit slug** (the normal case) | `post_name` is **empty** — `wp_insert_post()` skips slug generation for `draft`/`pending`/`auto-draft`. `get_page_uri()` on the child returns the **leaf alone** (`team`, not `about/team`), and `get_page_by_path()` resolves the child under **neither** address: WordPress advertises a permalink it cannot route. | Also unaddressable by path. **Parity — there is no address to miss.** |
| Draft parent **with an explicitly-set slug** | `post_name` is set, `get_page_uri()` = `services/crew`, and `get_page_by_path('services/crew')` **resolves**. WordPress serves the child. | Unaddressable — the parent has no projection row, so its slug is unknown. **Real divergence.** |
| Parent published, then unpublished / trashed | Keeps the `post_name` assigned at publish; resolves. | Row is **soft-deleted, never removed**, so the slug survives and the path resolves. **Parity** (asserted). |

The defect surface is therefore one row of that table, not the whole flag: a parent that has **never
been published yet carries a slug** — reachable by editing the permalink field on a draft, or by an
import that sets `post_name` directly.

**(1) The limit is accepted and documented.** `findByPath()` resolves only through ancestors the
projection knows about. In the normal draft case that matches WordPress exactly; in the
slugged-draft case HSP serves less than WordPress does, and that is recorded rather than fixed.

**(2) The behaviour is PINNED, not merely described.**
`PagePathAddressingIntegrationTest::test_a_page_under_an_unprojected_ancestor_is_not_addressable_by_path()`
asserts today's outcome, so a future OPEN-10 change cannot alter it silently — the test has to be
updated deliberately, with the ruling in hand.

**(3) The fallback is currently MORE permissive than WordPress, and retiring it fixes that.** The
deprecated one-segment leaf lookup (DECISION AD ruling 2) matches on slug alone, so it *does* return
a child whose ancestor is unprojected — a page WordPress itself 404s. Removing the fallback at the
Doc 9 §26 lifecycle transition therefore brings this case to exact parity. Noted here so that
retirement is understood as a fix, not a regression.

**Why (b) — projecting structural rows for unpublished ancestors — was NOT taken.** It requires
changing what the pipeline captures, which is **OPEN-10**, frozen. It would put content that has
never been public into the delivery database: today the projection holds non-public rows only for
content that was public at least once (soft-deleted tombstones), and widening that turns any future
query-predicate slip into a leak of unpublished material — a bug class this codebase has already hit
three times (the `?category=` slug collision, the console metrics count, the backfill projected
count). The cost is a capture-model change, new hook coverage for drafts, adapter and checksum work,
and reconciliation/orphan-sweep handling, weighed against a state an ordinary publishing workflow
does not produce. **If WordPress fidelity in that sub-case is later judged to be worth it, it is an
OPEN-10 ruling and must be taken as one** — this decision does not pre-empt it.

**(c) — resolving the missing segment from WordPress at request time — is prohibited** by ADR-040
(no WordPress read on the consumer path) and was never a candidate.

**Explicitly unchanged.** No schema, no migration, no new persistence, no capture-model change, no
contract change, no code change to `PageQueryProvider` or the REST boundary. One integration test
was added; nothing else moved.

---

### DECISION AF — The One-Segment Leaf Fallback Reaches Removed (completes DECISION AD ruling 2)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Session** | Page-addressing lifecycle session (interstitial, after DECISION AE) |
| **Authority** | Scope-owner directive 2026-09-07 to retire the fallback; **DECISION AD ruling 2**, which states removal "belongs in the next appropriate API contract/version transition **or explicit lifecycle session**" — this is that session; Doc 9 §26 (Supported → Deprecated → Removed) |
| **Resolves** | The last open item of DECISION AD, and the parity gap recorded in DECISION AE |
| **Amends** | **DECISION AD ruling 2** — the compatibility arm it defined is now Removed. **Corrects DECISION AD ruling 7** on one point of fact (see (3)). DECISION AE's finding is unchanged; its predicted consequence has landed |

**Ruling — the deprecated fallback is removed. `/hsp/v1/pages/{path}` is now exact-path lookup and
nothing else.**

**(1) What changed.** `ContentRestRegistrar::handlePageSingle()` no longer consults the leaf lookup
after a one-segment miss. Every miss is a 404, at any depth. `/pages/team` resolves the top-level
page named `team` or nothing; it can no longer return a nested namesake.

**(2) The mitigation SQL is deleted, not merely orphaned.**
`PageQueryProvider::findBySlug()` previously carried the FLAG-PAGESLUG-1 mitigation — a bare
`WHERE slug = $1` with `ORDER BY parent_id, id` to make the ambiguity at least deterministic. Simply
ceasing to call it would have left a method that hands an arbitrary nested page to any future
caller: a loaded footgun, and the mitigation only ever existed because the canonical lookup did not.
The method **cannot** be deleted — `QueryProviderInterface` requires it and pages need that
interface for the listing endpoint's `list()` — so it now **delegates to `findByPath($slug)`.` That
gives a bare page slug the single meaning DECISION AD ruling 2 already defines for a one-segment
address (**the top-level page of that name**), and makes it impossible for the two to drift apart.

**(3) Correction to DECISION AD ruling 7.** That ruling anticipated the REST registrar's page slot
narrowing to `HierarchicalQueryProviderInterface` alone once the fallback retired. **That was
imprecise and is not what happened:** the intersection type stays, because the `/pages` LISTING
still calls `QueryProviderInterface::list()`. What ended was the *use of* `findBySlug()` by the page
single-route handler, not the need for the interface. Recorded rather than quietly diverged from.

**(4) WordPress parity in the FLAG-PAGEPATH-ANCESTOR-1 case is now reached, as DECISION AE
predicted.** While the fallback existed it matched on slug alone, so it returned a published child
whose ancestor is unprojected — a page WordPress itself 404s, making HSP briefly *more* permissive
than WordPress. With the fallback gone there is no bare-slug back door, and the integration test
that pinned the old behaviour now pins parity.

**On the lifecycle, stated plainly.** Deprecated (DECISION AD) and Removed (here) fall on the same
calendar day, which is a short window by any reading of Doc 9 §26. It is nonetheless a legitimate
completion of the lifecycle rather than the "direct breaking cut-over" ruling 2 prohibited: the
platform is at **version 0.1.0 and unreleased**, so `hsp/v1` has no external consumers to strand,
and DECISION AD explicitly reserved removal for an "explicit lifecycle session", which the scope
owner has now called. Had there been shipped consumers, this would have required a genuine
migration window or an `hsp/v2` transition (Doc 9 §7) — and that remains the rule for any future
removal, which must not cite this decision as precedent for a same-day retirement on a released
contract.

**Explicitly unchanged.** No schema, no migration, no new persistence, no capture-model change, no
contract addition or removal (`QueryProviderInterface` and `HierarchicalQueryProviderInterface` are
both untouched), no route change, no endpoint-descriptor change — `/pages/{path}` and its published
parameter description already stated the top-level semantic — and no change to posts, taxonomies or
media addressing. The ADR-055 drift guard stays at 11 routes.

---

### DECISION AG — Phase 2 Ratification: Multi-Module Platform + WooCommerce Catalog Model

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Session** | P2-S0 (Phase 2 ratification — docs only) |
| **Authority** | Architect rulings 2026-09-07 answering the eleven blocking flags raised in P2-S0 ratification review, plus three proactive rulings (AG-12/AG-13/AG-14) and four standing requirements raised in the same review; Doc 11 §11 (Phase 2 — WooCommerce Catalog); Doc 3 §13–18 (frozen commerce schema); CLAUDE.md Rules 1/2/3/4/5/6/7; ADR-054 (bounded WP-Cron cycle, Principle 8 zero configuration); ADR-013 (foreign-key strategy); DECISION 1/2/3 (capture, counter, write-suppress + one-transaction atomicity); DECISION I (tombstones); DECISION T/U (re-emission is the only repair path); DECISION E / L Ruling 0 (no new `pg_*` wrapper, four-handle topology); DECISION K (delivery connection); DECISION Q (derived metrics, zero new persistence); DECISION AA (shared taxonomy projection per owning domain); DECISION AD/AE/AF (hierarchical addressing by full ancestor path); DECISION AB (sync-latency SLA); DECISION AC (aggregate coverage); ADR-012 (constructor injection); ADR-055 (registry-generated OpenAPI); OPEN-1 (event naming); OPEN-3/4/5/7 (column-type canon) |
| **Resolves** | Eleven blocking flags **raised and ruled inside this session** — FLAG-MODWIRE-1, FLAG-EMITTER-1, FLAG-PROJMAP-1, FLAG-QPART-1, FLAG-FILTERSET-1, FLAG-MODVER-1 (core is structurally single-module) and FLAG-COMMFK-1, FLAG-COMMSTOCK-1, FLAG-COMMTAX-1, FLAG-COMMMEDIA-1, FLAG-COMMCANON-1 (Doc 3's frozen commerce schema contradicts later rulings). **AG-12, AG-13 and AG-14 are proactive architect rulings from the same review — they are NOT flag resolutions and must not be recorded as such.** |
| **Amends** | **Doc 3 §13–18** (commerce DDL — banner applied, original text retained). **ADR-013 as stated in Doc 3 §18** is superseded for asynchronously projected cross-aggregate Commerce references (AG-7). **Doc 11 §11** gains the module-lifecycle and product-type scope it never defined (AG-12/AG-13). **DECISION AA is extended, not amended** — its per-owning-domain taxonomy model now applies to Commerce with one stated distinction (AG-9). `core/Contracts/FilterSet.php` is superseded as the delivery filter contract (AG-5). No other frozen ruling is weakened; DECISION 1/2/3, I, T, U, E, K, L Ruling 0, Q, AB, AC, AD and ADR-054 are all preserved verbatim. |

**Ruling — Phase 2 is the proof that HSP is genuinely multi-module, not merely capable of running the Content module.**

The success test for Phase 2 is explicitly **not** "WooCommerce products synchronize." It is: *WooCommerce becomes the second independent HSP domain module without special-casing Commerce in Core, without weakening Content, without adding a second repair path, without violating the WP-Cron bounded-cycle model, and without requiring architectural redesign for the third module.* Implementation shortcuts already in the tree are **not** preserved merely to avoid rework.

---

#### Part 1 — Multi-module infrastructure (built in P2-S1, consumed in P2-S2)

**AG-1 · Core must not know concrete modules** *(resolves FLAG-MODWIRE-1)*
Core must not import or hardcode `ContentModule`, `CommerceModule`, or any future module class. Today `core/Container/ContainerBuilder.php` imports `HSP\Modules\Content\ContentServiceProvider` and registers it directly — Rule 5 in reverse — while `ModuleInterface::getServiceProvider()` exists but returns an anonymous no-op that nothing in core calls. That designed seam becomes real: each module supplies its **own** real ServiceProvider, core registers discovered modules **generically** through the existing Module Registry boundary, and adding Commerce requires **no** new line in `ContainerBuilder`. Core depends only on core contracts; modules depend only on core contracts. **No reflection-based filesystem scanning** and **no hidden service resolution** are introduced — the existing explicit discovery/registration machinery is reused. Duplicate module names fail loudly. The module-isolation guard (`tools/smoke_e2e.php`) currently walks only `modules/` and therefore cannot see a core→module import; it must scan **both** directions.

**AG-2 · Replay and reconciliation become multi-module registries** *(resolves FLAG-EMITTER-1)*
A single `ReplayEmitterInterface` binding is no longer sufficient, and a **scalar** `WpReconciliationSourceInterface` is worse. `Container::singleton()` is last-writer-wins with no error, so a second module binding the same key would **silently delete** the first module's emitter. Core-owned registries replace both — conceptually `ReplayEmitterRegistryInterface` and `ReconciliationSourceRegistryInterface` — with **explicit registration keyed by aggregate type**:

```
content.post / content.page / content.media  → Content emitter
commerce.product / commerce.variation / commerce.inventory → Commerce emitter
```

Modules register implementations; **Core** constructs `ReplayService` and `ReconciliationService` (today a module constructs them). A module must not construct a Core service. Duplicate aggregate-type registration **throws immediately**. Last-writer-wins container replacement is prohibited. Missing aggregate coverage must **never** be silently `continue`d past — a registered source aggregate with no emitter/projection capability surfaces an explicit failure or diagnostic rather than a run that claims success. Backfill enumerates **all active registered** reconciliation sources, not one scalar Content source. WooCommerce absence stays a normal state: Commerce registers no source or emitter, and Content-only replay, reconciliation and backfill continue unchanged. **No Commerce dependency may be required for Content operation.**

**AG-3 · Projection metadata is module-registered** *(resolves FLAG-PROJMAP-1)*
The hardcoded `content.*` projection maps in `ReconciliationService::PROJECTION`, `BackfillReader::PROJECTION` and `BackfillProgress::TYPES` must not grow into `content.* + commerce.* + membership.* + booking.* …` — **Core must not know every domain table in the platform.** A Core-owned `ProjectionRegistryInterface` / `ProjectionDescriptor` replaces them, with modules registering descriptors keyed by aggregate type. A descriptor carries only the infrastructure metadata Core genuinely requires — aggregate type, schema/table identifier, source identity column, and the projection identity/read metadata reconciliation and backfill need — and is **not** a domain model. Schema/table/column identifiers are registered by **trusted module code, never accepted from API or user input**, and are validated against strict identifier rules at registration; this is load-bearing because `BackfillReader` interpolates the table name directly into SQL, which is safe today only because the values are fixed core literals. Duplicate aggregate descriptors fail loudly. Unknown aggregate types are never silently skipped. **No generic arbitrary-SQL escape hatch. No fifth PG handle.** Runtime metadata only — **zero new database persistence**.

**AG-4 · Queue partition routing** *(resolves FLAG-QPART-1)*
Commerce uses the **existing** `commerce` partition, already declared in `config/queue.php`, `DatabaseQueueProvider::VALID_PARTITIONS` and the maintenance sweep — so **no DDL change**. The `'content'` literals in `EventDispatcher`, `EventWorkerStrategy` and `DispatcherWorkerStrategy::consumesPartitions()` are replaced by **one explicit Core-owned domain→partition routing seam** (`content.* → content`, `commerce.* → commerce`, `system.* → system`), driven by the domain prefix the OPEN-1 event convention already guarantees and `EventRegistry` already validates. String parsing must **not** be scattered through the dispatcher and worker. This is the routing ADR that DECISION L (v1.12) explicitly deferred *"to a future ADR when a second domain is introduced"*. No new worker type, no new processing stage, no additional PG connection, no new raw `pg_*` wrapper; ADR-054's bounded WP-Cron cycle remains the execution model.

> **Performance clause (binding).** `processing.projection_batch_size` remains the **TOTAL** projection-stage budget for one cycle. It is **not** 200 Content + 200 Commerce = 400 unless configuration explicitly sets the total to 400. Active partitions **share** the configured budget, allocated by a **simple deterministic fair/round-robin** scheme, fair enough that one busy domain cannot permanently starve another. **No weighted scheduling and no priority queues in Phase 2 without a new ruling.** Mixed Content + Commerce backlog under one bounded cycle is a P2-S1 and P2-S7 validation item.

**AG-5 · The filter contract becomes domain-neutral** *(resolves FLAG-FILTERSET-1)*
`core/Contracts/FilterSet.php` is `final` while its own docblock says *"Modules extend or wrap this as needed"*, and four of its seven fields (`categorySlug`, `tagSlug`, `publishedAfter`, `status`) are content-domain. Core instead owns a small domain-neutral contract — conceptually `QueryFilterInterface` — and modules own strongly typed filter DTOs (`ContentFilterSet`; `ProductFilterSet` with price range, stock status, SKU, product category, attribute filters). `QueryProviderInterface` accepts the Core-owned contract rather than a content-shaped concrete class. **Not** an untyped `array<string,mixed>` bag: strong typing and explicit validation remain, and each domain's query provider must **explicitly reject** an incompatible domain filter rather than silently reading the wrong DTO. Current Content filtering behaviour stays functionally compatible through the refactor. **No `QueryFilterRegistry` is to be invented** — AG-5 does not require one; the proof is the typed DTOs and the explicit rejection, not a registry.

**AG-6 · `system.module_versions` gets a writer** *(resolves FLAG-MODVER-1)*
`system.schema_versions` remains the **authoritative** migration-state record. `system.module_versions` — created and read by `OperationsQueryReader`, required by `MigrationInterface`, but written by nothing — is module version/history metadata and is finally written: **idempotently, and only after** a module's migration batch successfully reaches its declared schema version. Historical module-version records are never deleted during rollback. Plugin version, code version and schema version are **not** automatically the same thing; `system.module_versions.schema_version` means an explicitly declared **module schema version** (`module.json` already declares `schema_version`, so this wires a writer rather than adding the field). `system.module_versions` must **not** replace `system.schema_versions` as the source used to determine whether migrations are applied — onboarding migration readiness continues to read active migration state, and Operations may display module version history without inferring migration health from the latest row.

---

#### Part 2 — Commerce domain model

**AG-7 · Soft references between independently synchronized aggregates** *(resolves FLAG-COMMFK-1)*
Doc 3 §18's mandatory cross-projection foreign keys are **superseded** for asynchronously projected Commerce aggregates. No blocking relational FK for `variation → product`, `inventory → product`, or `attribute term → independently processed attribute` when parent and child are produced by **separate events**. HSP guarantees eventual correctness under at-least-once delivery, non-FIFO processing, replay, reconciliation and overlapping cron cycles, so a child may legitimately arrive before its parent — **the database must not turn valid out-of-order synchronization into a DLQ failure.** Use typed identifiers, indexes, application/reconciliation integrity and query-side joins instead. Real database constraints remain appropriate for primary keys, uniqueness, check constraints, and structures whose rows are created and owned **atomically in the same adapter transaction**. No Phase 2 cross-aggregate FK may be introduced merely because the old Doc 3 diagram showed one. This is the same resolution `content.posts.featured_media_id → content.media` already ships (P1B-S2, ADR-013 soft reference, no FK).

**AG-8 · Inventory owns stock state** *(resolves FLAG-COMMSTOCK-1)*
`commerce.inventory` is the single delivery projection responsible for inventory facts. The same mutable stock fact must **not** be persisted independently in both `commerce.products` and `commerce.inventory` — Doc 3 places `stock_status` on both, which would give one WordPress fact two projections, two checksums and two independent write-suppress decisions that can disagree, with no rule for which wins. The duplicated `stock_status` is **removed from the product projection design**. Product listing, filter and resource queries **JOIN** the inventory projection at read time when stock information is required; stock is **not** copied back into `commerce.products` merely to avoid a join. One source fact, one authoritative delivery owner — with WordPress/WooCommerce authoritative above both. Exact source fields are verified against the installed WooCommerce version before implementation.

**AG-9 · Commerce taxonomy model** *(resolves FLAG-COMMTAX-1)*
DECISION AA applies to Commerce, with one important distinction. Commerce gets **one shared taxonomy term model** — `commerce.taxonomies` + `commerce.entity_taxonomies` — with terms told apart by `taxonomy_type` (`product_cat`, `pa_color`, `pa_size`, future `pa_*`), never a table per taxonomy and never a platform-wide taxonomy table. Doc 3's `commerce.categories`, `commerce.attribute_terms` and `commerce.product_categories` are therefore **superseded where they merely represent taxonomy terms and relationships**.

**However — WooCommerce global attribute DEFINITIONS are not taxonomy terms.** They carry domain semantics (name, slug, type, ordering behaviour, public/archive behaviour), so **`commerce.attributes` remains a legitimate separate Commerce domain projection**:

```
commerce.attributes  ──defines──▶  pa_color / pa_size  ──▶  commerce.taxonomies  ──▶  commerce.entity_taxonomies
```

Do **not** create a second `commerce.attribute_terms` table for rows already represented as `pa_*` terms in `commerce.taxonomies`. Do **not** implement `product_tag` merely because the architecture supports it — Doc 11 §11 lists Categories, not tags, so it is out of Phase 2 scope. Do **not** silently treat local/custom (non-global) product attributes as global `pa_*` taxonomies; they are out of Phase 2 scope rather than modelled ad hoc during this phase.

**AG-10 · Product media ownership** *(resolves FLAG-COMMMEDIA-1)*
`content.media` remains the single existing projection of WordPress attachment state — **no duplicate Commerce attachment projection.** Equally, no hardcoded Commerce PHP dependency on the Content module, and no Commerce query-provider SQL depending on a sibling module's table as an undocumented coupling. **Modules communicate through Events or Core Contracts** (Rule 5). Commerce may store the source attachment **references** its product state requires. If the approved Phase 2 product contract needs expanded media details, introduce a narrow Core-owned capability — conceptually `MediaReferenceProviderInterface`, implemented by Content, depended on by Commerce — supporting **bulk** resolution; one lookup per product or per gallery image is prohibited (N+1). If the contract needs references only, store them and **defer expansion** rather than building the composition capability early.

**Independence clause.** Commerce product synchronization must remain functional when the Content media capability is **unavailable** — Commerce must not require `ContentModule` to be active:

```
media provider available    → expand media references in the delivery representation where the contract requires it
media provider unavailable  → product synchronization still succeeds; stable references retained;
                              no fatal container dependency; no duplicate Commerce attachment projection
```

Expanded media composition is **optional capability composition**, and its design must support capability absence. This is what keeps the platform correct as future modules are enabled and disabled in different combinations.

**AG-11 · The column canon applies platform-wide; semantics do not clone** *(resolves FLAG-COMMCANON-1)*
The PostgreSQL column-type canon applies to `commerce.*`: `TIMESTAMPTZ` rather than bare `TIMESTAMP`, the frozen checksum type, and platform UUID identity conventions. Adapter-managed aggregate projections participating in DECISION 3 write suppression persist the appropriate projection/canonical checksum; tombstone-capable projections follow DECISION I deletion semantics.

**Correction to the flag's own wording:** it does **not** follow that every Commerce table must contain `meta_jsonb`, `synced_at`, `deleted_at` and `checksum` merely because another table has them. Those are **semantic** columns, not universal decoration. A pure relationship/join table does not need its own checksum for symmetry; a table that is never independently tombstoned does not automatically need `deleted_at`; `meta_jsonb` is not mandatory unless the canonical/delivery contract actually carries extensible metadata; sync timestamps must follow an existing architecture requirement or a real operational/query need. Doc 3's commerce DDL is amended by applying the **global type canon plus per-projection semantic requirements** — never by mechanically cloning Content's table shape.

---

#### Part 3 — Proactive rulings (not flag resolutions)

**AG-12 · Module availability, runtime readiness, and initial module bootstrap**

Phase 2's fresh-install case (Content + Commerce both active during onboarding) was already handled. The case that actually matters was not:

```
HSP already installed → global onboarding complete → Phase 2 update lands
→ Commerce becomes available → the WooCommerce catalog already contains products
```

Without an explicit lifecycle only **future** product edits would synchronize, and the existing catalog would remain absent until periodic reconciliation happened to reach it — a silent violation of the zero-configuration product goal (ADR-054 Principle 8).

**(a) Three lifecycle conditions, not two.** Availability is **not** runtime readiness; conflating them leaves a window in which a module counts as active while its schema may not exist yet.

```
DISCOVERED      module code is known to HSP
AVAILABLE       external/runtime requirements satisfied (Commerce code present AND WooCommerce available)
READY / ACTIVE  required module migrations successfully applied; runtime registrations may safely participate
```

Data bootstrap (`pending` / `complete`) is tracked **separately** from all three. The lifecycle is:

```
discovered → availability check → available → ensure required module migrations → migration success
→ runtime-active/ready → bootstrap pending → module-scoped reconciliation/backfill → convergence → bootstrap complete
```

**A module is never runtime-ready merely because `isAvailable()` returns true.** If migrations fail: the module remains **not** runtime-active; Content and every other module remain operational; the failure surfaces through existing migration/operations diagnostics; and no Commerce endpoint or projection consumer may run against missing schema.

An unavailable or not-ready module contributes **nothing** — no service-provider boot behaviour, event capture, platform-gating migrations, replay emitters, reconciliation sources, projection descriptors, queue routing requirements, API endpoints, backfill expected counts, or module readiness requirements. Use an explicit Core contract — preferred shape `ModuleInterface::isAvailable(): bool`, or an equivalently explicit Core-owned availability contract if `ModuleInterface` compatibility makes that cleaner. Availability is **never inferred from missing service bindings** and never hidden inside a module provider as an undocumented no-op.

**(b) Automatic activation transition — no reactivation.** This must work without reinstalling or reactivating HSP:

```
HSP installed → WooCommerce absent → Commerce unavailable
   … later …
WooCommerce installed/activated → next normal HSP lifecycle evaluation → Commerce available
→ migrations applied through the EXISTING Migration Engine → runtime-ready
→ automatic bootstrap scheduled → historical catalog converges
```

P2-S1 must identify and use an **explicit Core-owned module lifecycle coordinator/seam** for this transition. It must **not** be hidden inside `CommerceServiceProvider`, and must **not** require deactivating HSP, reactivating HSP, manually running migrations, or manually running reconcile. If the current Migration Engine can only execute module migrations during plugin activation, that is an implementation gap **P2-S1 is authorised to correct through the existing Migration Engine**. **No second migration system.**

**(c) Module bootstrap state.** The global `hsp_onboarding_state` is **not sufficient** — an installation may already be globally onboarded when a new domain module becomes active. One small WordPress option is authorised, conceptually `hsp_module_bootstrap_state`, module-keyed, minimum states `pending` / `complete`. This is **lifecycle state**, not metrics persistence and not a PostgreSQL schema addition. `system.module_versions` must **not** be used as data-bootstrap state — it remains schema/module-version metadata per AG-6.

**Lost-update safety is the contract, not the serialization shape.** Updating one module's state must never erase a sibling's: with `content = complete` and `commerce = pending`, writing Commerce must preserve Content. If safe semantics cannot be provided with the available implementation, **one option per module is authorised**. The architectural requirement is *persistent module-scoped lifecycle state*; **no PostgreSQL table is authorised for it**. Tests must prove a sibling module's bootstrap state cannot be overwritten accidentally.

**(d) Newly-active module flow.** When an active, ready module has no completed bootstrap state, a module-scoped full reconciliation/backfill is scheduled and runs through `ReconciliationService` re-emission — **no direct WordPress→PostgreSQL copy, no second repair path, no in-request queue drain, no reset of global onboarding.** Content's API and Operations surfaces stay online while Commerce bootstraps. WP-Cron remains the execution mechanism. DECISION T/U re-emission and the AG-2/AG-3 registries are reused. On a **fresh** install where global onboarding already covers all active modules, global convergence marks those modules' bootstrap state complete — **no duplicate Commerce backfill afterward**.

**AG-13 · Phase 2 product-type scope**

Doc 11 §11 says "Products" and "Product Variations" but never defines WooCommerce product-type coverage; implementation must not decide this silently. **Phase 2 supports the core types `simple` and `variable`** — variable-parent state begins in P2-S2, variation aggregates land in P2-S5. Phase 2 explicitly does **not** implement type-specific behaviour for `grouped`, `external`/affiliate, or custom third-party product types. Unsupported types are **not coerced into `simple`**, and no incomplete representation is presented as a fully supported product; they are handled deterministically and observably through the platform's **existing** unsupported-source validation pattern — reuse it, do **not** invent a new error architecture for product types. `virtual` and `downloadable` are product **characteristics**, not reasons to create separate projection tables; expose their fields only if the approved Phase 2 Product contract includes them.

**An unsupported product type is normal source scope, not a processing failure.** It must not retry repeatedly, must not enter the DLQ merely because its type is unsupported, must not block reconciliation, must not block module bootstrap convergence, and must not count as an expected Phase 2 projected Product. **Backfill and reconciliation expected counts include only product types the active Commerce contract supports**, and bootstrap must still converge on a catalog containing grouped/external/custom products.

**Type transitions are mandatory coverage**, because WooCommerce product type changes over time:

| Transition | Required result |
|---|---|
| unsupported → simple/variable | Entity enters supported scope; normal current-state synchronization projects it |
| simple/variable → unsupported | Any previously public Commerce Product projection is **tombstoned** through the existing DECISION I / T/U path — it must not remain visible forever merely because its new type is unsupported |
| unsupported → unsupported | Successful no-op from the Phase 2 projection perspective; no DLQ, no projection requirement |
| simple ↔ variable | Normal current-state synchronization; the final projection reflects the new supported type; related variation and inventory state converge per their own sessions |

Existing event, re-emission and tombstone mechanisms only — **no product-type-specific repair path.** P2-S2 and P2-S7 explicitly test: an unsupported existing product during initial bootstrap; supported→unsupported; unsupported→supported; simple→variable; variable→simple. P2-S7 must state accurately that **Phase 2 supports simple + variable products** and must **not** claim complete WooCommerce product-type parity; a future phase can add grouped/external/custom types inside the Commerce module with **no Core change**.

**AG-14 · Inventory ownership is aggregate-aware**

AG-8 makes `commerce.inventory` the single owner of stock facts, but Phase 2 also supports variable products and Product Variations, so the Inventory projection must **not** be structurally limited to a parent Product:

```
commerce.inventory
    └── inventory owner ── product
                        └── product variation
```

Carry an explicit owner identity — meaning equivalent to `owner_type` + `owner_id`, exact column names following the session's migration conventions — so an Inventory row can identify which supported Commerce aggregate owns that stock state. Do **not** create `commerce.product_inventory` and `commerce.variation_inventory` as separate tables. Do **not** duplicate variation stock onto the Product row.

**Source semantics still govern.** Before the P2-S6 migration is written, verify through the installed WooCommerce public CRUD API: how simple-product stock ownership is represented; how variable-parent stock ownership is represented; how variation-managed stock is represented; how a variation indicates that stock is effectively managed or inherited at the parent level; and which hooks/actions represent inventory changes for each case. The projection follows the authoritative WooCommerce semantics — a variation that manages its own stock is an inventory owner, while a variation relying on parent-managed stock must **not** get a duplicate inventory fact invented for symmetry. One source fact, one authoritative delivery owner. If the installed WooCommerce version exposes materially different semantics that cannot fit this owner model, **STOP-and-flag before P2-S6 migration work**.

**Read model (binding for Product and Variation alike).** A product or variation whose inventory row is not yet projected stays readable with inventory state **unavailable/unknown**. A missing inventory row must **never** mean **out of stock**, and must never cause a valid row to disappear from an otherwise correct listing because an INNER JOIN was used by accident — use a LEFT JOIN or equivalent tolerant composition. For an explicit `in_stock=true` filter, a product with unknown or missing inventory state must **not** be falsely classified as in stock. Reconciliation and backfill converge the two projections.

---

#### Part 4 — Standing requirements

**Requirement A · Permalink / public-URL alignment (a Phase 2 verification target, not an optional note).**
Headless sites should reproduce WordPress/WooCommerce public permalink structure as closely as practical rather than inventing a separate frontend URL model. `/hsp/v1/products/{slug}` is an **API resource route** and is **not** automatically the canonical WooCommerce frontend permalink.

Prohibited: forcing a stored derived `permalink` column onto Product; hardcoding `/product/{slug}` or `/product-category/{slug}` (WooCommerce permalink bases are configurable and product paths may depend on category configuration); querying WordPress/WooCommerce at delivery-request time (ADR-040).

Required: **P2-S2 preflight (products)** and **P2-S3 preflight (categories)** verify the installed WooCommerce version's public permalink configuration and the APIs/settings from which WooCommerce derives the product permalink base, the product-category permalink base, product-category hierarchy behaviour, and any category-in-product-permalink behaviour for the installed configuration. **Do not assume WooCommerce defaults equal the site's configured settings.** The result is then classified:

- **Case A — existing projected state is sufficient.** Document exactly how a frontend reconstructs the same public path. No new persistence.
- **Case B — a small store-level configuration projection is required.** **STOP-and-flag before inventing it**, returning the exact settings required, source APIs, invalidation hooks, proposed projection ownership, confirmation that it is store-level rather than per-product, and the proposed delivery contract. Permalink configuration is never stored redundantly on every product.
- **Case C — Phase 2 intentionally ships catalog data without public permalink reconstruction.** Allowed **only** if STATUS and the P2-S7 close explicitly record permalink parity as an unresolved follow-up.

Phase 2 must not claim full WooCommerce frontend contract parity while a consumer cannot reproduce the configured URL structure. **P2-S7 reports two items independently — Product permalink compatibility and Product Category permalink compatibility — each PROVEN or EXPLICITLY DEFERRED WITH FLAG.** Neither may silently disappear from the roadmap.

**Requirement B · WooCommerce catalog visibility is not `post_status`.**
`status = publish` alone does not mean "visible in the product catalog" — WooCommerce carries catalog/search visibility semantics beyond WordPress post status. Public product **list** endpoints must not expose products WooCommerce excludes from the catalog; a published product WooCommerce intentionally hides from listings stays absent from listings. Single-product addressing follows WooCommerce's direct-access behaviour rather than blindly copying listing visibility rules. **Verify the exact visibility source, getters and taxonomy representation against the installed WooCommerce version before coding this behaviour — do not guess hook names or internal storage.** Belongs to P2-S2, validated in P2-S7.

**Requirement C · Money precision.**
No monetary value may be persisted using binary floating-point types. Doc 3 already specifies `NUMERIC` for `price` and `sale_price` on both products and variations, and there is no `FLOAT`/`REAL`/`DOUBLE` in the commerce DDL, so the prohibition is already satisfied. **Keep PostgreSQL monetary storage as `NUMERIC` without imposing a fixed `(precision, scale)` in Phase 2** — do not arbitrarily choose `NUMERIC(10,2)`, `(12,2)` or `(19,4)`, because WooCommerce/store currency decimal configuration is not guaranteed to be universally two decimals and HSP should not narrow the source value unnecessarily. At the application boundary:

```
WooCommerce monetary source value → normalized exact decimal STRING → canonical model → PostgreSQL NUMERIC
```

PHP binary floating point must **not** be the canonical representation used for checksum or persistence. Canonical/checksum normalization must produce a **deterministic** decimal representation, so `10`, `10.0` and `10.00` normalize to one documented canonical form **before** checksum construction — otherwise semantically identical monetary values generate checksum churn from formatting alone, which is the DECISION 3 trap Phase 1B hit three times. Display decimal places are presentation/store-configuration semantics and are **not** derived from the stored numeric scale.

**Requirement C′ · Currency ownership.**
Doc 3's per-product `currency` column is architecturally incorrect for the standard single-store WooCommerce currency model unless source verification demonstrates genuine per-product currency semantics. **Currency is store-level Commerce configuration** and is not persisted redundantly on every `commerce.products` / `commerce.product_variations` row. For Phase 2: verify the installed WooCommerce public source API for store currency; if the Product API contract does not require currency yet, **supersede the per-product column and do not build a settings projection prematurely**; if it does, raise/store it through a Commerce-owned **store-level** configuration capability/projection rather than duplicating it across every product, under normal HSP synchronization and invalidation rules. **Never read WooCommerce configuration at delivery-request time.** Any store-level configuration projection must be separately justified by the actual delivery contract.

---

#### Part 5 — Cross-cutting build requirements

**Every aggregate proves its full lifecycle.** For every independently synchronized Phase 2 aggregate, the build session's DoD proves the applicable lifecycle — **create → update → delete / leave-supported-scope → tombstone → replay → reconciliation** — across Product, Product Category / taxonomy term, Global Attribute Definition, Attribute Term, Product Variation and Inventory. **A source entity leaving the public or supported projection scope must never leave an indefinitely visible stale projection.** Use DECISION I / T / U; **no direct cleanup SQL repair path**. Relationship projections converge as part of the same lifecycle: a product removed from a category loses that relationship after normal projection; an attribute term removed from a product likewise; a deleted variation stops being publicly active and leaves no stale relationship; a deleted or non-public product tombstones. Destructive deletion is not required where the architecture already uses soft tombstones. P2-S7 includes **at least one deletion / out-of-scope transition from each major Commerce vertical**.

**Relationships are tested through real handlers.** P2-S3/S4/S5 verify convergence for **Product ↔ Product Category**, **Product ↔ Global Attribute Term**, and **Variation ↔ selected attribute value/term** where that relation is part of the approved source model — produced by the real handlers, not hand-seeded fixture rows. Changing a relationship in WooCommerce must eventually produce the correct relationship projection, and replay/reconciliation must repair missing or stale relationships through the **same** re-emission mechanism.

**Product category hierarchy — P2-S3 preflight.** This is the defect class already found with hierarchical WordPress Pages, so it is checked **before** the `commerce.taxonomies` migration is written. Do **not** assume `(taxonomy_type, slug)` is globally unique for a hierarchical taxonomy. Verify against the installed WordPress/WooCommerce version: whether `product_cat` can carry the same leaf slug beneath different parents; how WordPress/WooCommerce resolves those terms; whether configured public product-category URLs contain ancestor paths; and which source relationship exposes the parent hierarchy.

Regardless of the result, **the durable identity of a taxonomy row is its HSP/source identity**. `(taxonomy_type, slug)` must **not** become the primary identity of a term, and **no `UNIQUE (taxonomy_type, slug)` constraint** may be added unless source verification proves it valid for the specific supported taxonomy semantics — a normal lookup index is fine. (DECISION AA reached the same conclusion for Content: it shipped the composite as an index and explicitly declined the unique constraint.)

**If hierarchical leaf slugs can be ambiguous, DECISION AD/AE/AF establish the direction** — full ancestor path resolved at read time, **not** an arbitrary leaf-slug tie-break and **not** a parent WordPress ID in the public contract, so `/clothing/men/shirts` addresses the exact hierarchy. Reuse the existing Core hierarchical-query capability built for Pages (`HierarchicalQueryProviderInterface`) if its contract is suitable; do **not** invent a second hierarchy architecture for Commerce, and do **not** add a stored derived category URI/path without a separate ruling — a parent rename must resolve from the current projected hierarchy rather than leaving descendant path columns stale (DECISION AD ruling 3). **Note for the implementing session:** DECISION AF removed the one-segment leaf fallback from the page contract, so a new Commerce hierarchy has **no legacy arm to preserve** and should ship exact-path-only from day one; DECISION AE's unprojected-ancestor limit applies equally and must be stated rather than rediscovered.

**Filtering consequence.** If verification proves leaf slugs ambiguous, a Product filter must not keep an ambiguous bare `categorySlug` as its canonical category key — use an exact category path or another domain-safe public identifier consistent with the hierarchy ruling. **WordPress term IDs must not be the required filtering key.** The outcome lands in the P2-S3 contract before Category Queries are implemented.

**Performance (extends the P1B-S0 PERFORMANCE DoD rule).** Mixed Content + Commerce traffic is benchmarked **together** — a Commerce-only queue is insufficient. A busy Commerce queue must not starve Content updates and vice versa. No session may raise default bounded-cycle work by multiplying the batch size per domain. Query plans for product list, category filter, attribute filter, variation lookup and stock filter must be index-backed **with realistically sized joined tables**. Listing one product and listing a full page must show no N+1 growth. Real handlers produce integration fixtures; integration schema comes from the real migration files. Standard content and product updates continue to satisfy the DECISION AB sync-latency objective under realistic mixed-domain load. **If DECISION AB's ≈20.1 s saturated-cycle figure leaves insufficient margin once Commerce is added, STOP-and-flag** — do **not** silently increase the cycle time budget, PHP timeout, connection count or processing architecture. Optimization examines query efficiency, source-loading cost, adapter batching efficiency, fair partition allocation and configured batch sizing **before** any architectural expansion.

**Source-fact verification protocol.** Nothing in the Phase 2 rows is promoted from "likely true" to architectural fact. Before each relevant session, verify against the WooCommerce version installed in the development site, using WooCommerce **public** CRUD functions and hooks: product CRUD source API; variation CRUD source API; which WordPress **and** WooCommerce hooks fire for product changes; which fire more than once for one logical save; global attribute-definition storage and lifecycle hooks; `product_cat` behaviour; `pa_*` taxonomy behaviour; catalog visibility source (Requirement B); inventory/stock source APIs and hooks (AG-14); product/variation image references; HPOS only far enough to prove Orders remain irrelevant to Phase 2; WooCommerce-absence behaviour; permalink configuration (Requirement A); and the store currency source API (Requirement C′). **Do not build synchronization against private storage details merely because a value happens to be visible in `wp_postmeta`** — WordPress/WooCommerce APIs remain the authoritative source access layer.

---

#### Part 6 — Phase 2 scope boundary

**In scope:** Products, Product Variations, Product Categories, Attributes, Attribute Terms, Inventory, the required Commerce filtering and Category queries, and the infrastructure changes strictly necessary to prove the second module.

**Out of scope:** Orders, Customers, Cart, Checkout, Payments, Shipping workflows, Coupons (unless already explicitly required), search expansion, GraphQL, Redis, OpenSearch, new queue technology, additional execution drivers, and new infrastructure orchestration. **WooCommerce cart remains WordPress-owned runtime functionality and must not be projected into PostgreSQL as part of this phase.** Also out by ruling: `product_tag`, local/custom (non-global) product attributes, and a second `commerce.attribute_terms` table (AG-9).

---

#### Part 7 — Session map inserted into IMPLEMENTATION_PLAN.md §5b

```
P2-S0  Ratification / docs (this session)
P2-S1  Multi-module readiness + availability/readiness lifecycle + module bootstrap lifecycle
P2-S2  Commerce module + Products + simple/variable + unsupported-type transitions
       + real upgrade/bootstrap proof + Product permalink verification
P2-S3  Shared Commerce taxonomies + Product Categories + hierarchy/path verification
       + Product Category permalink verification
P2-S4  Global Attribute Definitions + pa_* Terms
P2-S5  Product Variations
P2-S6  Inventory + Product/Variation inventory ownership + out-of-order read semantics
P2-S7  Full mixed-domain validation + lifecycle/tombstone validation + replay/reconciliation
       + performance + permalink status
```

The vertical order is deliberate — **product → taxonomy/category → attribute definition/term → variation → inventory** — so Variations arrive **after** the attribute semantics they depend on, removing the temporary/duplicate attribute model the naive ordering would force. **P2-S3 and P2-S4 must not be recombined to preserve tidier numbering.**

**P2-S1 ships no Commerce code**, so it proves the Core architecture with a **test-scoped second-domain fixture** that behaves like an independent module but contains **no WooCommerce business logic**. A fake production Commerce module must not be created to satisfy P2-S1. P2-S1 proves the architecture is generically multi-module; **P2-S2 proves Commerce actually consumes it** (`ContentModule` + `CommerceModule` registering simultaneously through the exact generic mechanism, with zero new concrete Commerce reference under `core/`).

---

### DECISION AH — Permalink Reconstruction: Case B Authorised, Implementation Scheduled for Phase 4

**Status: Accepted (architect ruling 2026-09-08). Resolves FLAG-COMMPERMA-1.**

Requirement A (DECISION AG) made permalink parity a Phase 2 verification target and required the
outcome to be classified Case A / B / C. The P2-S2 and P2-S3 preflights classified it **Case B** — a
store-level configuration projection is required — and Case B mandates STOP-and-flag rather than
invention inside a build session. This decision is that ruling.

**The flag is RESOLVED. It is NOT an unresolved architecture gap: the architecture is decided and
the implementation is scheduled.** Record it as *architecture decided, implementation scheduled for
Phase 4*, never as an open gap.

**Phase 2 remains correct as shipped** — no stored product permalink, no stored category permalink,
no derived URL/path/URI column, and no permalink field in the Commerce endpoint contract. Phase 2
does not reopen.

**(AH-1) A Commerce-owned store-level configuration projection IS authorised — in Phase 4.**
Conceptually `commerce.store_config`; the exact table shape is a Phase 4 implementation detail
following the existing canonical-model / adapter / checksum / tombstone rules. It is **ONE
store-level source**. Permalink configuration is **never duplicated onto** `commerce.products`,
`commerce.product_variations` or `commerce.taxonomies`, and **no derived permalink is persisted per
entity**. The consequence is the point: changing `product_base` from `/product/%product_cat%/` to
`/shop/%product_cat%/` must update the configuration projection **only** — never rewrite every
Product row.

**(AH-2) Commerce owns it, not Core.** This is WooCommerce-domain state. Do **not** create a generic
`core.settings`, `system.wordpress_settings` or `platform_permalink_settings` projection because
Content might one day want something similar — Core must not absorb domain semantics pre-emptively.
A Core contract may be introduced **only** once two real modules demonstrate a genuinely shared
capability. Do not generalise from one use case.

**(AH-3) Minimum configuration, with token semantics intact.** The Phase 4 source model preserves at
minimum the verified WooCommerce semantics for `product_base`, `category_base` and `attribute_base`.
`product_base` keeps its **template** form — `/product/`, `/shop/`, `/%product_cat%/`,
`/shop/%product_cat%/` — and must not be flattened into a prematurely derived path. `%product_cat%`
is **not a separate configuration value**; it is part of the template, and its expansion is the
resolver's job.

**Those three values must NOT be assumed to be the complete input set.** Before any Phase 4
migration or API work, verify the COMPLETE state required to reproduce WooCommerce's public relative
path structure — including whether correct reconstruction also depends on WordPress-level settings
such as permalink mode/structure, trailing-slash behaviour, site-relative path semantics, or other
settings the supported WooCommerce version actually consults. **Only verified required settings may
be added; an arbitrary dump of WordPress options is prohibited.** The aggregate carries the minimum
stable state the supported permalink contract needs.

**(AH-4) Invalidation flows through the normal pipeline.** Configuration changes travel
capture → `commerce.store_config.changed` → outbox → relay → dispatch → handler → projection, like
every other aggregate. Verify the exact hooks against the supported version first; the **preferred
direction is a narrow option-specific update hook** for the verified option. A generic
`updated_option` hook is acceptable **only when narrowly guarded to the exact supported option(s)** —
capturing every WordPress option change as a Commerce event is prohibited. Any additional
WordPress-level setting found necessary at the Phase 4 preflight needs its own verified invalidation
mechanism. **Module bootstrap and reconciliation must include the current store configuration**, so
an existing store converges without waiting for an admin settings edit. No direct WordPress → PostgreSQL
repair path, and no settings updater that bypasses the event pipeline.

**(AH-5) Permalinks are resolved at READ time.** Never persist `product_permalink`,
`category_permalink`, `path`, `uri` or `url` as derived columns. Resolution composes
`commerce.store_config` + `commerce.products` + `commerce.taxonomies`/hierarchy at read time, so a
base change, a hierarchy change or a slug change is reflected as soon as its own projection
converges — with **no descendant or product URL rewrite fan-out**, and **no WordPress query during
delivery** (Rule 6 / ADR-040).

**(AH-6) HSP owns the resolution algorithm.** Every frontend must not independently reimplement
WooCommerce's permalink-selection rules. This matters most for `%product_cat%`, because a product may
belong to several categories: Phase 4 must **verify** WooCommerce's supported public behaviour for
choosing the category portion and implement the equivalent deterministic behaviour from projected
state. Do **not** arbitrarily pick the first row returned, the lowest taxonomy id, or the
alphabetically first category unless verification proves that is the supported behaviour. Where
WooCommerce's behaviour is filter/plugin-extensible beyond what HSP can reproduce generically, HSP
implements and documents **supported core WooCommerce behaviour** and promises no third-party
permalink-filter parity. The goal is to match supported WordPress/WooCommerce behaviour as closely
and deterministically as practical — not to reproduce arbitrary plugin-defined runtime filters
without their logic.

**(AH-7) Delivery contract: a read-time derived link.** The primary contract is a computed
`links.permalink` on the affected resource — e.g. `ProductResource` → `links.permalink:
/clothing/blue-shirt/`, `ProductCategoryResource` → `links.permalink:
/product-category/clothing/shirts/`. The value is a **RELATIVE PUBLIC PATH** unless a later API
ruling explicitly authorises absolute URLs, because the WordPress host and the headless frontend host
may differ and HSP reproduces permalink STRUCTURE without assuming a frontend domain. **This value is
never persisted** — it is computed from projected state when the resource is read.

**No `GET /hsp/v1/store` endpoint is required to solve this flag.** The store configuration
projection is first an internal Commerce capability used by the resolver. If Phase 4 has an
independent consumer requirement for store-level information (currency, permalink configuration,
other approved settings), `/hsp/v1/store` may be evaluated as part of Phase 4 API design — but a
broad store endpoint must not be created merely because the internal projection exists. This ruling
deliberately chooses read-time `links.permalink` over forcing each frontend to reconstruct
WooCommerce URLs from a `/store` payload.

**(AH-8) Product Category permalinks follow the same model.** Hierarchy is resolved from current
projected taxonomy relationships; **category paths are not stored**. Ambiguous hierarchical leaf
slugs use the already-approved exact hierarchical path semantics (DECISION AD/AE/AF). A parent or
category rename changes the computed permalink as soon as projected state converges. **No WordPress
term id becomes part of the public addressing contract.**

**(AH-9) The Phase 2 guards.** `test_no_commerce_projection_stores_a_derived_url` is **permanently
valid in principle** — the projection must never contain derived entity URLs.
`test_no_commerce_endpoint_publishes_a_permalink` is valid **for the Phase 2 API contract only**.
When Phase 4 introduces the approved read-time contract, that endpoint guard is **replaced or
amended, never simply deleted**; the replacement must prove that the permalink exists only as a
delivery-time derived field, that no Commerce projection stores it, that no WordPress request occurs
during resolution, that a store-config change alters the resolved path without rewriting Product
rows, and that a category hierarchy change alters it without stored descendant URLs.

**Roadmap.** Phase 4 gains an explicit item covering: the store-config aggregate/projection; verified
settings capture and invalidation; initial bootstrap/reconciliation; the Product permalink resolver;
the Product Category permalink resolver; `%product_cat%` expansion; the `links.permalink` delivery
contract; no stored derived URLs; no delivery-time WordPress reads; and compatibility tests against
the supported WooCommerce permalink configurations.

---

### DECISION AI — The Full-Batch Cycle Budget is a Controlled CI Performance Gate

**Status: Accepted (architect ruling 2026-09-08). Resolves FLAG-PERFCYCLE-1.**

`ProcessingCycleIntegrationTest::test_a_full_default_batch_drains_within_the_cycle_time_budget`
straddled its threshold on the development workstation — six consecutive runs of an unchanged tree
produced 8.14 s, 9.97 s, 10.18 s, 12.72 s, 14.52 s and 25.05 s against a 10.0 s line. Measurement
established it as environmental rather than a regression: reverting the session's only Content-path
change made it *slower*. **Option (a) is chosen — accept as environmental, and require a controlled
CI performance gate.** Options (b) baseline subtraction and (c) raising the threshold are both
**rejected**.

**(AI-1) The performance guarantee is unchanged.** The assertion remains: the extrapolated default
projection batch must drain in **less than half of `processing.cycle_time_budget_seconds`** — with
the shipped values, a full default batch under **10 seconds** against a 20-second budget. That
architectural threshold does not move because one workstation has unstable database round-trip
latency. The test is valuable; the host is simply not a reliable place to enforce it.

**(AI-2) Enforcement moves to a controlled performance environment.** This assertion is classified a
**PERFORMANCE GATE**, not an ordinary machine-independent integration test. It executes in CI or on
the designated stable performance runner, where database services are **colocated** with the runner,
network and storage conditions are stable, and the environment is reproducible enough for a
10-second gate to mean something. **Containerisation is not the problem and "containers are slow"
must not be encoded as architecture** — if containers in CI are colocated, controlled, reproducible
and low-jitter, containers are acceptable. The defect is the noisy host topology, not containers.

**(AI-3) Local execution may skip, explicitly and visibly.** Default local development runs may skip
this wall-clock gate behind an explicit environment guard — conceptually `HSP_PERFORMANCE_GATE=1`,
naming to follow project convention. Requirements: the skip is explicit and visible; a skipped gate
reports as **skipped, never as passed**; the CI performance job explicitly enables it; and release
evidence must show the gate actually executed in the controlled environment. A developer's standard
integration suite must not fail at random because host↔container latency changed that morning.

**(AI-4) No self-calibration.** Option (b) is **not authorised in this phase**: no measured network
baseline, subtraction formula, synthetic latency compensation or host calibration factor. A
self-calibrating wall-clock test adds machinery and risks subtracting away a real regression. The
gate measures actual wall-clock work in an environment suitable for measuring it. Keep it simple.

**(AI-5) The threshold does not rise.** Not 10 s → 15 s, not 10 s → 20 s, not any larger number, to
accommodate the local host. DECISION AG Part 5 item 10 remains in force. **If the controlled runner
fails the existing budget consistently, that is a STOP-and-flag** and evidence of a genuine
performance problem — investigate query efficiency, event processing cost, adapter work, source
loading, PostgreSQL and MySQL access patterns, batch allocation and round-trip count **before**
requesting an architectural budget change.

**(AI-6) Mixed-domain performance remains a separate, still-required proof.** Two distinct questions:
**(A)** can one full configured projection batch drain inside the protected cycle budget — enforced
by the controlled CI gate; **(B)** does adding Commerce consume the SLA margin or starve Content —
enforced by the Phase 2 mixed-domain scenarios. **Both remain required.** A passing two-event
mixed-domain measurement does not permanently replace the full-batch gate, and a noisy local
full-batch run does not invalidate the clean mixed-domain result. P2-S7 preserves both kinds of
evidence.

**No production behaviour changes because of this flag** — not
`cycle_time_budget_seconds`, `projection_batch_size`, cron cadence, PHP timeout, PostgreSQL
connection count, or the execution architecture.

---

## Implications Carried into Schema

> **This table is ADDITIVE: it lists only deltas from Doc 3. Base table DDL remains governed by Doc 3 §4/§20–24. Migrations must compose Doc 3 base + these deltas; freeze checks verify both.**

The following tables and columns are affected by the rulings above. Migration freeze checks must verify each entry against this list.

### MySQL — WordPress database

| Table | Change | Driven by |
|---|---|---|
| `wp_hsp_outbox` | Column-level DDL frozen in v1.3 — see OPEN-6 Amendment (v1.3). Columns: `id CHAR(36) PK` (event_id), `event_type VARCHAR(255)`, `event_version INT`, `aggregate_type VARCHAR(100)`, `aggregate_id VARCHAR(255)`, `aggregate_version BIGINT`, `source_updated_at DATETIME NOT NULL` (UTC), `checksum CHAR(64)`, `correlation_id CHAR(36)`, `causation_id CHAR(36) NULL`, `payload JSON`, `status ENUM('pending','relayed')`, `created_at DATETIME NOT NULL` (UTC, capture time), `relayed_at DATETIME NULL`. Index on `(status, created_at)`. All `DATETIME` columns are UTC (v1.2 canon). | OPEN-6, OPEN-3 (v1.2), OPEN-6 (v1.3) |
| `wp_hsp_aggregate_counters` | New table: PK `(aggregate_type VARCHAR(100), aggregate_id VARCHAR(255))`, `version BIGINT`; atomic increment via `INSERT … ON DUPLICATE KEY UPDATE`. No timestamp columns. | DECISION 2 (v1.1) |

> **Note (v1.1):** The v1.0 rows for `wp_postmeta` (`_hsp_version`) and `wp_termmeta` (`_hsp_version`) are removed. That storage is superseded by `wp_hsp_aggregate_counters` per DECISION 2 amendment.

> **Note (v1.2):** MySQL timestamp columns use `DATETIME`-UTC, not `TIMESTAMPTZ` (which is a PostgreSQL type). A freeze-check finding of `TIMESTAMPTZ` in a MySQL migration is a violation; `DATETIME` is correct.

### PostgreSQL — system schema

| Table | Change | Driven by |
|---|---|---|
| `system.events` | New columns: `aggregate_version BIGINT`, `source_updated_at TIMESTAMPTZ`, `checksum VARCHAR(64)`, `correlation_id UUID`, `causation_id UUID` | OPEN-5 (v1.1) |
| `system.events` | Event `type` column must accept fully-qualified `<domain>.<aggregate>.<action>` values | OPEN-1 |
| `system.queue_jobs` | New columns: `worker_id UUID`, `visibility_timeout_at TIMESTAMPTZ` | OPEN-4 (v1.1) |
| `system.dead_letter_jobs` | New columns: `stack_trace TEXT`, `attempt_count INTEGER`, `worker_id UUID`, `payload_snapshot JSONB NOT NULL` (NOT NULL per DECISION A v1.4; Doc 3 `payload` superseded) | OPEN-3 (v1.1), DECISION A (v1.4) |
| `system.dead_letter_jobs` | New column: `replayed_at TIMESTAMPTZ NULL` (NULL = not yet replayed; stamped in the single-transaction replay per DECISION S; DLQ rows are never deleted). Absent from migration 0004 — added by a forward migration authorized in OPS-S1; migration 0004 must not be edited. | DECISION S (v1.16) |
| `system.worker_heartbeats` | New table: PK `worker_id UUID`; `worker_type TEXT NOT NULL`, `status TEXT NOT NULL`, `last_heartbeat_at TIMESTAMPTZ NOT NULL`, `started_at TIMESTAMPTZ NOT NULL`. Single current-state row per worker, upserted per tick; no history table. Migration authorized in OPS-S1. | DECISION P (v1.16) |
| `system.aggregate_versions` | New table: PK `(aggregate_type, aggregate_id)`, `latest_processed_version BIGINT`, `latest_processed_at TIMESTAMPTZ` | OPEN-2 |
| `system.processed_events` | New table: PK `event_id`, `checksum VARCHAR(64)`, `processed_at TIMESTAMPTZ` | OPEN-7 (v1.1), DECISION 3 |
| `system.schema_versions` | Frozen DDL: `id UUID PK`, `migration_name VARCHAR(255) NOT NULL`, `schema_context VARCHAR(100) NOT NULL` (engine-qualified values: `'core/mysql'`, `'core/pgsql'`, `'content/pgsql'`, etc.), `applied_at TIMESTAMPTZ NOT NULL`, `rolled_back_at TIMESTAMPTZ NULL`, `checksum VARCHAR(64) NOT NULL`, `UNIQUE(migration_name, schema_context)` | OPEN-8 (v1.4) |
| `system.module_versions` | Frozen DDL: `id UUID PK`, `module_name VARCHAR(100) NOT NULL`, `schema_version VARCHAR(50) NOT NULL`, `applied_at TIMESTAMPTZ NOT NULL`, `notes TEXT NULL`, `UNIQUE(module_name, schema_version)`, `INDEX(module_name, applied_at DESC)` | OPEN-8 (v1.4) |
| `system.security_events` | Frozen DDL: `id UUID PK`, `event_type VARCHAR(100) NOT NULL` (`security.<aggregate>.<action>`), `severity VARCHAR(20) NOT NULL`, `actor_type VARCHAR(50) NULL`, `actor_id VARCHAR(255) NULL`, `ip_address VARCHAR(45) NULL`, `metadata JSONB NOT NULL`, `created_at TIMESTAMPTZ NOT NULL`, `INDEX(event_type, created_at)` | OPEN-8 (v1.4) |

> **Note (v1.2):** Module-owned `content.*` tables (`content.pages`, `content.posts`, `content.taxonomies`, `content.media`, and any future module projection tables) are not listed here because they are generated in Phase 1A, not Phase 0. However, they **must** follow the v1.2 type canon: `TIMESTAMPTZ` for all timestamp columns, `VARCHAR(64)` for all checksum columns. Their freeze check occurs at the Phase 1A DoD gate. Doc 3 §9–11, which show bare `TIMESTAMP` for these tables, is superseded by OPEN-3 (v1.2).

> **Note (v1.8 — P1A-S4 delivery):** `content.pages`, `content.posts`, `content.taxonomies`, and `content.entity_taxonomies` migrations were delivered in P1A-S4. All timestamp columns use `TIMESTAMPTZ`; all checksum columns use `VARCHAR(64)`. `content.entity_taxonomies` is a pure join table — (entity_id UUID, taxonomy_id UUID) composite PK only (FLAG-P1AS4-1). The freeze check for all `content.*` tables occurs at the Phase 1A DoD gate (end-to-end validation in P1A-S6) per the v1.2 rule. `content.media` remains OUT of MVP scope (Phase 1B).

> **Note (v1.10 — content.* soft-delete column):** The tombstone path (DECISION I) writes the `deleted_at TIMESTAMPTZ NULL` column that already exists on `content.pages`, `content.posts`, and `content.taxonomies` from the P1A-S4 migrations. DECISION F's default listing filter (`WHERE status = 'publish' AND deleted_at IS NULL`) depends on this same column. No new migration is owed by P1A-S6b — the column is already present.

> **Migration freeze rule:** no schema migration that touches any table or column in the tables above may be merged unless it is consistent with the ruling in the referenced OPEN / DECISION item, or this document is formally amended with a new versioned entry.

### PHP Contracts and Infrastructure

> **This table records non-schema implications: interface changes, class-level dependencies, and wiring obligations introduced by rulings. These are as binding as schema implications.**

| Component | Change | Driven by |
|---|---|---|
| `core/Contracts/AdapterInterface` | Gains method `tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void`. All existing adapter implementations (PageAdapter, PostAdapter, CategoryAdapter) must implement it. The tombstone performs a soft-delete (`deleted_at = now()`) inside a single-PG transaction covering all three DECISION 3 ops. If the target row does not exist, the projection write is a no-op but `system.processed_events` and `system.aggregate_versions` are still updated. | DECISION I (v1.10) |
| `core/Workers/Strategies/EventWorkerStrategy` | Gains a PostgreSQL read dependency for the Resolve-stage aggregate-version lookup (`system.aggregate_versions`). Must be injected via constructor (ADR-012); no service-locator call permitted. Resolves the `system.aggregate_versions` row using a non-locking SELECT before handler invocation. | DECISION J (v1.10) |
| `core/Container/Definitions/WorkerServiceProvider` | Must wire the aggregate-version read dependency into `EventWorkerStrategy` via constructor injection. | DECISION J (v1.10) |
| `core/Container/Definitions/DeliveryServiceProvider` | New service provider. Binds `DatabaseConnectionInterface::class` as a singleton opened with `PGSQL_CONNECT_FORCE_NEW`, wrapping `PostgresDatabaseConnection`. This is the exclusive binding for delivery reads (REST query providers), Resolve-stage reads (`EventWorkerStrategy`), and adapter persistence. Registered in `ContainerBuilder` before `WorkerServiceProvider` and `ContentServiceProvider`. | DECISION K (v1.11) |
| `core/Container/Definitions/QueueServiceProvider` | `DatabaseConnectionInterface::class` singleton binding removed. Queue provider binds only `'queue.connection.pgsql'` (its own FORCE_NEW handle) and `QueueProviderInterface`. | DECISION K (v1.11) |
| `core/Events/Dispatcher/` | New directory. `DispatcherWorkerStrategy` (implements `WorkerStrategyInterface`), `EventDispatcher` (reads `system.events` anti-join, calls `DatabaseQueueProvider::enqueueIdempotent()`), `DispatchBatch` (value object: event rows selected in one tick). | DECISION L (v1.12) |
| `core/Queue/Providers/Database/DatabaseQueueProvider` | Gains `enqueueIdempotent(EventInterface $event, string $queueName): void` — executes `INSERT … ON CONFLICT(event_id) DO NOTHING`. Does NOT replace or alter `enqueue()`. | DECISION L (v1.12) |
| `database/Core/pgsql/0011_add_unique_event_id_to_queue_jobs.sql` | New forward migration: `ALTER TABLE system.queue_jobs ADD CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)`. Must not edit frozen migration 0003. | DECISION L (v1.12) |
| `core/Container/Definitions/DispatcherServiceProvider` | New service provider. Binds `'dispatcher.connection.pgsql'` (FORCE_NEW `PostgresDatabaseConnection`), `'dispatcher.strategy'` → `DispatcherWorkerStrategy`, `'dispatcher.engine'` → `WorkerEngine`. The dispatcher connection is physically distinct from the delivery handle (DECISION K) and relay/queue handles. Registered in `ContainerBuilder` after `QueueServiceProvider`. | DECISION L (v1.12) |
| `bootstrap/CredentialResolver` | New class. Single source of truth for all database credential resolution. Implements `define()` → `getenv()` → default precedence. Required PG credentials (host, user, password, dbname) throw `\RuntimeException` when unresolvable. MySQL derives from WP `DB_*` constants by default; `HSP_MYSQL_*` overrides when present. Injected into provider factories via constructor (ADR-012). | DECISION O (v1.15) |
| `core/Container/Definitions/OutboxServiceProvider`, `QueueServiceProvider`, `DeliveryServiceProvider`, `DispatcherServiceProvider` | Each receives a `CredentialResolver` instance via constructor. Must not call `getenv()` directly for DB credentials. | DECISION O (v1.15) |
| `wp-config.php` (local dev) | HSP PostgreSQL credentials set via `define('HSP_PG_HOST', …)` etc. (not `putenv()`). MySQL credentials not duplicated — resolver reads `DB_*` WP constants directly. | DECISION O (v1.15) |
| `core/Workers/` heartbeat publisher | New `DatabaseHeartbeatPublisher` implements the existing `HeartbeatPublisherInterface` (replaces `NullHeartbeatPublisher` at runtime); upserts `system.worker_heartbeats` per tick; PG connection injected via constructor (ADR-012), using the worker-runtime handle (DECISION L Ruling 0) — no new handle/class/`pg_*` wrapper. | DECISION P (v1.16) |
| `core/Workers/Strategies/MaintenanceWorkerStrategy` | Un-stubbed: drives `DatabaseQueueProvider::requeueTimedOut()` on a config-driven cadence (no hardcoded timing); uses the worker-runtime handle. | DECISION R (v1.16) |
| DLQ replay path (`core/`) + WP-CLI `hsp dlq list\|inspect\|replay` | Replay runs in one PG transaction: verify DLQ row exists → verify `replayed_at IS NULL` → DELETE any `system.queue_jobs` row sharing `event_id` → INSERT fresh job `attempts = 0` → stamp `replayed_at`. Re-enters via normal queue/claim path; DECISION J Resolve-stage guard may ack with zero writes (correct). WP-CLI only; no admin UI. DLQ rows never deleted. | DECISION S (v1.16) |
| Metrics (no persistence) | No metrics table / rollups / external telemetry in MVP. Derived metrics (queue depth, DLQ depth, oldest-pending age, worker count) computed on demand via PostgreSQL aggregates; runtime counters (processed/retry/failure/replay) emitted as structured worker log events. "metrics emit" DoD = queryable status + structured logs. | DECISION Q (v1.16) |
| `core/Contracts/ReplayEmitterInterface` | New contract (core-owned). `emitForAggregate(string $aggregateType, string $aggregateId, string $correlationId, string $causationId): ?EventInterface` — reads current WP state, decides `.updated` (exists+public) vs `.deleted` (missing/non-public), emits ONE synthetic event through the outbox with a fresh counter version. Implemented in the Content module (`modules/Content/Replay/ContentReplayEmitter`); core never imports the module (Rule 5). | DECISION T (v1.17) |
| `core/Replay/ReplayService` | New class. Orchestrates entity replay (one aggregate) and date-range replay (`SELECT DISTINCT aggregate_type, aggregate_id FROM system.events` in `[from, to)`, read via the existing delivery `DatabaseConnectionInterface` handle — no fifth handle). Delegates per-aggregate emit to `ReplayEmitterInterface`. Assigns one `correlation_id` per run and a `causation_id` per replay operation. No projection writes; no historical-event mutation. | DECISION T (v1.17) |
| `core/Workers/Strategies/ReplayWorkerStrategy` | Un-stubbed: exposes `replayEntity()` / `replayRange()` delegating to `ReplayService`. `execute()` remains a no-op (`false`) — entity/date-range replay is a producer-side, CLI-triggered operation, not a `system`-queue consumer. **Doc 8 roster note (no Doc 8 edit):** Doc 8 §7 describes worker strategies as consumer-side `Claim→…→Ack` pipelines; `ReplayWorkerStrategy` is intentionally a producer-side strategy whose `execute()` is a deliberate no-op. If ever launched under a `WorkerEngine` it idles cleanly (returns false → 'idle' heartbeat + engine idle back-off; no busy-spin, no queue claim, no I/O, no exception — asserted by `ReplayWorkerStrategyTest::testIdlesCleanlyUnderWorkerEngine`). This is recorded here rather than by amending Doc 8. | DECISION T (v1.17) |
| WP-CLI `hsp replay entity <type> <id>` / `hsp replay range <from> <to>` | New CLI subcommands extending the OPS-S1 `hsp` surface (thin `\WP_CLI` shim → `ReplayWorkerStrategy`). WP-CLI only (consistent with DECISION S clause (d)). | DECISION T (v1.17) |
| `core/Contracts/WpReconciliationSourceInterface` | New core contract (core-owned; Content-module implementation `modules/Content/Reconciliation/WpReconciliationSource`). Detection-side WordPress reads only: list aggregate IDs by type (paged), fetch `post_modified_gmt` / existence / public-status, and the data to recompute the projection checksum for incremental/full modes. Symmetric with `ReplayEmitterInterface`; core never imports the module (Rule 5). Contract-only — no schema change. | DECISION U (v1.19) |
| `core/Reconciliation/ReconciliationService` | New class. Detection + batching + suppression; three modes as one detector + `mode` parameter (drift/incremental/full per D1). Reads `content.*` + `system.aggregate_versions` (+ `system.events` for suppression) via the existing delivery `DatabaseConnectionInterface`; reads WP via `WpReconciliationSourceInterface`; reads pending `wp_hsp_outbox` via the outbox read path. Repairs **only** by calling `ReplayService::replayEntity()` per genuinely-drifted aggregate — **no direct `content.*`/`system.*` projection writes.** Applies the D4 suppression rule. WordPress-wins by construction. | DECISION U (v1.19) |
| `core/Workers/Strategies/ReconciliationWorkerStrategy` | Un-stubbed in OPS-S3 as a façade over `ReconciliationService`: `reconcileDrift()` / `reconcileIncremental()` / `reconcileFull()`. `execute()` stays a producer-side no-op (`false`, B1 — matches `ReplayWorkerStrategy`); reconciliation is CLI/cron-triggered, not a `system`-queue consumer. Service dependency via constructor injection (ADR-012); `WorkerServiceProvider` wiring updated (was `new ReconciliationWorkerStrategy()`). Detection: missed captures (WP newer/absent vs delivery — DECISION 1 backstop) and orphans (full-mode only, PG→WP). Repair via DECISION T re-emission ONLY; no new handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E). | DECISION U (v1.19) |
| WP-CLI `hsp reconcile drift\|incremental\|full` (+ `status` dry-run) & WP-Cron triggers | New CLI subcommands extending the OPS-S1 `hsp` surface (thin `\WP_CLI` shim → `ReconciliationWorkerStrategy`); WP-CLI only. WP-Cron authorized (CLAUDE.md recovery-jobs carve-out) to *trigger* passes only via three schedules (hourly/nightly/weekly, cadence + page-size config-driven); callbacks call the same strategy methods; the worker-bootstrapped process remains the execution path. Trigger is swappable to external scheduling later with no repair-path change. | DECISION U (v1.18/v1.19) |
| `core/Operations/` | New core-infrastructure subtree (lowercase; `HSP\Core\Operations\`): Operations Registry (Page/Nav/Widget/Action/Asset), Providers (Health/Metrics/Worker-Status/Queue-Status/Endpoint), Services, Diagnostics, and **server-rendered PHP** admin UI (no node/npm/bundler toolchain, no shipped JS bundle; minimal vanilla JS only). Console is **observability/diagnostics only** — not a control plane. Registry-driven discovery (explicit registration, no reflection); providers resolve via constructor injection (ADR-012). | DECISION V (v1.20); ADR-047/048/052/053 |
| `core/Contracts/Operations/` | New namespace under the existing contracts root. **All operations contracts live here** (provider/widget/action/diagnostics/metrics interfaces), NOT under `core/Operations/Contracts/`. Keeps Rule 5 verbatim: modules (`modules/*/Operations/`) that provide console implementations depend on `core/Contracts/` only. | DECISION V (v1.20 — FLAG-11 A) |
| Console provider PG reads | Reuse the delivery `DatabaseConnectionInterface` (DECISION K) from the wp-admin request context — no fifth handle (DECISION L Ruling 0 topology unchanged), no new raw `pg_*` wrapper (DECISION E). Providers read `system.queue_jobs`, `system.dead_letter_jobs`, `system.worker_heartbeats`, `content.*`. | DECISION V (v1.20 — FLAG-10 A) |
| Console metrics | **No new persistence** — all metrics derived on-demand per DECISION Q (processing rate = rolling-window query; replay/reconciliation status = last-run summary from existing rows/logs). No metrics table, no rollups, no time-series store. | DECISION V (v1.20 — FLAG-5 A); DECISION Q |
| Console Operational Actions (`core/Operations/` + `modules/*/Operations/`) | **Replay + Reconcile only.** Thin delegators to `ReplayService` (DECISION T/S) and `ReconciliationService` (DECISION U); **no second repair path**, no direct `content.*`/`system.*` writes (write-spy proof required in OPSC-S4 DoD). **Flush Queue REMOVED** (destructive; violates Rule 4 / DECISION 1). **No Restart Workers action** — worker status/heartbeat/runbook links only; lifecycle belongs to the supervisor. Actions gated by capability + confirmation + audit. | DECISION V (v1.20 — FLAG-6/7/8); ADR-053; ADR-051 HELD |
| Coding standard (now settled) | PSR-12 for all platform code; WPCS security rules (escape/sanitize/capability/nonce) at WordPress entry points only. Lifts the IMPLEMENTATION_PLAN §3 "do not enforce until confirmed" hold; the WP-admin boundary is open. **DECISION W (v1.21) extends the WPCS boundary to the REST/ajax endpoints the React admin UI calls** (the untrusted-client JSON boundary). | DECISION V (v1.20 — FLAG-4 A); DECISION W (v1.21) |
| Admin UI stack (amends DECISION V (a)) | **React + shadcn is THE admin UI stack** (supersedes DECISION V (a)'s server-rendered PHP + no-node-toolchain ruling going forward). Build-artifact policy = **commit `dist/` to the repo** (npm build in dev/CI only; production deploy is a file copy per the CLAUDE.md robocopy step; **no host build step**). `package.json`/toolchain in repo for dev/CI; production carries only built assets. The **already-shipped OPSC-S1..S4 server-rendered PHP console remains as built** (not rewritten); only **new** admin UI (incl. onboarding) is React. Provider/registry architecture (registries, provider contracts, `OperationsService` seam, ADR-047/048/052/053, console-is-observability-only) is **unchanged**. | DECISION W (v1.21 — ruling (a)) |
| `core/Onboarding/` | New core-infrastructure subtree (`HSP\Core\Onboarding\`): first-run preflight/prerequisite checks, onboarding admin page (React), nav gating on `hsp_onboarding_state`, backfill trigger, derived progress. **Lifecycle/setup surface — NOT under `core/Operations/`** (keeps DECISION V (j) console-is-observability-only intact). Delegates to ratified services only (`ReconciliationService` DECISION U, migration engine, Worker Status/heartbeat read path); opens no PG handle (reuses delivery `DatabaseConnectionInterface` — DECISION K; no fifth handle DECISION L Ruling 0; no new `pg_*` wrapper DECISION E). Any onboarding contracts live under `core/Contracts/` (Rule 5). | DECISION W (v1.21 — ruling (e)) |
| Onboarding backfill (first-run content migration) | **Full-reconciliation re-emission via `ReconciliationService::reconcileFull()` (DECISION U) through the normal outbox→relay→dispatch→worker pipeline.** Thin delegator; **NO direct WP→PG copy path, no second repair path** — projections written only by the worker pipeline (write-spy proof required in ONB-S2 DoD, mirrors DECISION V (d)/GATE-S3). WordPress-wins by construction. No new event contracts (reuses OPEN-1 `.updated`/`.deleted`). | DECISION W (v1.21 — ruling (b)); DECISION U; DECISION T |
| Onboarding queue-drain prerequisite | **A live worker heartbeat is a HARD PREREQUISITE** for triggering backfill — a fresh `system.worker_heartbeats` row must exist (DECISION P age check vs config threshold). Workers drain the pipeline as normal (execution path); **no in-request tick drain** (the admin request never runs the worker engine inline). If no live worker, backfill is blocked → worker-status + runbook guidance (no Restart Workers — DECISION V (f)). | DECISION W (v1.21 — ruling (c)); DECISION P |
| Onboarding progress + completion state | Progress **derived on-demand per DECISION Q** (expected-count scan of in-scope WP aggregates vs processed/projection counts at read time); **zero new PG persistence** — no progress table/rollups/time-series. Completion signal = a single WordPress option **`hsp_onboarding_state`** in MySQL (WP options table) — **no schema migration, no new table/column**. | DECISION W (v1.21 — ruling (d)); DECISION Q |
| Onboarding nav gating + preflight hard-block | Until `hsp_onboarding_state = complete`, the **Operations + API Playground admin pages are not registered/visible** (menu registration gated on the flag); the onboarding page is the only HSP admin surface. **ONB-S1b environment-preflight hard-blocks (four checks):** (1) `pgsql` extension loaded; (2) PG constants defined in `wp-config` (DECISION O `HSP_PG_*`); (3) PG reachable; (4) PHP version ≥ platform minimum. **Amended v1.22:** the migration-engine-state check (required core+content migrations applied per `system.schema_versions`/`system.module_versions`, OPEN-8) is a **backfill prerequisite evaluated in ONB-S2**, not part of the ONB-S1b environment preflight — same hard-block semantics on the same delivery-handle read path, moved to where the delivery schema/data readiness actually gates work. A failed prerequisite is a hard block with remediation guidance, not a warning. | DECISION W (v1.21 — ruling (f), amended v1.22); DECISION O; OPEN-8 |
| Onboarding self-remediating backfill gates (ONB-S2) | The two backfill gates (migrations applied; processing pipeline advancing) are **self-remediating in-product** so a **zero-config fresh install** completes with **no manual CLI** (ADR-054 **Principle 8**) — each keeps its **hard block** (action, not bypass). **`POST hsp/v1/onboarding/migrate`** applies the outstanding core + content migrations through the **EXISTING** engine over the **DECISION W (e) delegate list** (core migrations + module `getMigrations()` — OPEN-9/Rule 5), a thin delegator (`core/Onboarding/MigrationApplier`; no new engine/DDL/schema/`pg_*` wrapper/handle), gated on the four env preflight checks (409 until they pass), re-evaluating `MigrationsAppliedCheck` after. **`POST hsp/v1/onboarding/spawn-worker`** issues a **non-blocking** WP-Cron spawn (`core/Onboarding/WorkerCronSpawner`) so a cycle runs + a heartbeat appears — **NO in-request drain (DECISION W (c) intact)**; WP-Cron-only warning when `DISABLE_WP_CRON` (no supervisor/systemd/daemon/restart). Plugin **`activate()`/`upgrade()` (OPEN-9)** attempt pending migrations through the same engine **IFF `HSP_PG_*` defined AND PG reachable**, silent no-op otherwise — **never fatal on an unconfigured site**. All WPCS-guarded (nonce+capability+sanitize — DECISION W (a)/V (b)). No second repair path; no schema change; no new handle/wrapper. | DECISION W (f) (amended **v1.23**); DECISION W (e)/(a)/(c); DECISION X (4); OPEN-8/OPEN-9; ADR-054 Principle 8 |
| Background execution model (WP-Cron only, v1.x) | **No long-running workers / supervisors / systemd / Docker workers / CLI daemons.** Background processing = a **WP-Cron-triggered Processing Engine** running one **bounded, stateless cycle** per tick (relay batch → dispatch batch → projection batch → maintenance → persist metrics → clean exit). Config keys in `config/worker.php`: `processing.relay_batch_size`, `processing.dispatch_batch_size`, `processing.projection_batch_size`, `processing.cycle_time_budget_seconds` (config-driven, sensible defaults; **no schema migration**). Overlapping cycles safe via **existing** guarantees only (SKIP LOCKED + aggregate versioning [DECISION J] + visibility timeout [OPEN-4/DECISION R] + single-txn commit [DECISION 3]) — **no new locking mechanism**. Implementation class names retained (`WorkerEngine`/`*WorkerStrategy`/`WorkerExecutionContext`) as processing components invoked by cron — **no rename**. `system.worker_heartbeats` (DECISION P) reused verbatim, reinterpreted as **per-cycle freshness/progress**, not daemon liveness. Metrics: `worker_uptime`/`restart_count` **removed**; replaced by cycles-completed / avg-cycle-duration / per-stage-throughput / queue-backlog / processing-lag (derived on demand — DECISION Q). Recovery = next cron execution + queue durability + visibility timeout + replay (DECISION T) + reconciliation (DECISION U). **No fifth PG handle, no new `pg_*` wrapper** (DECISION L Ruling 0 / E unchanged). | ADR-054 (v1.23); supersedes ADR-024; amends ADR-035/ADR-036; Doc 8 v2.0 |
| `core/Contracts/WorkerInterface` (contract correction) | `run()` / `shutdown()` **removed**; contract now expresses **one bounded processing cycle** (execute one cycle honouring configured per-stage batch limits + execution-time budget; return a processing-result value describing the completed cycle). Internal core contract (no module implements it). Retains the name (ADR-054 §8). | DECISION X (v1.24 — ruling (3)); FLAG-ALIGN-1 (c) |
| `core/Workers/` Processing Engine cycle + heartbeat identity | The engine composes the four consumer-side stages (relay → dispatch → projection → maintenance) into **one bounded cycle** and exits (no `run()` loop, no `usleep`, no daemon lifecycle). Each cycle mints a **fresh UUIDv7** `worker_id` at bootstrap → `system.worker_heartbeats` holds one row per recent cycle (per stage); maintenance prunes stale rows under existing retention. Heartbeat `status` set = **`'running'`/`'idle'` only** (`'processing'`→`'running'`; `'shutdown'` removed). `DatabaseHeartbeatPublisher` SQL + DECISION P schema **reused verbatim**. Per-strategy daemon-engine bindings (`worker.engine.event`/`worker.engine.maintenance`/`dispatcher.engine`) retired from the execution path; one cycle-engine binding composes the stages. | DECISION X (v1.24 — rulings (1)/(2)); ADR-054; DECISION P/Q |
| Processing-cycle WP-Cron trigger + activation scheduling | A recurring processing-cycle WP-Cron event (custom interval from config cadence, `wp_next_scheduled` guard — `ReconciliationCronRegistrar` precedent) whose callback runs **one bounded cycle**; bound in `headless-sync.php`. `Application::activate()` schedules the processing event (+ reconciliation events for consistency); `deactivate()` clears via `wp_clear_scheduled_hook`. **No `hsp worker`/`hsp process` daemon CLI** (superseded ADR-024 surface); the cadence trigger is `wp cron event run`. | DECISION X (v1.24); ADR-054 §1/§8b; DECISION R (precedent) |
| Onboarding backfill worker prerequisite (realigned — implemented ALIGN-S2) | The DECISION W (c) "live worker heartbeat" prerequisite becomes: **(i) the processing-cycle cron event is scheduled AND (ii) a recent processing heartbeat exists** (both required). Remediation references **only WP-Cron** (`wp cron event run --due-now`, "ensure the processing cron is scheduled/firing") — **never** supervisor/systemd/daemon/"restart the worker". `BackfillService` no-in-request-drain + re-emission repair unchanged. | DECISION X (v1.24 — ruling (4)); FLAG-ALIGN-2; DECISION W (c); ADR-054 §5 |
| `core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface` (additive enrichment) | Descriptor **additively enriched** (no field removed; existing five fields method/route/namespace/displayGroup/description retained) to carry OpenAPI 3.1 Operation metadata: **parameters** (path + query, incl. DECISION F filters + cursor), **request/response schema** (Rule 6 published shapes — not internal `content.*`/canonical), **auth requirement** (public/authenticated — Doc 9 §22), **cursor-pagination envelope** (`data`+`next_cursor` — Doc 9 §13 / DECISION F `CursorPage`), **deprecation status** (Doc 9 §26 → OpenAPI `deprecated`), **version** (Doc 9 §7), **module owner** (Doc 9 §6). Core owns the contract (`core/Contracts/`, Rule 5); modules populate their own descriptors (`modules/*/Operations/`, e.g. `ContentEndpointProvider`) depending on `core/Contracts/` only. | ADR-055 (v1.26); Doc 12 §15; Doc 9 §6/§7/§13/§22/§26 |
| OpenAPI generator (`core/`) + `GET /hsp/v1/openapi.json` | New core generator produces an **OpenAPI 3.1** document **from the endpoint registry** (`EndpointProviderInterface`) — **never hand-authored, never reflection/scan-derived** from WP routes (explicit-registration idiom, ADR-048/052). Single source of truth = the registrations; the served spec **auto-updates** because it is derived at request time. Route `GET /hsp/v1/openapi.json` registered on the `hsp/v1` namespace (DECISION N) at the normal REST boundary (WPCS per DECISION V (b)/W (a)); versioned per Doc 9 §7 (the v1 doc describes v1). **Scoped to PUBLIC endpoints only** (Doc 9 §22 — FLAG-OAPI-1 resolved v1.27): endpoints requiring auth/capabilities are **excluded from the generated document**, exclusion driven by the metadata **auth field** (not route inspection); the generator endpoint itself stays **public + stateless** (no capability check inside generation — consistent with ADR-055 (e)). **Request-time + stateless:** NO persistence, NO PG read, NO new handle (DECISION L Ruling 0), NO `pg_*` wrapper (DECISION E), **NOT part of the ADR-054 cron cycle**. No schema change; no new event contract. | ADR-055 (v1.26; scoping v1.27); ADR-050; DECISION N/F; Doc 9 §7/§22; DECISION E; DECISION L Ruling 0; ADR-054 |
| OpenAPI drift guard (CI test) | A test asserts **(1)** every **non-exempted** registered `hsp/v1` REST route has a **complete** endpoint-metadata entry — enumeration reads the **full live `hsp/v1` REST index** (external ground truth, never the registry — non-circular), **subtracts the one frozen structural exemption `hsp/v1/onboarding/`** (DECISION W (e) — first-run admin surface, outside the published contract; further exempt prefixes need an architect ruling; guard hardcodes this prefix with an ADR-055 (f) citation), then requires a complete descriptor per remaining route — a non-exempted route without metadata **fails CI** (net today 13 − 6 = **7** guarded routes); route enumeration permitted here ONLY as a completeness assertion, never as the generation source; **(2)** the generated document **validates against the pinned OpenAPI 3.1 meta-schema** — the gate runs via **ajv (Node toolchain, `tools/openapi-validator/`)** layered over a PHP structural pre-check (ruling D, v1.29 — `opis/json-schema` removed for two reproduced 2020-12 defects; no conformant PHP validator; Node is a sanctioned dev/CI dep per DECISION W (a)); node-missing SKIPs unless `HSP_REQUIRE_NODE_GATE=1` (then FAILs); **(3)** (exclusion test, v1.27) **no endpoint whose metadata marks it non-public appears in the generated document** — public-only scoping (ADR-055 (d)) asserted positively; **(4)** (non-circularity, v1.28) a fixture `hsp/v1` route **outside** the exempted prefix **without** a descriptor **fails the guard**. | ADR-055 (v1.26 — clause (f); scoping v1.27; enumeration v1.28; ajv gate v1.29); ADR-048/052; DECISION W (a)/(e) |
| `content.taxonomies` + `content.entity_taxonomies` (shared taxonomy projection) | **One shared taxonomy projection per owning DOMAIN**, told apart by `taxonomy_type` — `category`, `post_tag` and every future **Content** taxonomy live in `content.taxonomies`; a new Content taxonomy is **new data, not a new table and not a migration**. **No table-per-taxonomy** and **no platform-wide taxonomy table** (Commerce gets its own `commerce.*` projection model, designed with the Commerce module — never `content.taxonomies` merely because WordPress stores it via the taxonomy API). `content.entity_taxonomies` stays a **pure join table** (composite PK only — P1A-S4 upheld, not amended). **Query rule:** every read of the shared table identifies BOTH taxonomy type and term identity; a read keyed on the globally-unique `source_term_id` is exempt. Enforced by `tests/Unit/Content/TaxonomyQueryRuleTest.php`. **Index deltas (migration `0008_align_content_taxonomy_indexes`):** `+ (taxonomy_type, slug)`, `+ entity_taxonomies (taxonomy_id, entity_id)`, `− (slug)`, `− (taxonomy_type)`, `− entity_taxonomies (taxonomy_id)`; `(taxonomy_type, parent_id)` deliberately **deferred** until a delivery query predicates on parent. **No column, key, constraint, contract, event, handle or persistence change.** No partitioning or table-per-taxonomy optimisation without profiling plus a future ruling. | DECISION AA (v1.33); FLAG-TAXSCHEMA-1; P1A-S4 join-table ruling; Rule 2 / Rule 5 |
| `content.pages` (hierarchical page addressing) + `core/Contracts/HierarchicalQueryProviderInterface` | **No schema change — that is the ruling.** `content.pages` must NOT gain a `path` / `uri` / `permalink` / derived-ancestor column, and no migration may add one: a stored path duplicates hierarchical state and goes stale on a parent rename (WordPress emits no descendant event; hourly drift is timestamp-only and would not see it). OPEN-11's "no precomputed URIs" exclusion **stands**. The public page identity is the **full ancestor path** — `GET /hsp/v1/pages/{path}`, exact hierarchy, `/pages/wrong-parent/team` is a 404 — resolved **at read time** by a recursive CTE walking up `parent_id` → `source_post_id` in **one** query (anchor on `idx_content_pages_slug`, hops on `uq_content_pages_source_post_id`; no N+1, no WP read, no cache), bounded by `PageQueryProvider::MAX_ANCESTOR_DEPTH` as a corruption/cycle guard. The public-set predicate (`status='publish' AND deleted_at IS NULL`) applies to the **requested page only**; ancestors are structural. `findBySlug()` on pages **no longer means a bare-slug lookup at all**: the one-segment compatibility fallback reached Removed in DECISION AF, and the method now delegates to `findByPath()`, so a bare page slug means the TOP-LEVEL page of that name. Every miss is a 404 at any depth. `QueryProviderInterface` is unchanged for the flat resources. **Enforced by** `tests/Integration/Content/PagePathAddressingIntegrationTest.php` (incl. a live `information_schema` assertion that no derived path column exists) and `tests/Unit/Content/Rest/ContentRestRegistrarTest.php`. **No column, key, constraint, migration, event, handle, `pg_*` wrapper or persistence change.** | DECISION AD (v1.36) as completed by DECISION AF (v1.38); FLAG-PAGESLUG-1; OPEN-11; Doc 9 §7/§26; DECISION F; Rule 6 |
| `ReconciliationService::PROJECTION` + `BackfillReader::PROJECTION` + `BackfillProgress::TYPES` (aggregate coverage) | All three consuming lists must carry **every** aggregate type the module's `WpReconciliationSourceInterface` implementation supports — currently **page, post, category, tag, media**. `reconcile()` skips an unlisted type **silently**, which means never reconciled and (since the backfill IS `reconcileFull()` — DECISION W (b)) never backfilled; onboarding then converges with none of it projected. `tag` reads `content.taxonomies` scoped `taxonomy_type = 'post_tag'`, `category` scoped `'category'` (DECISION AA query rule); `media` reads `content.media`/`source_post_id` and carries **no** taxonomy predicate. Adding a new aggregate is an edit to **all** of these plus the module source and replay emitter. **Enforced by** `tests/Unit/Reconciliation/AggregateCoverageTest.php` (unit, so it holds without a live database); `Phase1BValidationTest` guards the producing side. Convergence now scores media and tags. **No column, key, constraint, contract, event, handle, `pg_*` wrapper, migration or persistence change.** | DECISION AC (v1.35); FLAG-RECON-COVERAGE-1; DECISION U; DECISION W (b)/(d); DECISION AA; Rule 1 |
| `config/worker.php` → `processing.interval_seconds` + `core/Workers/ProcessingCronRegistrar` | Shipped cadence is **20** seconds, not 60, and `ProcessingCronRegistrar::DEFAULT_INTERVAL_SECONDS` **must track it** (the code fallback may not contradict the shipped config). Worst-case sync latency ≈ interval + cycle ≈ **20.1s** (≈26–29s at a saturated 200-batch) against the PRD **<30s** SLA; the pipeline itself is 0.06s. Changing the interval needs **no migration and no re-scheduling** — `wp_reschedule_event()` resolves it by schedule name. **The interval alone does not deliver the SLA:** `spawn_cron()` enforces `WP_CRON_LOCK_TIMEOUT` (60s core default), so the SLA additionally **requires** an out-of-band trigger running `wp cron event run --due-now` at **≤20s** (a trigger, not a daemon — ADR-054 §5/§23); without it the platform still runs zero-config (Principle 8) and only the SLA is unmet. **Guarded by** `ProcessingCycleIntegrationTest::test_end_to_end_sync_latency_through_one_cycle`, which reads the interval from the shipped config (never restates it) and asserts the worst case < 30s. `spawn_cron()` stays confined to `core/Onboarding/WorkerCronSpawner` — **never** called from the capture path. **No schema, migration, persistence, contract, handle (L Ruling 0) or `pg_*` wrapper (E) change; batch sizes, `cycle_time_budget_seconds` and `heartbeat.offline_after_seconds` untouched.** | DECISION AB (v1.34); FLAG-P1BS0-1; PRD §Performance; ADR-054 §4/§5/§23; Principle 8 |
| `commerce.*` schema (Phase 2, DECISION AG) | **The Doc 3 §13–18 commerce DDL is amended, not adopted verbatim.** Type canon applies platform-wide: all timestamps `TIMESTAMPTZ` (never bare `TIMESTAMP`), checksums the frozen `VARCHAR(64)` sha256 type, UUID identity per the platform canon. **Semantic columns do NOT clone Content's table shape** — a pure join table needs no checksum for symmetry, a never-independently-tombstoned table needs no `deleted_at`, and `meta_jsonb` appears only where the delivery contract actually carries extensible metadata (AG-11). Money columns stay `NUMERIC` with **no imposed `(precision, scale)`**, exactness enforced at the application boundary by deterministic decimal-string normalization **before** checksum construction (Requirement C). The per-product `currency` column is **superseded** — currency is store-level Commerce configuration (Requirement C′). | DECISION AG (AG-11, Requirements C / C′) |
| `commerce.products` / `commerce.product_variations` / `commerce.inventory` / `commerce.attributes` — cross-aggregate references | **No cross-aggregate FOREIGN KEY may be created** for `variation → product`, `inventory → product` or `attribute term → attribute`: parent and child are produced by separate events, and under at-least-once, non-FIFO, replay and overlapping-cycle processing a child may legitimately arrive first — an FK would turn valid out-of-order synchronization into a DLQ failure. Use typed identifiers, indexes, reconciliation integrity and query-side joins (the `content.posts.featured_media_id → content.media` precedent). **Doc 3 §18's mandatory-FK statement is superseded for asynchronously projected Commerce aggregates.** Primary keys, uniqueness, check constraints and structures created/owned atomically inside the SAME adapter transaction are unaffected. | DECISION AG (AG-7); ADR-013 as amended |
| `commerce.inventory` (stock ownership) | **Single owner of stock facts** — `stock_status` is **removed from the `commerce.products` projection design** (Doc 3 §13 placed it on both, giving one WordPress fact two projections, two checksums and two write-suppress decisions that can disagree). Product list/filter/resource queries **JOIN** inventory at read time; stock is never copied back to avoid a join. Inventory ownership is **aggregate-aware** — an explicit owner identity (meaning equivalent to `owner_type` + `owner_id`) so a **product OR a product variation** can own a row; **no `commerce.product_inventory` / `commerce.variation_inventory` split**, and no variation stock duplicated onto the product row. A variation that inherits parent-managed stock gets **no** invented duplicate inventory fact. Doc 3 §15's Product-only inventory relationship is superseded. **Read model:** a missing inventory row means *state unavailable*, **never out of stock**, and must never drop a valid product/variation from a listing (LEFT JOIN or equivalent tolerant composition); `in_stock=true` must not match unknown inventory state. | DECISION AG (AG-8, AG-14) |
| `commerce.taxonomies` + `commerce.entity_taxonomies` (shared Commerce taxonomy projection) | **One shared taxonomy projection for the Commerce domain**, terms told apart by `taxonomy_type` — `product_cat` and every `pa_*` attribute taxonomy live in `commerce.taxonomies`, with `commerce.entity_taxonomies` the generic entity↔term join (the `content.*` shape, DECISION AA). Doc 3 §16–17's `commerce.categories`, `commerce.attribute_terms` and `commerce.product_categories` are **superseded where they merely represent taxonomy terms and relationships**; **`commerce.attributes` remains a separate projection** because global attribute DEFINITIONS carry domain semantics (name, slug, type, ordering, archive behaviour) and are not terms. **No second `commerce.attribute_terms` table** for rows already represented as `pa_*` terms. **`(taxonomy_type, slug)` is NOT the durable identity of a term and gets NO UNIQUE constraint** unless source verification proves it valid for the supported taxonomy semantics — a normal lookup index only (DECISION AA reached the same conclusion for Content). Out of Phase 2 scope: `product_tag`, and local/custom non-global product attributes. | DECISION AG (AG-9); DECISION AA extended |
| Commerce hierarchical category addressing | **No `path` / `uri` / `permalink` / derived-ancestor column on `commerce.taxonomies`, and no migration may add one.** If P2-S3 verification proves `product_cat` leaf slugs can repeat under different parents, addressing follows **DECISION AD/AE/AF**: full ancestor path resolved **at read time** via the existing `HierarchicalQueryProviderInterface` capability — no second hierarchy architecture for Commerce, no stored path (a parent rename would leave every descendant stale with no event to repair it), no arbitrary leaf-slug tie-break, and no WordPress term ID as the required public filtering key. **Unlike pages, Commerce ships exact-path-only from day one** — DECISION AF already retired the one-segment leaf fallback, so there is no legacy arm to preserve. DECISION AE's unprojected-ancestor limit applies equally. | DECISION AG (AG-9, Part 5); DECISION AD/AE/AF |
| Commerce product media references | **No Commerce attachment projection.** `content.media` remains the single projection of WordPress attachment state; Commerce stores source attachment **references** only. **No `Commerce → Content` PHP import and no `Commerce SQL → content.media` hidden dependency** — expanded media, if the contract requires it, arrives through a narrow Core-owned capability contract implemented by Content with **BULK** resolution (never one lookup per product or gallery image). **Commerce product synchronization must still succeed when that capability is absent** — optional capability composition, no fatal container dependency. | DECISION AG (AG-10); Rule 5 |
| `ReplayEmitterInterface` + `WpReconciliationSourceInterface` → Core-owned registries | The single-binding shape is **retired**: `Container::singleton()` is last-writer-wins with no error, so a second module binding `ReplayEmitterInterface` would **silently delete** the first module's emitter, and `WpReconciliationSourceInterface` was consumed as a **scalar** with nowhere for a second source to go. Replaced by Core-owned registries keyed by **aggregate type**, with **explicit registration**, **duplicate registration throwing immediately**, and **no silent skip** of an aggregate that has no emitter/projection capability. **Core — not a module — constructs `ReplayService` and `ReconciliationService`.** Backfill enumerates **all ACTIVE registered** sources. Commerce inactive ⇒ nothing registered ⇒ Content-only replay/reconciliation/backfill continues unchanged. | DECISION AG (AG-2); extends DECISION AC |
| `ReconciliationService::PROJECTION` + `BackfillReader::PROJECTION` + `BackfillProgress::TYPES` → `ProjectionRegistryInterface` | The three hardcoded `content.*` maps DECISION AC widened by hand become a **module-registered projection registry** (`ProjectionDescriptor` keyed by aggregate type) — **core must not know every domain table in the platform**. The descriptor carries only infrastructure metadata (aggregate type, schema/table identifier, source identity column, projection read metadata) and is **not** a domain model. Schema/table/column identifiers are supplied **only by trusted module registration, never from API or user input**, and are **validated against strict identifier rules at registration** — load-bearing, because `BackfillReader` interpolates the table name directly into SQL, which is safe today only while the values are fixed core literals. Duplicate descriptors fail loudly; unknown aggregate types are never silently skipped. **No arbitrary-SQL escape hatch, no fifth PG handle, zero new persistence.** | DECISION AG (AG-3) |
| Queue partition routing (`system.queue_jobs.queue_name`) | **No DDL change** — the `commerce` partition already exists in `config/queue.php`, `DatabaseQueueProvider::VALID_PARTITIONS` and the maintenance sweep, and `queue_name` carries no DB-level constraint. What changes is that the hardcoded `'content'` literals in `EventDispatcher`, `EventWorkerStrategy` and `DispatcherWorkerStrategy::consumesPartitions()` are replaced by **one explicit Core-owned domain→partition routing seam** driven by the OPEN-1 domain prefix — the routing ADR DECISION L (v1.12) deferred *"to a future ADR when a second domain is introduced"*. Duplicate **domain routing-key** registration fails loudly; two domains sharing one physical partition is **not** prohibited. **`processing.projection_batch_size` remains the TOTAL projection budget for one cycle, shared across active partitions by a deterministic fair/round-robin allocation — never multiplied per domain**; no weighted scheduling or priority queues without a new ruling. | DECISION AG (AG-4); completes DECISION L (v1.12) |
| `core/Contracts/FilterSet.php` → `QueryFilterInterface` | The concrete `final` `FilterSet` — whose docblock claims modules extend it while four of its seven fields (`categorySlug`, `tagSlug`, `publishedAfter`, `status`) are content-domain — is **superseded as the delivery filter contract**. Core owns a small domain-neutral `QueryFilterInterface`; modules own strongly typed DTOs (`ContentFilterSet`, `ProductFilterSet` with price range, stock status, SKU, product category, attribute filters). `QueryProviderInterface` accepts the Core contract, and each provider **explicitly rejects** an incompatible domain filter rather than silently reading the wrong DTO. **Not** an untyped `array<string,mixed>` bag; **no `QueryFilterRegistry`**. Existing Content filtering behaviour stays functionally compatible. | DECISION AG (AG-5); DECISION F preserved |
| `system.module_versions` (finally written) | The table is created (`0009_create_system_module_versions`) and **read** by `OperationsQueryReader`, but **nothing has ever written it**. A writer is added: **idempotent**, and firing **only after** a module's migration batch successfully reaches its **declared module schema version** (`module.json` already declares `schema_version`). Historical rows are **never deleted on rollback**. Plugin version, code version and schema version are not interchangeable. **`system.schema_versions` remains the AUTHORITATIVE migration-state record** — onboarding migration readiness continues to read active migration state, and Operations must not infer migration health from the latest `module_versions` row. | DECISION AG (AG-6) |
| Module lifecycle state (WordPress option — **no PostgreSQL table**) | Core distinguishes **DISCOVERED / AVAILABLE / READY(ACTIVE)**, with data bootstrap (`pending`/`complete`) tracked **separately**. A module is **never runtime-ready merely because `isAvailable()` returns true** — required module migrations must have applied first; on migration failure the module stays not-active, every other module keeps operating, and no endpoint or projection consumer runs against missing schema. Bootstrap state lives in a **module-keyed WordPress option** (`hsp_module_bootstrap_state`, or one option per module if that is what delivers lost-update safety) — updating one module's state must **never** erase a sibling's. This is **lifecycle state: no PostgreSQL table is authorised**, and `system.module_versions` must **not** be used for it. A newly ready module with no completed bootstrap schedules a **module-scoped `ReconciliationService` re-emission** — no direct WP→PG copy, no second repair path, no in-request drain, no reset of global onboarding, Content staying online — so **WooCommerce installed after HSP converges the existing catalog with no reactivation, no manual migrate and no manual reconcile**. On a fresh install where global onboarding already covers all active modules, convergence marks them complete with **no duplicate backfill**. | DECISION AG (AG-12); ADR-054 Principle 8 |
| Commerce product-type scope + lifecycle coverage | Phase 2 projects **`simple` and `variable` only**. An unsupported type (`grouped`, `external`, custom) is **normal out-of-scope source, not a processing failure**: no repeated retry, no DLQ merely for being unsupported, no blocked reconciliation or bootstrap convergence, and **excluded from backfill/reconciliation expected counts**. Types are never coerced into `simple` and never partially projected. **All four transitions are mandatory coverage**, and `simple`/`variable` → unsupported must **tombstone** the previously public projection through the existing DECISION I / T / U path rather than leaving it visible forever. More broadly, **every** Phase 2 aggregate proves `create → update → delete/leave-supported-scope → tombstone → replay → reconciliation`, with relationship projections converging through the **same** re-emission mechanism — **no aggregate-specific or relationship-specific repair path**. | DECISION AG (AG-13, Part 5) |
