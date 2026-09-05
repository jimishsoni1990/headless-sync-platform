# Development Roadmap & Platform Evolution Strategy

**Project:** Headless Sync Platform (HSP)
**Version:** 1.2
**Status:** Approved
**State:** Frozen

**Amended by ADR-054 (2026-07-17; applied 2026-09-05).** Background execution is WP-Cron-only
(Document 8 v2.0 / ADR-054): no daemons, supervisors, or CLI workers. Two wordings are corrected
below — the Scalability Validation criterion "Multiple Worker Processes" (now overlapping cron
cycles / concurrent claimants) and the Operations Console "Restart Workers" note. Roadmap phases
and gate criteria are otherwise unchanged.

**Amended by DECISION Y (2026-09-05; applied 2026-09-05).** **PostgreSQL Search is removed from
the §7 Phase 1B deliverables** and defers to **Phase 5 — Search Expansion (§14)**, which already
states PostgreSQL Search remains supported; the §7 "Search Queries" validation item moves with
it. The **§17 Search Roadmap ordering is unchanged** — PostgreSQL Search still precedes the
provider contract and OpenSearch/Typesense; only its phase placement moved. Superseded §7
entries are retained under a banner, not deleted. Nothing else in this document changes.

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture
* Document 5 — Event Architecture & Contract Design
* Document 6 — Transformer Architecture & Canonical Model Design
* Document 7 — Adapter Architecture & Delivery Projection Design
* Document 8 v2.0 — Background Processing & Execution Architecture
* Document 9 — Delivery API & Consumption Architecture
* Document 10 — Operations, Deployment & Runtime Architecture

---

# 1. Purpose

This document defines the implementation roadmap and long-term evolution strategy for the Headless Sync Platform.

It establishes:

* Delivery sequencing
* Phase structure
* MVP scope
* Validation milestones
* Operational readiness gates
* Future expansion strategy
* Technical debt policy

This document is the authoritative guide for platform implementation planning.

---

# 2. Guiding Principles

## Principle 1

Prove Architecture Before Expanding Surface Area.

---

## Principle 2

Deliver Vertical Slices.

---

## Principle 3

Reliability Before Features.

---

## Principle 4

Operational Readiness Before Scale.

---

## Principle 5

Avoid Premature Infrastructure Complexity.

---

## Principle 6

Protect Architectural Boundaries.

---

# 3. Delivery Strategy

## Decision

Build complete vertical slices.

---

Preferred:

```text
Feature
      ↓
Event
      ↓
Queue
      ↓
Worker
      ↓
Transformer
      ↓
PostgreSQL
      ↓
API
      ↓
Consumer
```

---

Avoid:

```text
Build All Infrastructure
      ↓
Build All Features
      ↓
Integrate Later
```

---

# 4. Implementation Phases

```text
Phase 0
Foundation

Phase 1A
Blog MVP

Phase 1A — Expanded
Operations Console & Developer Experience

Phase 1B
Content Enhancement

Architecture Validation Gate

Phase 2
WooCommerce Catalog

Phase 3
Operational Hardening

Phase 4
API Expansion

Phase 5
Search Expansion

Phase 6
Future Domain Modules
```

---

# 5. Phase 0 — Foundation

## Objective

Establish platform infrastructure.

---

## Deliverables

### Core Platform

```text
Service Container

Module Registry

Module Lifecycle

Configuration System

Migration Engine
```

---

### Contracts

```text
Event Contracts

Canonical Contracts

Adapter Contracts

Queue Contracts

Worker Contracts

API Contracts
```

---

### Infrastructure

```text
Outbox

Database Queue Provider

Worker Engine

Event Registry

Adapter Registry
```

---

## Success Criteria

```text
Platform Boots

Modules Register

Infrastructure Tests Pass
```

---

# 6. Phase 1A — Blog MVP

## Objective

Validate the complete synchronization architecture using the smallest possible domain.

---

## Content Scope

```text
Pages

Posts

Categories
```

---

## Infrastructure Scope

```text
Outbox

Database Queue

Workers

PostgreSQL Delivery Store

REST API
```

---

## Frontend Validation

```text
Blog Listing

Single Post

Static Pages
```

---

## Explicitly Excluded

```text
ACF

Flexible Content

Tags

Media

Relationships

WooCommerce

Search
```

---

## Success Criteria

Validated pipeline:

```text
WordPress
      ↓
Event
      ↓
Outbox
      ↓
Queue
      ↓
Worker
      ↓
Transformer
      ↓
PostgreSQL
      ↓
API
      ↓
Next.js
```

---

The architecture must operate reliably under real usage.

---

# 6b. Phase 1A — Expanded — Operations Console & Developer Experience

> Ratified by **DECISION V** (ARCHITECTURE_DECISIONS.md v1.20, 2026-07-15), which adopts
> Doc 12 (Admin Operations Console) as amended. Where this section and Doc 12 differ from
> DECISION V, DECISION V wins.

