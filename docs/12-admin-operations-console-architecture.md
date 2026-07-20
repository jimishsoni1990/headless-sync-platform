# Document 12 --- Admin Operations Console Architecture

**Version:** 1.0\
**Status:** Accepted (as amended by DECISION V — see ARCHITECTURE_DECISIONS.md)\
**Phase:** Architecture

------------------------------------------------------------------------

# 1. Purpose

The HSP Operations Console is a Core infrastructure subsystem providing
operational visibility, diagnostics, developer tooling, and platform
management. It is not merely a collection of WordPress admin pages.

The console follows the established architectural principles:

-   Core owns infrastructure.
-   Modules own implementations.
-   Registry-driven discovery.
-   Provider-driven runtime data.
-   No module-to-module dependencies.
-   Future module extraction remains possible.

------------------------------------------------------------------------

# 2. Roadmap Alignment

Document 11 is updated to include:

-   Phase 0 --- Foundation
-   Phase 1A --- Blog MVP
-   **Phase 1A -- Expanded --- Operations Console & Developer
    Experience**
-   Phase 1B --- Content Enhancement
-   Phase 2 --- WooCommerce

------------------------------------------------------------------------

# 3. Core Operations Module

``` text
Core/
└── Operations/
    ├── Admin/
    ├── Assets/
    ├── Contracts/
    ├── Registries/
    ├── Providers/
    ├── Services/
    ├── Diagnostics/
    └── UI/
```

------------------------------------------------------------------------

# 4. Operations Registry

The Operations Registry coordinates platform extension points.

Responsibilities include:

-   Page Registry
-   Navigation Registry
-   Widget Registry
-   Action Registry
-   Asset Registry

Core discovers capabilities through registries. Nothing is hardcoded.

------------------------------------------------------------------------

# 5. Registries vs Providers

The platform explicitly distinguishes discovery from runtime data.

## Registries

Registries discover capabilities.

Examples:

-   Page Registry
-   Widget Registry
-   Endpoint Registry
-   Action Registry

## Providers

Providers supply runtime information.

Examples:

-   Health Provider
-   Metrics Provider
-   Worker Status Provider
-   Queue Status Provider
-   Endpoint Provider

This distinction mirrors the architecture used throughout the platform.

------------------------------------------------------------------------

# 6. Navigation

## MVP

-   Operations
-   API Playground

## Future

``` text
HSP
├── Dashboard
├── Operations
├── API Playground
├── Health
├── Replay
├── Reconciliation
├── Logs
├── Settings
├── Module Management
└── Audit
```

Dashboard is reserved as the future landing page.

------------------------------------------------------------------------

# 7. Widget Architecture

Core owns widget infrastructure.

Modules provide widget implementations.

Widgets never poll independently.

------------------------------------------------------------------------

# 8. Refresh Coordinator

``` text
Operations Console
        ↓
Refresh Coordinator
        ↓
Registered Providers
        ↓
Console State Store
        ↓
Widgets
```

The Refresh Coordinator centralizes refresh scheduling and avoids
duplicated polling.

------------------------------------------------------------------------

# 9. Console State Store

The Console State Store is the single source of truth for UI state.

Responsibilities:

-   Shared operational state
-   Provider cache
-   Event updates
-   Refresh coordination

------------------------------------------------------------------------

# 10. Operations Service Layer

The UI never communicates directly with infrastructure.

``` text
React UI
      ↓
Operations Services
      ↓
Provider Interfaces
      ↓
Infrastructure
```

The Operations Services layer orchestrates provider interactions and
shields the UI from implementation details.

------------------------------------------------------------------------

# 11. Diagnostics

Diagnostics Providers contribute:

-   Health
-   Metrics
-   Configuration Validation
-   Environment Validation
-   Version Information
-   Warnings
-   Recommendations

Diagnostic Reports include severity:

-   OK
-   Info
-   Warning
-   Error
-   Critical

Historical health storage is intentionally out of scope for Phase 1A --
Expanded.

The Operations Console reports current operational state only.

------------------------------------------------------------------------

# 12. Metrics

First-class metrics include:

-   Queue Depth
-   Worker Count
-   Worker Heartbeat
-   Retry Count
-   Failed Jobs
-   Processing Rate
-   Replay Progress
-   Reconciliation Status
-   API Availability

------------------------------------------------------------------------

# 13. System Information

Displays:

-   Platform Version
-   WordPress Version
-   PHP Version
-   PostgreSQL Version
-   Installed Modules
-   Module Versions
-   Queue Provider
-   Migration Version
-   Worker Information

