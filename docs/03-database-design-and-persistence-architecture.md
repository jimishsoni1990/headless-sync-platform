# Database Design & Persistence Architecture

**Project:** Headless Sync Platform (HSP)
**Version:** 1.1
**Status:** Approved
**State:** Frozen

**Depends On:**

* Technical Architecture Specification v1.1
* Plugin Folder Structure & Code Organization v1.1

---

# 1. Purpose

This document defines the PostgreSQL persistence architecture for the Headless Sync Platform.

The database exists to provide optimized delivery projections for downstream consumers.

The database is not a WordPress replica.

The platform must never recreate:

* wp_posts
* wp_postmeta
* wp_terms
* wp_term_relationships

All persisted records must pass through:

```text
WordPress
    ↓
Transformer
    ↓
Canonical Model
    ↓
Delivery Projection
    ↓
PostgreSQL
```

---

# 2. Architectural Principles

## Principle 1

Transform Before Persist.

---

## Principle 2

PostgreSQL Is A Delivery Database.

---

## Principle 3

Queryable Data Becomes Structure.

---

## Principle 4

Variable Data Becomes JSONB.

---

## Principle 5

Events And Queue Jobs Are Separate Concepts.

---

## Principle 6

Schema Boundaries Reflect Domain Boundaries.

---

## Principle 7

Business Domains Own Their Projections.

---

# 3. PostgreSQL Schema Layout

```text
system
content
commerce
```

Future:

```text
membership
directory
booking
```

Each schema represents an architectural domain boundary.

---

# 4. System Schema

Purpose:

Infrastructure persistence.

Tables:

```text
system.events

system.queue_jobs

system.dead_letter_jobs

system.audit_log

system.schema_versions

system.module_versions

system.security_events
```

No business-domain content belongs in the system schema.

---

# 5. Content Schema

Purpose:

Content delivery projections.

Tables:

```text
content.pages

content.posts

content.taxonomies

content.entity_taxonomies

content.media
```

---

# 6. Commerce Schema

Purpose:

Commerce delivery projections.

Tables:

```text
commerce.products

commerce.product_variations

commerce.inventory

commerce.attributes

commerce.attribute_terms

commerce.product_attributes

commerce.categories

commerce.product_categories
```

---

# 7. Delivery Projection Rule

Fields commonly used for:

* Routing
* Filtering
* Sorting
* Indexing
* Lookups

must be projected into dedicated columns.

Examples:

```text
slug
uri
title
status
published_at
updated_at
```

---

Highly variable content remains JSONB.

Examples:

* ACF
* Flexible Content
* Repeaters
* Nested Repeaters
* Relationship Structures
* Page Builder Content

---

# 8. UUID Strategy

## ADR-015

### Status

Accepted

### Decision

Platform-owned identifiers use UUIDv7.

Examples:

```sql
id UUID PRIMARY KEY
```

Source identifiers remain references only.

Examples:

```sql
source_post_id BIGINT

source_product_id BIGINT

source_attachment_id BIGINT

source_term_id BIGINT
```

### Reasoning

* Better index locality
* Better insertion performance
* Better chronological ordering
* Better scalability

---

# 9. Content Storage Model

## content.pages

```sql
id UUID PRIMARY KEY

source_post_id BIGINT UNIQUE

source_entity_type VARCHAR(50)

slug VARCHAR(255)

uri VARCHAR(500)

title TEXT

status VARCHAR(50)

published_at TIMESTAMP

updated_at TIMESTAMP

deleted_at TIMESTAMP NULL

checksum VARCHAR(64)

structure_jsonb JSONB

meta_jsonb JSONB

created_at TIMESTAMP

synced_at TIMESTAMP
```

Indexes:

```sql
slug

uri

status

published_at

updated_at
```

GIN Indexes:

```sql
structure_jsonb

meta_jsonb
```

---

## content.posts

```sql
id UUID PRIMARY KEY

source_post_id BIGINT UNIQUE

source_entity_type VARCHAR(50)

slug VARCHAR(255)

uri VARCHAR(500)

title TEXT

excerpt TEXT

status VARCHAR(50)

published_at TIMESTAMP

updated_at TIMESTAMP

deleted_at TIMESTAMP NULL

checksum VARCHAR(64)

structure_jsonb JSONB

meta_jsonb JSONB

created_at TIMESTAMP

synced_at TIMESTAMP
```

---

# 10. Taxonomy Storage Model