## Objective

Give operators and developers a first-class, **observability-and-diagnostics** surface over the
already-shipped pipeline — worker health, queue/DLQ state, diagnostics, and a delivery-API
playground — plus the two re-emission actions (Replay, Reconcile) as thin, auditable delegators.
The console is **not an operational control plane**: restarting services/containers, managing OS
processes, and infrastructure orchestration are permanently outside the plugin's scope
(DECISION V (j)).

---

## Deliverables

```text
Operations Console (core/Operations/) — server-rendered PHP + minimal vanilla JS

Operations dashboard (worker status, heartbeat, queue depth, DLQ depth — derived, no persistence)

API Playground (Endpoint Explorer / Request Builder / Execution / Response Viewer over hsp/v1)

Diagnostics + Metrics providers (current-state only, derived on-demand per DECISION Q)

Replay + Reconcile actions (thin delegators to ReplayService / ReconciliationService)
```

---

## Scope Constraints (binding — DECISION V)

```text
Stack: server-rendered PHP + WP native admin UI + minimal vanilla JS — NO node/npm/bundler

Coding standard: PSR-12 platform-wide; WPCS security rules at WordPress entry points only

Metrics: derived on-demand — zero new persistence (DECISION Q)

Actions: Replay + Reconcile ONLY — thin delegators, no second repair path (write-spy proof)

Flush Queue: REMOVED (destructive; violates never-lose-a-sync)

Restart Workers: NONE — nothing to restart under ADR-054; status/heartbeat/runbook links only

PG reads: reuse the delivery DatabaseConnectionInterface — no fifth handle

Contracts: core/Contracts/Operations/ (Rule 5 holds verbatim)
```

---

## Success Criteria

The console renders in wp-admin over the live pipeline; all metrics are derived on-demand with no
new persistence; Replay and Reconcile actions route exclusively through the ratified re-emission
services (zero direct projection writes, proven by write-spy); no destructive or
infrastructure-control action exists.

---

# 7. Phase 1B — Content Enhancement

> **Amended by DECISION Y (2026-09-05; applied 2026-09-05).** **PostgreSQL Search is NOT a
> Phase 1B deliverable** — it defers to **Phase 5 — Search Expansion** (§14), which already
> states PostgreSQL Search remains supported. The **§17 Search Roadmap ordering is unchanged**
> (PostgreSQL Search still precedes the provider contract and OpenSearch/Typesense); only its
> phase placement moved. The superseded entries are retained below under this banner rather
> than deleted. Phase 1B is: **Featured Images, Media Synchronization, Tags, Basic ACF,
> Pagination.** Authority: `docs/ARCHITECTURE_DECISIONS.md` DECISION Y (v1.32) — that document
> wins on conflict.

## Objective

Expand content capabilities without introducing commerce complexity.

---

## Deliverables

```text
Featured Images

Media Synchronization

Tags

Basic ACF

Pagination
```

**Superseded by DECISION Y — deferred to Phase 5 (§14), retained as history:**

```text
PostgreSQL Search
```

---

## Additional Validation

```text
Structured Content

Media Relationships

Pagination Workflows
```

**Superseded by DECISION Y — validated in Phase 5 (§14), retained as history:**

```text
Search Queries
```

---

## Success Criteria

Content enhancements function without architectural redesign.

---

# 8. Early Operational Baseline

## Decision

Operational capabilities arrive before WooCommerce.

---

## Required Deliverables

```text
Dead Letter Queue

Basic Replay

Worker Health Monitoring

Basic Metrics
```

---

## Reasoning

The first synchronization failures will occur during content development.

Visibility must exist before commerce complexity arrives.

---

# 9. Architecture Validation Gate

## Mandatory Gate

Must be completed before WooCommerce begins.

---

## Reliability Validation

```text
Successful Sync Processing

Replay Success

DLQ Recovery
```

---

## Scalability Validation

> **AMENDED BY ADR-054 §4.** Concurrency is proven by **overlapping cron cycles / concurrent
> claimants** under `FOR UPDATE SKIP LOCKED`, not by multiple supervised worker processes. GATE-S2
> already satisfies this with genuinely concurrent PostgreSQL sessions — no re-gate is required.

```text
Concurrent Claimants (overlapping processing cycles)

Queue Growth Handling

Replay Handling
```

---

## Operability Validation

```text
Health Visibility

Failure Diagnostics

Reconciliation Execution
```

---

## Extensibility Validation

```text
New Content Fields

New Projection Fields

New API Resources
```

without architectural redesign.

---

# 10. Gate Failure Rule

If the validation gate fails:

```text
Do Not Start WooCommerce
```

---

Architectural weaknesses must be resolved first.

---

# 11. Phase 2 — WooCommerce Catalog