------------------------------------------------------------------------

# 14. Module Inspector

Each module may expose:

-   Version
-   Events
-   Endpoints
-   Transformers
-   Adapters
-   Workers
-   Diagnostics Providers
-   Metrics Providers
-   Operational Actions

------------------------------------------------------------------------

# 15. API Playground

Subsystems:

-   Endpoint Explorer
-   Request Builder
-   Request Execution Service
-   Response Viewer

## Endpoint Metadata

Each endpoint registers:

-   Resource
-   Category
-   Display Category
-   HTTP Methods
-   Version
-   Description
-   Authentication Requirements
-   Response Schema
-   Example Request
-   Example Response
-   Deprecation Status
-   Tags
-   Module Owner

Display Categories include:

-   Content
-   Commerce
-   System
-   Health
-   Operations
-   Search

In scope (per ADR-055, 2026-07-20 — see ARCHITECTURE_DECISIONS.md):

-   **OpenAPI Generation** — an **OpenAPI 3.1** document **generated from this
    endpoint metadata registry** (never hand-authored, never reflection/scan-derived
    from WP REST routes), served at `GET /hsp/v1/openapi.json` (versioned per Doc 9 §7).
    The endpoint metadata above is additively enriched (parameters, request/response
    schema, auth requirement, cursor-pagination envelope, deprecation status, version,
    module owner) to supply the generator. Request-time and stateless: no persistence,
    no PG read, no new connection handle, and not part of the ADR-054 processing cycle.
    A CI drift guard fails the build if any registered `hsp/v1` route lacks a complete
    metadata entry or the generated document fails OpenAPI 3.1 meta-schema validation.
    Built in session **OAPI-S1**.

Future capabilities:

-   Authentication
-   Multiple Environments
-   Request History
-   Saved Requests
-   Import / Export
-   GraphQL Explorer

> **§15 amendment log — 2026-07-20 (ADR-055, session OAPI-S1):** OpenAPI Generation
> moved from *Future capabilities* to *in scope*, generated from this endpoint metadata
> registry (single source of truth; auto-updating). Authority: ADR-055; Doc 9
> §6/§7/§13/§22/§26; Rule 5. No other §15 content changed.

------------------------------------------------------------------------

# 16. Operational Events

Where practical, the console consumes platform events rather than
relying exclusively on polling.

Examples include:

-   Worker Started
-   Worker Stopped
-   Queue Backlog Changed
-   Replay Completed
-   Module Registered

------------------------------------------------------------------------

# 17. Operational Actions

Operational Actions are registry-driven.

Examples:

-   Replay
-   Reconciliation
-   Flush Queue
-   Restart Workers
-   Validate Configuration

Actions execute only through dedicated Operations Services.

The UI must never invoke infrastructure classes directly.

Each action registers:

-   Required Capability
-   Confirmation Required
-   Destructive Flag

------------------------------------------------------------------------

# 18. Notification Providers

Notification Providers contribute transient operational notifications.

Examples:

-   Queue Threshold Exceeded
-   Worker Offline
-   Replay Completed
-   Migration Available
-   Configuration Warning

------------------------------------------------------------------------

# 19. Extensibility

Core owns:

-   Infrastructure
-   Registries
-   Services
-   Aggregation
-   Rendering

Modules own:

-   Pages
-   Widgets
-   Diagnostics
-   Metrics
-   Actions
-   Endpoints

------------------------------------------------------------------------

# 20. ADRs

## ADR-047 --- Operations Console as Core Infrastructure

Accepted.

## ADR-048 --- Registry-Driven Administration

Accepted.

## ADR-049 --- Unified Diagnostics

Accepted.

## ADR-050 --- Delivery API Validation

Accepted.

## ADR-051 --- Operational Actions

Accepted.

## ADR-052 --- Registry-Driven Operations Console

**Decision**

All Operations Console capabilities are discovered through registries
and provider contracts. Core never hardcodes pages, widgets, endpoints,
diagnostics, metrics or operational actions.

## ADR-053 --- Operations Console is Read-Only by Default

**Status:** Accepted

**Decision**

The Operations Console shall primarily provide operational visibility.

State-changing functionality shall only be implemented as registered
Operational Actions protected by capability checks.

**Consequence**

The console remains observational by default while administrative
operations remain explicit, discoverable and auditable.

------------------------------------------------------------------------

# 21. Summary

The Operations Console is a first-class platform subsystem that is
infrastructure-owned, registry-driven, provider-driven, event-aware,
module-extensible and future-proof.
