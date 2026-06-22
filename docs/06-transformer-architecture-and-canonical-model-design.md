# Transformer Architecture & Canonical Model Design

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

---

# 1. Purpose

This document defines the transformation architecture used by the Headless Sync Platform.

It establishes:

* Extractor architecture
* Source model architecture
* Validation architecture
* Transformer architecture
* Canonical model architecture
* Canonical model versioning
* Projection ownership
* Relationship handling
* Transformation failure behavior

This document is the authoritative specification governing how WordPress data becomes delivery-ready data.

---

# 2. Architectural Principles

## Principle 1

Transform Before Persist.

---

## Principle 2

Canonical Models Represent Business Meaning.

---

## Principle 3

Canonical Models Are Delivery Agnostic.

---

## Principle 4

Transformers Are Pure.

---

## Principle 5

Modules Own Domain Transformation Logic.

---

## Principle 6

Adapters Own Delivery Projection Creation.

---

## Principle 7

Transformers Never Operate On Raw WordPress Objects.

---

# 3. High-Level Architecture

```text
WordPress Entity
        ↓
Extractor
        ↓
Source Model
        ↓
Validator
        ↓
Transformer
        ↓
Canonical Model
        ↓
Adapter
        ↓
Delivery Projection
```

---

# 4. Architectural Responsibilities

| Component           | Responsibility                                   |
| ------------------- | ------------------------------------------------ |
| Extractor           | Normalize source data                            |
| Source Model        | Stable source representation                     |
| Validator           | Validate source integrity                        |
| Transformer         | Convert source into canonical model              |
| Canonical Model     | Business-domain representation                   |
| Adapter             | Convert canonical model into delivery projection |
| Delivery Projection | Persistence-ready representation                 |

---

# 5. Extractor Architecture

## Decision

Modules own extractors.

---

### Examples

```text
Modules/Content/Extractors/PageExtractor

Modules/WooCommerce/Extractors/ProductExtractor

Modules/Membership/Extractors/MemberExtractor
```

---

## Responsibility

Extractors convert platform-specific entities into Source Models.

Example:

```text
WP_Post
        ↓
PageSourceModel
```

```text
WC_Product
        ↓
ProductSourceModel
```

---

## Extractor Rules

Extractors may:

* Read WordPress entities
* Read WooCommerce entities
* Read ACF structures
* Normalize source data

---

Extractors must not:

* Create canonical models
* Persist data
* Access adapters
* Perform delivery projection logic

---

# 6. Source Models

## Decision

Source Models are module-owned.

---

### Examples

```text
Modules/Content/SourceModels/PageSourceModel

Modules/WooCommerce/SourceModels/ProductSourceModel

Modules/Membership/SourceModels/MemberSourceModel
```

---

## Purpose

Source Models represent normalized source-system data.

They remove direct dependency on:

```text
WP_Post

WP_Term

WC_Product

Raw WordPress Arrays
```

---

## Benefits

* Stable transformer inputs
* Easier testing
* Future importer support
* Platform independence

---

# 7. Source Model Rules

Source Models must:

* Be immutable
* Be strongly typed
* Represent source-system state

---

Source Models must not:

* Contain delivery concerns
* Contain database concerns
* Contain adapter logic

---

# 8. Validation Architecture

## Decision

Validation is a separate layer.

---

Architecture:

```text
Source Model
        ↓
Validator
        ↓
Transformer
```

---

## Responsibility

Validators verify:

* Required fields
* Relationship integrity
* Data consistency
* Structural correctness

---

## Failure Behavior

Validation failure immediately stops processing.

Example:

```text
Invalid Product
        ↓
Validation Failure
        ↓
Queue Retry
```

---

No automatic repair.

No partial transformation.

---

# 9. Transformer Ownership

## Decision

Modules own transformers.

---

### Examples

```text
Modules/Content/Transformers/PageTransformer

Modules/WooCommerce/Transformers/ProductTransformer

Modules/Membership/Transformers/MemberTransformer
```

---

## Reasoning

Transformers represent domain logic.

Core should not understand:

* Products
* Courses
* Members
* Directories

---

# 10. Transformer Responsibilities

Transformers convert:

```text
Source Model
        ↓
Canonical Model
```

---

Transformers must:

* Apply business rules
* Normalize domain concepts
* Create canonical representations

---

Transformers must not:

* Query databases
* Call APIs
* Access Redis
* Publish events
* Persist projections

---

# 11. Transformer Purity

## Decision

Transformers are pure.

---

Example:

```text
Input
        ↓
ProductSourceModel
```