## content.taxonomies

```sql
id UUID PRIMARY KEY

source_term_id BIGINT UNIQUE

taxonomy_type VARCHAR(50)

slug VARCHAR(255)

name VARCHAR(255)

description TEXT

deleted_at TIMESTAMP NULL

created_at TIMESTAMP

updated_at TIMESTAMP
```

---

## content.entity_taxonomies

```sql
entity_id UUID

taxonomy_id UUID

PRIMARY KEY (
    entity_id,
    taxonomy_id
)
```

Supports:

* Categories
* Tags
* Future Taxonomies

---

# 11. Media Model

## content.media

```sql
id UUID PRIMARY KEY

source_attachment_id BIGINT UNIQUE

slug VARCHAR(255)

title TEXT

mime_type VARCHAR(255)

file_name VARCHAR(255)

file_extension VARCHAR(50)

file_size BIGINT

width INTEGER

height INTEGER

alt_text TEXT

caption TEXT

original_url TEXT

cdn_url TEXT

deleted_at TIMESTAMP NULL

checksum VARCHAR(64)

metadata_jsonb JSONB

created_at TIMESTAMP

updated_at TIMESTAMP
```

---

## Media Rule

Media is a first-class entity.

References may appear inside JSONB structures.

The authoritative media record lives in:

```text
content.media
```

---

# 12. Commerce Storage Strategy

Commerce uses relational structures.

Commerce is not JSONB-first.

Reason:

Commerce workloads require:

* Filtering
* Sorting
* Aggregation
* Inventory Queries
* Reporting

---

# 13. Products

## commerce.products

```sql
id UUID PRIMARY KEY

source_product_id BIGINT UNIQUE

sku VARCHAR(255)

slug VARCHAR(255)

name TEXT

description TEXT

status VARCHAR(50)

product_type VARCHAR(50)

price NUMERIC

sale_price NUMERIC

currency VARCHAR(10)

stock_status VARCHAR(50)

featured BOOLEAN

deleted_at TIMESTAMP NULL

checksum VARCHAR(64)

created_at TIMESTAMP

updated_at TIMESTAMP

synced_at TIMESTAMP
```

---

# 14. Product Variations

## commerce.product_variations

```sql
id UUID PRIMARY KEY

product_id UUID

source_variation_id BIGINT UNIQUE

sku VARCHAR(255)

price NUMERIC

sale_price NUMERIC

stock_status VARCHAR(50)

deleted_at TIMESTAMP NULL

checksum VARCHAR(64)

attributes_jsonb JSONB

created_at TIMESTAMP

updated_at TIMESTAMP
```

---

# 15. Inventory

## commerce.inventory

```sql
id UUID PRIMARY KEY

product_id UUID

quantity INTEGER

stock_status VARCHAR(50)

backorders_allowed BOOLEAN

updated_at TIMESTAMP
```

---

# 16. Attributes

## commerce.attributes

```sql
id UUID PRIMARY KEY

source_attribute_id BIGINT UNIQUE

name VARCHAR(255)

slug VARCHAR(255)
```

---

## commerce.attribute_terms

```sql
id UUID PRIMARY KEY

attribute_id UUID

source_term_id BIGINT UNIQUE

name VARCHAR(255)

slug VARCHAR(255)
```

---

## commerce.product_attributes

```sql
product_id UUID

attribute_term_id UUID
```

---

# 17. Commerce Categories

## commerce.categories

```sql
id UUID PRIMARY KEY

source_term_id BIGINT UNIQUE

slug VARCHAR(255)

name VARCHAR(255)

description TEXT

deleted_at TIMESTAMP NULL

created_at TIMESTAMP

updated_at TIMESTAMP
```

---

## commerce.product_categories

```sql
product_id UUID

category_id UUID

PRIMARY KEY (
    product_id,
    category_id
)
```

Supports:

* Product Filtering
* Category Pages
* Category Aggregation
* Navigation Structures

---

# 18. Foreign Key Strategy

## ADR-013

### Status

Accepted

### Decision

Business projection tables use foreign keys.

Examples:

```text
commerce.product_variations.product_id
    → commerce.products.id

commerce.inventory.product_id
    → commerce.products.id

commerce.attribute_terms.attribute_id
    → commerce.attributes.id

commerce.product_categories.category_id
    → commerce.categories.id
```

---

Operational infrastructure tables use soft references.

Examples:

```text
system.queue_jobs.event_id

system.audit_log.entity_id
```