## Objective

Introduce commerce support.

---

## Deliverables

```text
Products

Product Variations

Categories

Attributes

Attribute Terms

Inventory
```

---

## Validation Areas

```text
Variation Synchronization

Inventory Updates

Commerce Filtering

Category Queries
```

---

## Explicitly Excluded

```text
Orders

Customers
```

---

# 12. Phase 3 — Operational Hardening

## Objective

Strengthen production readiness.

---

## Deliverables

```text
Advanced Replay

Advanced Reconciliation

Improved Monitoring

Alerting

Operational Runbooks
```

---

## Success Criteria

Recovery workflows proven in staging environments.

---

# 13. Phase 4 — API Expansion

## Objective

Expand consumption capabilities.

---

## Deliverables

```text
Composition APIs

Advanced Filtering

Caching Enhancements

Resource Versioning Improvements
```

---

## Validation

```text
Homepage Composition

Product Composition

Performance Testing
```

---

# 14. Phase 5 — Search Expansion

## Objective

Introduce provider-based search.

---

## Phase 5A

```text
Search Provider Contract
```

---

## Phase 5B

Optional providers:

```text
OpenSearch

Typesense
```

---

## Rule

PostgreSQL Search remains supported.

---

# 15. Phase 6 — Future Domain Modules

## Candidate Modules

```text
Membership

LMS

Directory

Booking

Events

Custom Business Applications
```

---

## Rule

New domains must be implemented as modules.

---

Core modifications are prohibited unless infrastructure changes are required.

---

# 16. Queue Provider Roadmap

## Phase 1

```text
Database Queue Provider
```

only.

---

## Future Providers

```text
Redis

RabbitMQ

Kafka

Amazon SQS
```

---

## Rule

New providers must use existing queue contracts.

---

# 17. Search Roadmap

## Phase 1

```text
PostgreSQL Search
```

---

## Future

```text
Search Provider Contract
        ↓
OpenSearch
        ↓
Typesense
```

---

# 18. API Roadmap

## Phase 1

```text
REST
```

---

## Future

```text
GraphQL

gRPC

SDKs
```

---

Transport-agnostic architecture remains unchanged.

---

# 19. Module Extraction Strategy

## Decision

Architectural extraction support.

---

Physical packaging remains:

```text
Single Plugin
```

---

Future extraction remains possible because:

```text
Modules Depend On Core Contracts
```

only.

---

# 20. Administration UI Strategy

## Decision

Minimal Operational UI

---

Supported:

```text
Queue Status

Worker Status

Replay Trigger

Reconciliation Trigger

Health Checks
```

---

Avoid:

```text
Large Monitoring Dashboards

Infrastructure Management Consoles
```

---

# 21. Testing Roadmap

## Priority Order

### Tier 1

```text
Transformers

Canonical Models
```

---

### Tier 2

```text
Adapters

Event Processing
```

---

### Tier 3

```text
Workers

Queue Providers
```

---

### Tier 4

```text
API Layer
```

---

### Tier 5

```text
Admin UI
```

---

# 22. Technical Debt Policy

## Architectural Rule

No implementation shortcut may violate:

```text
Module Boundaries

Event Flow

Canonical Models

Adapter Separation

Queue Contracts

Core Dependency Rules
```

---

## Consequence

Short-term speed must never create long-term architectural debt.

---

# 23. Evolution Strategy

Platform evolution should occur through:

```text
New Modules

New Adapters

New Providers

New Delivery Targets
```

---

Avoid:

```text
Core Business Logic Expansion
```

---

# 24. Success Metrics

The roadmap is considered successful when:

```text
Content Module Proven

WooCommerce Proven

Replay Proven

Reconciliation Proven

Operational Recovery Proven

Additional Modules Possible Without Redesign
```

---

# 25. Long-Term Vision

The Headless Sync Platform becomes:

```text
WordPress
      ↓
Synchronization Platform
      ↓
Delivery Database
      ↓
Consumer Systems
```

where WordPress remains:

```text
Editorial Source Of Truth
```

and delivery systems remain:

```text
Optimized Consumer Read Models
```

---

# 26. Approval Checklist

* [x] Delivery strategy approved
* [x] Phase structure approved
* [x] Blog MVP approved
* [x] Content enhancement phase approved
* [x] Early operational baseline approved
* [x] Architecture validation gate approved
* [x] WooCommerce roadmap approved
* [x] API roadmap approved
* [x] Search roadmap approved
* [x] Queue provider roadmap approved
* [x] Module roadmap approved
* [x] Testing roadmap approved
* [x] Technical debt policy approved
* [x] Evolution strategy approved

---

# Approval Status

**Version:** 1.1

**Status:** Approved

**State:** Frozen

This document is the authoritative Development Roadmap & Platform Evolution Strategy specification for the Headless Sync Platform.
