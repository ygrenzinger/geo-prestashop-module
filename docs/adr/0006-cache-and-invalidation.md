# ADR 0006: Database-backed document cache and dependency revisions

## Context

Catalog generation combines product, category, price, and stock reads. Catalog
mutations also occur outside the module configuration page, and PrestaShop's
static cache API is request-local in the supported runtime.

## Decision

Use module-owned `llmscatalog_cache_document` and `llmscatalog_revision`
tables. Stable SHA-256 identities partition documents by type, shop, language,
product, and canonical public-price fingerprint. Atomic dependency revisions
invalidate entries without a mutable key registry or row enumeration. Cache
maintenance failures bypass caching and do not fail public responses.

Index and product documents have separate entries. Only complete documents are
cached. The configured origin TTL is shortened to the next applicable public
price transition. Successful responses use conditional validators and bound
shared freshness to the remaining origin lifetime. Errors and degraded pricing
use `no-store`.

## Consequences

Normal changes become visible quickly without regenerating on every request.
The TTL bounds missed or unsupported invalidation hooks. Runtime behavior and
hook coverage still require PrestaShop 9.0.2 and the latest supported 9.x
validation.
