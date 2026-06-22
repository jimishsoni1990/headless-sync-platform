# Adapter Architecture & Delivery Projection Design

**Project:** Headless Sync Platform (HSP)
**Version:** 1.0
**Status:** Approved
**State:** Frozen

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture
* Document 5 — Event Architecture & Contract Design
* Document 6 — Transformer Architecture & Canonical Model Design

---

# 1. Purpose

This document defines the adapter architecture responsible for converting Canonical Models into delivery-specific projections.

It establishes:

* Adapter ownership
* Projection ownership
* Delivery target architecture
* Projection versioning
* Adapter registration
* Multi-target delivery
* Projection lifecycle management
* Adapter failure behavior
* Projection compatibility strategy

The adapter layer forms the boundary between:

Business-Domain Meaning

and

Delivery-Specific Representation.

---

# 2. Architectural Principles

## Principle 1

Canonical Models Represent Business Meaning.

---

## Principle 2

Adapters Represent Delivery Concerns.

---

## Principle 3

Delivery Projections Are Storage-Specific.

---

## Principle 4

Adapters Must Not Contain Business Logic.

---

## Principle 5

Canonical Model Evolution And Projection Evolution Are Independent.

---

## Principle 6

Modules Own Delivery Implementations.

---

## Principle 7

Core Owns Contracts.

---

# 3. High-Level Architecture

```text
Canonical Model
        ↓
Adapter
        ↓
Delivery Projection
        ↓
Delivery Target
```

Examples:

```text
ProductCanonicalModel
        ↓
ProductPostgresAdapter
        ↓
ProductProjection
        ↓
PostgreSQL
```

```text
ProductCanonicalModel
        ↓
ProductSearchAdapter
        ↓
SearchDocumentProjection
        ↓
OpenSearch
```

---

# 4. Adapter Ownership

## ADR-032

### Status

Accepted

### Decision

Core owns adapter contracts.

Modules own adapter implementations.

---

## Core Responsibilities

Core owns:

* AdapterInterface
* ProjectionInterface
* DeliveryTargetInterface
* AdapterRegistryInterface

Core does not own adapter implementations.

---

## Module Responsibilities

Modules own:

* Adapters
* Projection Builders
* Delivery Mappings
* Projection Definitions

---

### Examples

```text
Modules/WooCommerce/Adapters/ProductPostgresAdapter

Modules/WooCommerce/Adapters/ProductSearchAdapter

Modules/Content/Adapters/PagePostgresAdapter
```

---

## Reasoning

Adapters remain part of domain implementation.

This preserves:

* Module independence
* Future extraction capability
* Clear ownership boundaries

---

# 5. Adapter Responsibilities

Adapters convert:

```text
Canonical Model
        ↓
Delivery Projection
```

---

Adapters may:

* Build projections
* Map fields
* Format target-specific structures
* Understand delivery schemas

---

Adapters must not:

* Execute business rules
* Query WordPress
* Create canonical models
* Perform domain validation
* Mutate business meaning

---

# 6. Projection Ownership

## Decision

Projections are module-owned.

---

### Examples

```text
Modules/WooCommerce/Projections/ProductProjection

Modules/WooCommerce/Projections/ProductSearchProjection

Modules/Content/Projections/PageProjection
```

---

## Reasoning

Projections remain domain representations.

Only delivery infrastructure is shared.

---

# 7. Delivery Targets

The architecture supports multiple delivery targets.

---

Examples:

```text
PostgreSQL

OpenSearch

Typesense

Redis Cache

Analytics Store

External API
```

---

## Rule

A single canonical model may be delivered to multiple targets simultaneously.

---

Example:

```text
ProductCanonicalModel
        ↓
ProductPostgresAdapter
        ↓
PostgreSQL

ProductCanonicalModel
        ↓
ProductSearchAdapter
        ↓
OpenSearch

ProductCanonicalModel
        ↓
ProductAnalyticsAdapter
        ↓
Analytics Store
```

---

# 8. Adapter Registration

## Decision

Adapters are registered explicitly.

---

Example:

```php
public function registerAdapters(): void
{
    ...
}
```

---

Modules register:

* Supported canonical models
* Supported projections
* Supported delivery targets
* Adapter mappings

---

No automatic discovery.

No reflection-based registration.

No hidden mapping behavior.

---

# 9. Adapter Registry

Core maintains a centralized registry.

---

Responsibilities:

* Adapter lookup
* Target resolution
* Compatibility validation
* Version tracking

---

The registry contains no business logic.

---

# 10. Projection Construction

Projection creation belongs exclusively to adapters.

---

Architecture:

```text
Canonical Model
        ↓
Adapter
        ↓
Projection
```

---

Transformers must never create:

* Database rows
* Search documents
* Cache entries
* Analytics records

---

Projection generation is an adapter concern.

---

# 11. Adapter Composition

Large adapters should be decomposed.

---

Example:

```text
ProductPostgresAdapter
        ↓
AttributeProjectionBuilder

InventoryProjectionBuilder

MediaProjectionBuilder

CategoryProjectionBuilder
```

---

Benefits:

* Better maintainability
* Smaller units
* Easier testing
* Reusability

---

# 12. Delivery Schema Awareness

## Decision

Adapters are schema-aware.

---

Examples:

```text
commerce.products

commerce.inventory

content.pages

content.media
```

are adapter concerns.

---

Canonical models must not know delivery schemas.

---

# 13. Projection Versioning

## Decision

Delivery projections support versioning.

---

Examples:

```text
ProductProjectionV1

ProductProjectionV2

ProductSearchProjectionV1
```

---

Purpose:

* Storage evolution
* API evolution
* Search evolution
* Backward compatibility

---

# 14. Canonical And Projection Version Independence

## ADR-033

### Status

Accepted

### Decision

Canonical model versions and projection versions evolve independently.

---

### Example

```text
ProductCanonicalModelV2
```

may produce:

```text
ProductProjectionV1
```

or

```text
ProductProjectionV2
```

depending on adapter requirements.

---

### Reasoning

Business-domain evolution and delivery-target evolution are separate concerns.

---

# 15. Canonical Model Compatibility

Adapters target specific canonical contracts.

---

Example:

```text
ProductPostgresAdapterV2
```

supports:

```text
ProductCanonicalModelV2
```

---

Adapters should not support unlimited canonical model versions.

---

# 16. Canonical Evolution Ownership

## ADR-034

### Status

Accepted

### Decision

Modules own canonical model evolution.

Adapters consume canonical contracts.

Adapters do not perform canonical model upgrades.

---

### Approved Flow

```text
Source Model
        ↓
Transformer
        ↓
Canonical V3
        ↓
Adapter
        ↓
Projection
```

---

### Prohibited

```text
Canonical V1
        ↓
Adapter Upgrade Logic
        ↓
Canonical V3
```

---

### Consequence

Domain evolution remains inside modules.

Adapters remain simpler.

---

# 17. Projection Compatibility Strategy

Projection compatibility is adapter-owned.

---

Adapters may temporarily support:

```text
ProjectionV1

ProjectionV2
```

during migration windows.

---

Purpose:

* Safe deployment
* Incremental upgrades
* Backward compatibility

---

# 18. Projection Lifecycle

Projection lifecycle:

```text
Supported
        ↓
Deprecated
        ↓
Removed
```

---

Direct removal is prohibited.

---

# 19. Bulk Operations

## Decision

Adapters support:

```php
persist()
```

and

```php
bulkPersist()
```

---

Purpose:

Single-event processing:

```php
persist()
```

---

Reconciliation:

```php
bulkPersist()
```

---

Full Replay:

```php
bulkPersist()
```

---

# 20. Delete Behavior

## Decision

Delete behavior is projection-specific.

---

Examples:

```text
Soft Delete

Hard Delete

Archive
```

---

Adapters determine behavior based on delivery-target requirements.

---

Document 3 remains authoritative regarding PostgreSQL soft-delete requirements.

---

# 21. Failure Strategy

## Decision

Fail Fast

---

Examples:

```text
Projection Generation Failure

Missing Required Fields

Schema Mapping Failure
```

---

Flow:

```text
Adapter Failure
        ↓
Queue Retry
        ↓
DLQ (if exhausted)
```

---

Adapters must not:

* Partially persist
* Silently ignore failures
* Generate incomplete projections

---

# 22. Multi-Target Delivery

Multiple adapters may execute for a single canonical model.

---

Example:

```text
ProductCanonicalModel
        ↓
PostgreSQL Adapter

Search Adapter

Analytics Adapter
```

---

Each adapter operates independently.

---

Failure of one target must not corrupt another target.

---

# 23. Adapter Testing Strategy

Adapters should be tested independently.

---

Example:

```text
ProductCanonicalModel
        ↓
ProductPostgresAdapter
        ↓
Expected ProductProjection
```

---

Tests should validate:

* Field mappings
* Projection correctness
* Schema compliance
* Version compatibility

---

# 24. Future Delivery Expansion

The architecture must support future delivery targets without modifying:

* Canonical Models
* Transformers
* Source Models

---

New delivery targets require:

```text
New Adapter
+
New Projection
```

only.

---

# 25. Approval Checklist

* [x] Adapter ownership defined
* [x] Core contract ownership defined
* [x] Projection ownership defined
* [x] Adapter registration defined
* [x] Adapter registry defined
* [x] Multiple delivery targets supported
* [x] Adapter composition supported
* [x] Schema awareness defined
* [x] Projection versioning supported
* [x] Canonical/projection version independence defined
* [x] Canonical evolution ownership defined
* [x] Bulk operations supported
* [x] Delete behavior defined
* [x] Fail-fast strategy defined
* [x] Multi-target delivery defined

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Adapter Architecture & Delivery Projection Design specification for the Headless Sync Platform.