No FK enforcement required.

---

### Reasoning

Business data benefits from referential integrity.

Operational systems require flexibility for:

* Event archival
* Replay
* Recovery
* Retention management

---

# 19. Soft Delete Strategy

## ADR-014

### Status

Accepted

### Decision

All major delivery entities support:

```sql
deleted_at TIMESTAMP NULL
```

Examples:

* content.pages
* content.posts
* content.media
* commerce.products
* commerce.product_variations
* commerce.categories

---

### Lifecycle

```text
Published
    ↓
Trash
    ↓
Restore
    ↓
Delete
```

maps to:

```text
deleted_at = NULL
```

↓

```text
deleted_at = timestamp
```

↓

```text
deleted_at = NULL
```

↓

(optional purge)

---

### Benefits

* Restoration
* Reconciliation
* Replay
* Auditing
* Debugging

---

# 20. Event Store

## system.events

Purpose:

Immutable event history.

```sql
id UUID PRIMARY KEY

event_type VARCHAR(255)

event_version INTEGER

aggregate_type VARCHAR(100)

aggregate_id VARCHAR(255)

payload JSONB

created_at TIMESTAMP
```

Events never updated.

Events never reused.

Events represent facts.

---

# 21. Queue Jobs

## system.queue_jobs

Purpose:

Work processing.

```sql
id UUID PRIMARY KEY

event_id UUID

queue_name VARCHAR(255)

status VARCHAR(50)

attempts INTEGER

available_at TIMESTAMP

started_at TIMESTAMP

completed_at TIMESTAMP

last_error TEXT
```

Events and queue jobs remain separate.

---

# 22. Dead Letter Queue

## system.dead_letter_jobs

```sql
id UUID PRIMARY KEY

job_id UUID

event_id UUID

failure_reason TEXT

payload JSONB

created_at TIMESTAMP
```

---

# 23. Audit Log

## system.audit_log

```sql
id UUID PRIMARY KEY

entity_type VARCHAR(100)

entity_id UUID

action VARCHAR(100)

metadata JSONB

created_at TIMESTAMP
```

---

# 24. Schema Versioning

## system.schema_versions

Tracks:

* Core Schema Versions
* Applied Migrations
* Rollback State

---

## system.module_versions

Tracks:

* Module Name
* Schema Version
* Upgrade History

---

# 25. Event Retention Strategy

Events support:

```text
Hot Storage

Warm Storage

Archive Storage
```

Retention periods remain configurable.

---

## Hot Storage

Operational events.

Used for:

* Retries
* Replay
* Diagnostics

---

## Warm Storage

Historical operational data.

---

## Archive Storage

Long-term retention.

Must be removable from primary operational tables.

---

# 26. Content Revision Strategy

## ADR-016

### Status

Accepted

### Decision

WordPress owns revision history.

PostgreSQL stores only the current delivery projection.

---

### Current State

```text
WordPress
    owns revisions

PostgreSQL
    owns current state
```

---

### Future

Optional Content History Module may introduce historical snapshots.

Not included in Phase 1.

---

# 27. Search & Indexing Strategy

## Phase 1

PostgreSQL-native search.

Tools:

* GIN Indexes
* Full Text Search (tsvector)
* Trigram Indexes

Applies to:

* Pages
* Posts
* Products

---

## Phase 2

Dedicated Search Adapter.

Potential Targets:

* OpenSearch
* Typesense
* Meilisearch

Search systems consume delivery projections.

Search systems never consume WordPress tables directly.

---

# 28. Reconciliation Support

Every projection supports:

```text
Full Sync

Incremental Sync

Event Replay

Drift Detection
```

Required fields:

* Source Identifiers
* Timestamps
* Checksums

---

# 29. Approval Checklist

* [x] Domain schemas approved
* [x] Delivery Projection Rule approved
* [x] JSONB content model approved
* [x] Relational commerce model approved
* [x] Media model approved
* [x] Taxonomy model approved
* [x] Commerce category model approved
* [x] Foreign key strategy approved
* [x] Soft delete strategy approved
* [x] Event store approved
* [x] Queue separation approved
* [x] UUIDv7 strategy approved
* [x] Search strategy approved
* [x] Content revision strategy approved
* [x] Retention architecture approved

---

# Approval Status

**Version:** 1.1

**Status:** Approved

**State:** Frozen

This document is the authoritative persistence architecture specification for the Headless Sync Platform.
