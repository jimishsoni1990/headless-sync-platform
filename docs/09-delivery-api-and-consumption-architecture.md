# Delivery API & Consumption Architecture

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
* Document 7 — Adapter Architecture & Delivery Projection Design
* Document 8 — Worker Architecture & Execution Model

---

# 1. Purpose

This document defines how delivery data is exposed to consumer systems.

It establishes:

* API architecture
* Consumer boundaries
* Query architecture
* Transport abstraction
* Resource ownership
* Composition architecture
* Search architecture
* Versioning strategy
* Caching strategy
* Security model

This document is the authoritative specification governing delivery data consumption.

---

# 2. Architectural Principles

## Principle 1

Consumers Depend On API Contracts.

---

## Principle 2

Consumers Never Depend On Database Schemas.

---

## Principle 3

The Delivery API Is The Official Consumer Boundary.

---

## Principle 4

API Architecture Must Remain Transport-Agnostic.

---

## Principle 5

Query Logic Belongs To Domains.

---

## Principle 6

Composition Is A Consumer Concern.

---

## Principle 7

Internal Storage May Evolve Independently Of Consumer Contracts.

---

# 3. Platform Responsibility Boundary

## Approved Boundary

```text
WordPress
        ↓
Headless Sync Platform
        ↓
PostgreSQL
        ↓
Delivery API
        ↓
Consumers
```

---

Consumers include:

```text
Next.js

Mobile Applications

AI Agents

Partner APIs

Internal Applications

Search Experiences
```

---

The platform includes the Delivery API.

The platform does not include frontend implementations.

---

# 4. Delivery API Architecture

## High-Level Architecture

```text
Delivery Projections
        ↓
Query Providers
        ↓
API Contracts
        ↓
Transport Layer
        ↓
Consumers
```

---

The API is an architectural layer.

Transports are implementation details.

---

# 5. Transport-Agnostic Architecture

## ADR-038

### Status

Accepted

### Decision

The Delivery API is transport-agnostic.

---

### Architecture

```text
Query Layer
        ↓
API Contract Layer
        ↓
Transport Layer
        ↓
Consumer
```

---

### Phase 1

```text
REST
```

---

### Future

```text
GraphQL

gRPC

Internal SDK

Partner Gateway

Other Transports
```

---

### Consequence

The API architecture may evolve without redesigning the underlying query system.

---

# 6. API Ownership

## Core Owns

* API Contracts
* Query Contracts
* Transport Contracts
* Serialization Contracts
* Security Contracts

---

## Modules Own

* Query Providers
* Resources
* Endpoints
* Filters
* Composition Registrations

---

This follows the approved ownership pattern:

```text
Core Owns Contracts

Modules Own Implementations
```

---

# 7. API Versioning

## Decision

Versioning is required from day one.

---

Examples:

```text
/api/v1/pages

/api/v1/posts

/api/v1/products
```

---

Future:

```text
/api/v2/products
```

---

Versioning must be explicit.

---

# 8. Query Architecture

## Decision

APIs query delivery projections.

---

Architecture:

```text
Projection Tables
        ↓
Query Provider
        ↓
API
```

---

Prohibited:

```text
Canonical Model
        ↓
API
```

---

Canonical models remain internal platform contracts.

---

# 9. Query Ownership

## Decision

Query logic belongs to modules.

---

Examples:

```text
Modules/Content/Queries

Modules/WooCommerce/Queries

Modules/Membership/Queries
```

---

Reason:

Query requirements are domain-specific.

---

# 10. Query Providers

## Purpose

Encapsulate projection queries.

---

Architecture:

```text
Endpoint
        ↓
Query Provider
        ↓
Projection Tables
```

---

Benefits:

* Reuse
* Testability
* Isolation
* Consistency

---

Endpoints must not query tables directly.

---

# 11. Resource Architecture

## Decision

Resources are module-owned.

---

Examples:

```text
PageResource

ProductResource

CategoryResource
```

---

Responsibilities:

* Serialization
* Formatting
* Contract shaping
* Response consistency

---

Resources must not contain business logic.

---

# 12. Filtering Strategy

## Decision

Hybrid Filtering Model

---

Core provides:

```text
Filtering Contracts
```

---

Modules provide:

```text
Filter Implementations
```

---

Examples:

```text
slug

status

category

updated_after

published_after
```

---

# 13. Pagination Strategy

## Decision

Cursor Pagination

---

Preferred:

```text
cursor
```

---

Avoid:

```text
offset
```

for large datasets.

---

Benefits:

* Scalability
* Stability
* Performance

---

# 14. Search Architecture

## Decision

Search is provider-based.

---

## Search Provider Contract

Core owns:

```text
SearchProviderInterface
```

---

Future Implementations:

```text
PostgreSQLSearchProvider

OpenSearchProvider

TypesenseProvider
```

---

The platform must not require architectural redesign when introducing new search engines.

---

# 15. Search Strategy

## Phase 1

PostgreSQL Search

---

Examples:

```text
GIN Indexes

Full Text Search

Trigram Search
```

---

## Future

Dedicated search providers.

---

Examples:

```text
OpenSearch

Typesense
```

---

# 16. API Composition Strategy

## ADR-039

### Status

Accepted

### Decision

The platform supports:

```text
Domain APIs
```

and

```text
Composition APIs
```

---

# 17. Domain APIs

Examples:

```text
/api/v1/pages

/api/v1/posts

/api/v1/products

/api/v1/categories
```

---

Responsibilities:

* Domain-specific queries
* Domain-specific contracts
* Independent caching

---

# 18. Composition APIs

Examples:

```text
/compose/homepage

/compose/navigation

/compose/product-detail
```

---

Responsibilities:

* Consumer-oriented responses
* Aggregated data
* Reduced client round-trips

---

# 19. Composition Architecture

Architecture:

```text
Composition Endpoint
        ↓
Composition Provider
        ↓
Query Providers
        ↓
Projection Tables
```

---

Composition providers consume:

```text
Query Provider Contracts
```

not module implementations.

---

This preserves:

```text
No Module-To-Module Dependencies
```

---

# 20. Example Homepage Composition

```text
HomepageCompositionProvider
```

may aggregate:

```text
PageQueryProvider

NavigationQueryProvider

FeaturedProductQueryProvider

RecentPostsQueryProvider
```

---

Result:

```text
HomepageViewModel
```

---

# 21. Caching Strategy

## Decision

API-Level Caching Supported

---

Architecture:

```text
API
        ↓
Cache Layer
        ↓
Query Layer
```

---

Cache invalidation should be event-driven.

---

Examples:

```text
content.page.updated

commerce.product.updated
```

---

# 22. Security Model

## Decision

Support Public And Authenticated Endpoints

---

Examples:

### Public

```text
Pages

Posts

Navigation

Public Products
```

---

### Authenticated

```text
Administrative APIs

Partner APIs

Protected Commerce APIs
```

---

Authorization is endpoint-specific.

---

# 23. Consumer Boundary

## ADR-040

### Status

Accepted

### Decision

Consumers depend on API contracts.

Consumers must not depend on:

* PostgreSQL schemas
* Projection tables
* Canonical models
* Internal module implementations

---

### Consequence

Storage may evolve independently.

Consumer contracts remain stable.

---

# 24. Direct Database Access

## Official Position

Not supported.

---

Preferred:

```text
Consumer
        ↓
Delivery API
        ↓
PostgreSQL
```

---

Avoid:

```text
Consumer
        ↓
PostgreSQL
```

---

Reason:

* Security
* Governance
* Compatibility
* Versioning

---

# 25. Resource Versioning

Resources may evolve independently.

---

Examples:

```text
ProductResourceV1

ProductResourceV2
```

---

Version transitions must follow:

```text
Supported
        ↓
Deprecated
        ↓
Removed
```

---

# 26. API Contract Lifecycle

Lifecycle:

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

Backward compatibility windows should follow platform release policies.

---

# 27. Observability

The API layer must expose:

```text
Request Count

Response Time

Error Rate

Cache Hit Rate

Cache Miss Rate

Search Latency

Composition Latency
```

---

Purpose:

* Monitoring
* Capacity Planning
* Performance Analysis

---

# 28. Testing Strategy

Query Providers tested independently.

---

Example:

```text
Query Provider
        ↓
Projection Data
        ↓
Expected Result
```

---

Resources tested independently.

---

Composition providers tested independently.

---

Transport implementations tested separately.

---

# 29. Future Expansion

The architecture must support:

```text
GraphQL

gRPC

SDKs

Search Providers

Additional Consumers
```

without redesigning:

* Query Providers
* Resources
* Projection Models
* Domain APIs

---

# 30. Approval Checklist

* [x] Delivery API boundary defined
* [x] Transport-agnostic architecture approved
* [x] API ownership defined
* [x] Versioning defined
* [x] Query architecture defined
* [x] Query providers defined
* [x] Resource ownership defined
* [x] Filtering strategy defined
* [x] Cursor pagination approved
* [x] Search provider contract defined
* [x] Composition architecture defined
* [x] API caching defined
* [x] Security model defined
* [x] Consumer boundary defined
* [x] Direct database access policy defined

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Delivery API & Consumption Architecture specification for the Headless Sync Platform.