```text
Output
        ↓
ProductCanonicalModel
```

---

No side effects.

No infrastructure dependencies.

---

## Benefits

* Testability
* Replayability
* Deterministic behavior
* Predictability

---

# 12. Transformer Composition

Large transformations should be decomposed.

---

Example:

```text
ProductTransformer
        ↓
AttributeTransformer

MediaTransformer

InventoryTransformer

RelationshipTransformer
```

---

## Benefits

* Smaller units
* Better testing
* Easier maintenance
* Improved reuse

---

# 13. Canonical Models

## Decision

Modules own canonical model implementations.

Core owns canonical contracts.

---

Core:

```text
Core/Contracts/CanonicalModelInterface
```

---

Modules:

```text
Modules/Content/CanonicalModels/PageCanonicalModel

Modules/WooCommerce/CanonicalModels/ProductCanonicalModel
```

---

# 14. Canonical Model Purpose

Canonical Models represent business-domain meaning.

---

Example:

```text
Product
```

rather than:

```text
WC_Product
```

or:

```text
commerce.products table
```

---

Canonical Models sit between:

```text
Source System
```

and

```text
Delivery System
```

---

# 15. Canonical Model Independence

## ADR-030

### Status

Accepted

### Decision

Canonical Models represent business-domain meaning only.

---

Canonical Models must not depend on:

* PostgreSQL schemas
* Search schemas
* Cache schemas
* Analytics schemas
* API response formats

---

### Consequence

New delivery targets may be added without changing transformation logic.

---

# 16. Canonical Model Versioning

## Decision

Canonical Models support versioning.

---

Examples:

```text
ProductCanonicalModelV1

ProductCanonicalModelV2
```

---

Purpose:

* Backward compatibility
* Evolution support
* Adapter stability

---

# 17. Canonical Model Evolution

## ADR-031

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

Domain evolution remains within modules.

Adapters remain simple.

---

# 18. Relationship Handling

## Decision

Reference-First Relationships

---

Preferred:

```json
{
  "related_products": [
    "uuid-1",
    "uuid-2"
  ]
}
```

---

Avoid:

```json
{
  "related_products": [
    { ...full product... }
  ]
}
```

---

## Benefits

* Smaller payloads
* Reduced recursion
* Better replayability
* Easier synchronization

---

# 19. Adapter Boundary

## Decision

Adapters own projection creation.

---

Architecture:

```text
Canonical Model
        ↓
Adapter
        ↓
Delivery Projection
```

---

Transformers must never create:

* PostgreSQL rows
* Search documents
* Cache entries
* Analytics records

---

# 20. Projection Ownership

Delivery projections belong to adapters.

---

Examples:

```text
PostgreSQL Product Projection

Search Product Projection

Analytics Product Projection
```

---

Canonical Models remain unchanged.

---

# 21. Future Delivery Targets

The same canonical model may be delivered to:

```text
PostgreSQL

OpenSearch

Typesense

Redis Cache

Analytics Store

External API
```

without changing transformer logic.

---

# 22. Failure Strategy

## Decision

Fail Fast

---

Example:

```text
Source Model
        ↓
Validation Error
        ↓
Stop Processing
        ↓
Retry Workflow
```

---

The platform must not:

* Silently repair
* Partially transform
* Ignore corruption

---

# 23. Future Import Support

Because transformers consume Source Models rather than WordPress objects:

Future systems may create Source Models directly.

Examples:

```text
CSV Importer

ERP Importer

PIM Importer

External API Importer
```

---

Architecture:

```text
Importer
        ↓
ProductSourceModel
        ↓
Transformer
        ↓
Canonical Model
```

---

No transformer changes required.

---

# 24. Testing Strategy

Transformers should be tested independently.

---

Example:

```text
ProductSourceModel
        ↓
ProductTransformer
        ↓
Expected ProductCanonicalModel
```

---

No infrastructure required.

No database required.

No WordPress required.

---

# 25. Approval Checklist

* [x] Extractor architecture approved
* [x] Source model architecture approved
* [x] Validation architecture approved
* [x] Transformer ownership approved
* [x] Pure transformer architecture approved
* [x] Transformer composition approved
* [x] Canonical model ownership approved
* [x] Canonical model independence approved
* [x] Canonical model versioning approved
* [x] Canonical model evolution ownership approved
* [x] Reference-first relationships approved
* [x] Adapter projection ownership approved
* [x] Fail-fast strategy approved
* [x] Importer compatibility approved

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Transformer Architecture & Canonical Model Design specification for the Headless Sync Platform.
