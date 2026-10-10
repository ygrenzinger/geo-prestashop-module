# Freshness, invalidation, and HTTP caching

**Status:** Implemented locally; current host and Docker PrestaShop 9.2.0 suites pass. Supported-version and multi-node cache acceptance remains pending; see the [status matrix](00-geo-remediation-master-plan.md).
**Target module version:** 1.1.0  
**Minimum PrestaShop version:** 9.0.2  
**Catalog scale target:** Up to 10,000 public products per shop

## 1. Purpose

`llmscatalog` must serve deterministic catalog and product Markdown that is:

- fresh after supported PrestaShop mutations;
- bounded in staleness when mutations bypass hooks;
- safe for public HTTP caching;
- isolated by shop, language, product, and canonical price context;
- efficient for catalogs containing up to 10,000 products;
- resilient to concurrent cache misses and generation failures.

This specification covers:

- product and category creation, update, deletion, position, and assignment;
- stock changes, including order-driven quantity changes;
- combinations and default-combination changes;
- specific prices, catalog price rules, and scheduled promotions;
- currencies, exchange rates, taxes, and group reductions;
- shop, URL, language, and relevant configuration changes;
- origin caching, conditional requests, CDN caching, errors, and stampedes;
- multistore scoping, tests, migration, rollout, and rollback.

## 2. Current problems

The existing implementation does not satisfy this contract:

1. `CacheManager` uses `Cache::store()`, `retrieve()`, and `isStored()`. In
   PrestaShop 9 these static methods are request-local memory, so the current
   index cache does not persist between HTTP requests.

2. Only the index is cached. Product Markdown is generated on every request.

3. Both controllers advertise a hard-coded `max-age=3600`, independently of
   the configured TTL.

4. The index cache key contains only shop, language, and configuration version.
   It omits resource type, product identity, and the pricing context.

5. `Product::getPriceStatic()` inherits the request's currency, cart, customer
   group, and address. The same public URL can therefore produce visitor-specific
   bytes, which is incompatible with shared public caching.

6. Product and category object hooks do not cover stock, scheduled price
   transitions, all combination paths, currency or tax changes, or category
   position changes.

7. Invalidating the global known-key registry for each mutation does not scale
   to 10,000 product documents and is susceptible to concurrent registry
   updates.

8. Catalog reads use `_PS_USE_SQL_SLAVE_`. Immediately regenerating after
   invalidation can therefore read replica-lagged data and cache it as current.

## 3. Decisions

### 3.1 Canonical anonymous price context

All published documents use one canonical public price context per shop.
Request cookies, carts, authenticated customers, geolocation, and selected
visitor currencies do not affect output.

The canonical context is:

| Dimension | Value |
| --- | --- |
| Shop | Shop selected by the routed domain |
| Currency | Shop's configured default currency |
| Country | Shop country, falling back to the configured default country |
| State/postcode | Shop state/postcode when configured; otherwise neutral values |
| Customer | None |
| Cart | None |
| Group | PrestaShop's unidentified-visitors group |
| Quantity | 1 |
| Combination | Default combination |
| Specific prices | Generic rules applicable to the canonical dimensions |
| Customer-specific price | Disabled |
| Group reduction | Enabled for the unidentified-visitors group |
| Tax display | Match the canonical group's public price display method |
| Ecotax | Match shop configuration |

Price calculation must call the PrestaShop pricing API with these dimensions
explicitly. It must not infer them from the current visitor.

Third-party implementations of `actionProductPriceCalculation` remain
supported, but they are expected to honor the explicit parameters. A
third-party hook that deliberately derives price from unrelated request state is
outside the public-cache contract.

### 3.2 Browser and CDN caching

Successful responses use:

```http
Cache-Control: public, max-age=0, s-maxage=<remaining-fresh-seconds>, must-revalidate, no-transform
ETag: "sha256-<64 lowercase hexadecimal characters>"
Last-Modified: <IMF-fixdate>
Vary: Accept-Encoding
```

Consequences:

- browsers revalidate every use;
- browsers can receive a cheap `304 Not Modified`;
- shared caches may serve the response for at most the remaining origin
  freshness window;
- an origin invalidation cannot purge an unknown external CDN, so a CDN may
  remain stale until its existing `s-maxage` expires;
- each origin response uses the remaining freshness time, not the original
  configured TTL, so repeated cache hits cannot extend CDN freshness past the
  origin's expiry.

Errors and disabled/not-found documents use:

```http
Cache-Control: no-store
```

They do not emit an ETag or Last-Modified validator.

### 3.3 Minimum PrestaShop version

The module minimum becomes PrestaShop 9.0.2.

PrestaShop documents that `actionAttributeCombinationSave` was not triggered
correctly in 9.0.0 and was fixed in 9.0.2. Supporting 9.0.0 would require
accepting a known invalidation gap. The compatibility declaration must therefore
become:

```php
$this->ps_versions_compliancy = [
    'min' => '9.0.2',
    'max' => '9.99.99',
];
```

### 3.4 Database-backed cache and revisions

The content cache and dependency revisions are stored in module-owned database
tables.

The static PrestaShop `Cache` API is retained only for request-local
memoization. It is not the persistent cache of record.

Reasons:

- persistence must work even when an external PrestaShop cache backend is
  disabled;
- database state is shared across web nodes;
- invalidation hooks must not enumerate or delete up to 10,000 product entries;
- stable keys prevent abandoned versioned cache entries from growing without
  bound;
- atomic revision updates avoid lost invalidations.

Both tables contain disposable derived state and no merchant-authored data.

## 4. Freshness contract

### 4.1 Origin freshness

For covered mutations, the next origin request must:

1. read the updated revision from the primary database;
2. reject any cache entry with an older dependency snapshot;
3. regenerate from the primary database;
4. overwrite the stable cache row;
5. evaluate conditional request headers against the current bytes.

There is no eager regeneration in mutation hooks.

### 4.2 External-cache freshness

An external shared cache may remain stale for at most the `s-maxage` value it
received before the mutation.

The module does not claim immediate invalidation of an unknown CDN. CDN purge
integration is a possible future extension.

### 4.3 Missed-hook freshness

Changes made through direct SQL, an unhooked third-party integration, or a
failed invalidation hook become visible no later than the configured TTL.

### 4.4 Scheduled promotions

A promotion starting or ending does not execute a mutation hook at its time
boundary. The effective cache expiry must therefore be:

```text
min(generated_at + configured_ttl, next_relevant_price_transition)
```

The next transition is the earliest future `from` or `to` boundary from a
specific price or generated catalog-price-rule row applicable to the canonical
shop, currency, country, group, customer, cart, and quantity dimensions.

A transition query may conservatively include an unrelated public rule and
shorten the cache lifetime. It must never exclude a rule that can affect the
canonical price.

## 5. Persistent data model

### 5.1 Revision table

Create `_DB_PREFIX_llmscatalog_revision`:

| Column | Type | Meaning |
| --- | --- | --- |
| `id_shop` | unsigned integer | `0` for global or the affected shop |
| `scope` | ASCII varchar | `settings`, `index`, `product`, `pricing`, or `routing` |
| `id_entity` | unsigned integer | Product ID for `product`; otherwise `0` |
| `revision` | unsigned bigint | Monotonic invalidation counter |
| `changed_at` | UTC datetime | Last successful bump |

Primary key:

```text
(id_shop, scope, id_entity)
```

A missing revision is equivalent to `0`.

Bumps use one atomic statement:

```sql
INSERT ... revision = 1
ON DUPLICATE KEY UPDATE
    revision = revision + 1,
    changed_at = UTC_TIMESTAMP()
```

Related bumps should execute in one short database transaction.

### 5.2 Cached-document table

Create `_DB_PREFIX_llmscatalog_cache_document`:

| Column | Type | Meaning |
| --- | --- | --- |
| `cache_key` | ASCII `CHAR(64)` | SHA-256 of the normalized representation identity |
| `resource_type` | ASCII varchar | `index` or `product` |
| `id_shop` | unsigned integer | Shop identity |
| `id_lang` | unsigned integer | Requested language |
| `id_product` | unsigned integer | `0` for index |
| `variant_hash` | ASCII `CHAR(64)` | Canonical pricing and rendering dimensions |
| `content` | `MEDIUMTEXT` | Exact UTF-8 response bytes |
| `content_sha256` | ASCII `CHAR(64)` | Hash of exact content |
| `generated_at` | UTC datetime | Last semantic content modification |
| `fresh_until` | UTC datetime | Origin freshness boundary |
| `stale_until` | UTC datetime | Maximum safe stale-on-error boundary |
| `settings_revision` | unsigned bigint | Dependency snapshot |
| `global_pricing_revision` | unsigned bigint | Dependency snapshot |
| `shop_index_revision` | unsigned bigint | Used by index rows |
| `shop_pricing_revision` | unsigned bigint | Dependency snapshot |
| `shop_routing_revision` | unsigned bigint | Dependency snapshot |
| `product_revision` | unsigned bigint | Used by product rows |
| `updated_at` | UTC datetime | Last row write |

Indexes:

```text
PRIMARY KEY (cache_key)
INDEX (id_shop, resource_type, id_product)
INDEX (stale_until)
```

The cache key is stable across revision changes. Regeneration overwrites the
existing row.

## 6. Cache identity and dependencies

### 6.1 Normalized representation identity

Index identity:

```text
schema-version
resource=index
shop-id
language-id
canonical-price-context-fingerprint
```

Product identity:

```text
schema-version
resource=product
shop-id
language-id
product-id
canonical-price-context-fingerprint
```

The slug is not part of product identity; the product ID is authoritative. The
response should emit the current canonical URL as `Content-Location`.

The pricing fingerprint contains at least:

- default currency ID and precision;
- country, state, and postcode inputs;
- unidentified group ID;
- tax-included/tax-excluded mode;
- ecotax setting;
- quantity;
- default-combination policy;
- rounding configuration.

### 6.2 Dependency snapshots

An index entry depends on:

- global `settings`;
- global `pricing`;
- shop `index`;
- shop `pricing`;
- shop `routing`.

A product entry depends on:

- global `settings`;
- global `pricing`;
- shop `pricing`;
- shop `routing`;
- shop/product `product`.

A cache entry with any mismatched dependency is invalid and cannot be served
stale.

### 6.3 Revision operations

The implementation exposes these operations:

| Operation | Revisions changed |
| --- | --- |
| `invalidateProduct(shopIds, productId)` | Each shop's `index` and shop/product `product` |
| `invalidateIndex(shopIds)` | Each shop's `index` |
| `invalidateShopPricing(shopIds)` | Each shop's `pricing` and `index` |
| `invalidateGlobalPricing()` | Global `pricing` |
| `invalidateRouting(shopIds)` | Each shop's `routing` and `index` |
| `invalidateSettings()` | Global `settings` |

Hook handlers may call more than one operation. A request-local deduplication
set prevents repeated hooks from bumping the same revision tuple multiple times
during one mutation request.

## 7. Cache read and generation flow

For both index and product documents:

1. Resolve the routed shop and language.
2. Construct the canonical price context.
3. Build the normalized identity and stable cache key.
4. Read current revisions from the primary database.
5. Read the cached document.
6. If its dependency snapshot matches and `fresh_until > now`, use it.
7. Otherwise attempt to acquire the generation lock.
8. After acquiring the lock, repeat steps 4–6 because another request may have
   regenerated.
9. Generate from the primary database.
10. Determine the configured and promotion-boundary expiry.
11. Hash the exact content.
12. If the hash equals the previous valid representation, preserve
    `generated_at`; otherwise set it to the current time.
13. Store the content, validators, expiry, and revision snapshot atomically.
14. Release the lock in `finally`.
15. Evaluate conditional request headers.
16. Return `200`, `304`, a safe stale response, `404`, or `503`.

Catalog generation must stop using `_PS_USE_SQL_SLAVE_`. Replica reads cannot
satisfy an invalidate-then-regenerate guarantee.

## 8. HTTP conditional request handling

### 8.1 ETag

The ETag is a strong validator of the exact uncompressed UTF-8 response bytes:

```text
"sha256-<content_sha256>"
```

The same document bytes have the same ETag across regenerations.

### 8.2 Last-Modified

`Last-Modified` is the cache entry's `generated_at`, rounded to whole seconds.

If regeneration produces identical bytes, preserve the existing `generated_at`.
This prevents an irrelevant invalidation from appearing as a content
modification.

### 8.3 Preconditions

For `GET` and `HEAD`:

1. Evaluate `If-None-Match` first.
2. Support comma-separated tags and `*`.
3. Use the weak comparison required for GET semantics, even though the emitted
   validator is strong.
4. If `If-None-Match` is present, ignore `If-Modified-Since`.
5. Otherwise compare `If-Modified-Since` to `generated_at`.
6. A match returns `304` without a body.

A `304` repeats:

- `Cache-Control`;
- `ETag`;
- `Last-Modified`;
- `Vary`;
- `Content-Location`;
- the product route's `Link` header where applicable.

### 8.4 Successful response headers

Index `200`:

```http
Content-Type: text/plain; charset=UTF-8
X-Content-Type-Options: nosniff
Content-Location: <canonical-index-url>
Cache-Control: public, max-age=0, s-maxage=<remaining>, must-revalidate, no-transform
ETag: "sha256-..."
Last-Modified: ...
Vary: Accept-Encoding
```

Product `200` adds:

```http
Content-Type: text/markdown; charset=UTF-8
Link: <canonical-index-url>; rel="describedby"
Content-Location: <canonical-product-markdown-url>
```

`HEAD` returns the same headers as GET and no body.

### 8.5 Error responses

| Condition | Status | Cache policy |
| --- | --- | --- |
| Module disabled | `404` | `no-store` |
| Invalid or unavailable language | `404` | `no-store` |
| Missing, inactive, or hidden product | `404` | `no-store` |
| Generation failure without safe stale content | `503` | `no-store`, `Retry-After: 5` |
| Cold miss while another request owns the lock | `503` | `no-store`, `Retry-After: 1` |

Error bodies remain minimal Markdown or plain text and must not contain
exception details.

## 9. Invalidation hook matrix

All mutation handlers must:

- resolve scope from hook parameters or persisted associations;
- use primary-database reads;
- perform only revision upserts;
- avoid document regeneration;
- catch and log invalidation failures so a merchant mutation is not rolled back
  by cache maintenance;
- rely on TTL as the last-resort recovery mechanism.

### 9.1 Product and category events

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Product add/update/delete | `actionObjectProductAddAfter`, `actionObjectProductUpdateAfter`, `actionObjectProductDeleteAfter`, `actionProductSave`, `actionProductUpdate`, `actionProductDelete` | Affected product and index in associated shops |
| Modern product form completion | `actionAfterCreateProductFormHandler`, `actionAfterUpdateProductFormHandler` | Affected product and index |
| Category add/update/delete/position | `actionCategoryAdd`, `actionCategoryUpdate`, `actionCategoryDelete`, plus object hooks as fallback | Index and routing for affected shops |
| Product-category assignment/default category | Product form hooks and product update hooks | Product and index |
| Product shop association | Product update/shop-association path | Product and index in old and new shops where determinable; otherwise all shops |

Dedicated category hooks are required because category position updates invoke
`actionCategoryUpdate` without necessarily passing through the generic
object-update path.

PrestaShop does not expose one guaranteed hook for every isolated direct call to
its category-assignment services. Standard back-office and legacy object paths
are covered; direct SQL and unhooked domain calls depend on TTL fallback.

### 9.2 Stock and orders

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Quantity change | `actionUpdateQuantity` | Affected product and index |
| Direct `StockAvailable` object mutation | `actionObjectStockAvailableAddAfter`, `UpdateAfter`, `DeleteAfter` | Affected product and index |
| Order placement, cancellation, refund, return, or manual correction | Covered through `actionUpdateQuantity` when quantity actually changes | Affected product and index |

Do not invalidate from `actionValidateOrder` merely because an order exists.
`actionUpdateQuantity` is the authoritative stock-change event and includes the
affected product, combination, quantity, and shop.

For shared stock with `id_shop` equal to `0` or unresolved, invalidate all shops
in the stock-sharing group. If the group cannot be resolved safely, invalidate
every active shop.

### 9.3 Combinations

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Combination create/update/delete | `actionObjectCombinationAddAfter`, `UpdateAfter`, `DeleteAfter` | Parent product and index |
| Attribute membership saved/deleted | `actionAttributeCombinationSave`, `actionAttributeCombinationDelete` | Parent product and index |
| Legacy product-attribute update/delete | `actionProductAttributeUpdate`, `actionProductAttributeDelete` | Parent product and index |
| Default combination changed | `actionUpdateDefaultCombinationAfter` | Product and index |
| Combination stock changed | `actionUpdateQuantity` | Product and index |

When only a combination ID is supplied, resolve the parent product from the
primary database.

### 9.4 Specific prices and promotions

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Product-specific price add/update/delete | `actionObjectSpecificPriceAddAfter`, `UpdateAfter`, `DeleteAfter` | Product and index in the rule's shops |
| Product-independent or unresolved specific price | Same hooks | Shop pricing, or global pricing if shop is `0` |
| Catalog price rule add/update/delete | `actionObjectSpecificPriceRuleAddAfter`, `UpdateAfter`, `DeleteAfter` | Shop pricing, or global pricing |
| Promotion start/end | No hook at the time boundary | Effective TTL ends at next applicable boundary |
| Unidentified-group reduction | Group and group-reduction object hooks | Shop or global pricing |

Resolving every product affected by a catalog price rule is intentionally
avoided. A shop/global pricing revision invalidates entries lazily and keeps the
mutation path independent of catalog size.

### 9.5 Currency and tax

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Currency add/update/delete, format, precision, or exchange rate | `actionObjectCurrencyAddAfter`, `UpdateAfter`, `DeleteAfter` | Global pricing unless affected shops are unambiguous |
| Default currency configuration | Configuration hook | Shop pricing |
| Tax add/update/delete | `actionObjectTaxAddAfter`, `UpdateAfter`, `DeleteAfter` | Global pricing |
| Tax-rule add/update/delete | `actionObjectTaxRuleAddAfter`, `UpdateAfter`, `DeleteAfter` | Global pricing |
| Tax-rules-group add/update/delete | `actionObjectTaxRulesGroupAddAfter`, `UpdateAfter`, `DeleteAfter` | Global pricing |
| Product tax-rules-group change | Product update hooks | Product and index |
| Tax display/ecotax configuration | Configuration hook | Shop or global pricing |

Conservative global invalidation is acceptable for infrequent tax and currency
administration. It changes only one global revision row and does not scan cached
documents.

### 9.6 Shop, language, URL, and configuration

Register `actionConfigurationUpdateValueBefore` and allowlist
content-affecting keys.

The hook runs before persistence; a failed or no-op configuration update may
therefore cause a harmless false-positive invalidation.

The allowlist includes at least:

- `PS_SHOP_NAME`;
- `PS_META_DESCRIPTION`;
- `PS_LANG_DEFAULT`;
- `PS_CURRENCY_DEFAULT`;
- `PS_COUNTRY_DEFAULT`;
- `PS_SHOP_COUNTRY_ID`;
- `PS_SHOP_STATE_ID`;
- `PS_SHOP_CODE`;
- `PS_UNIDENTIFIED_GROUP`;
- `PS_TAX`;
- `PS_TAX_ADDRESS_TYPE`;
- `PS_PRICE_DISPLAY_PRECISION`;
- `PS_PRICE_ROUND_MODE`;
- `PS_ROUND_TYPE`;
- `PS_USE_ECOTAX`;
- `PS_ECOTAX_TAX_RULES_GROUP_ID`;
- relevant friendly-URL and product/category route keys;
- SSL/domain settings that affect generated URLs.

Also register:

| Change | Hooks | Invalidation |
| --- | --- | --- |
| Shop metadata | `actionObjectShopAddAfter`, `UpdateAfter`, `DeleteAfter`, `actionShopFormSave` | Routing and index |
| Shop URL/domain | `actionObjectShopUrlAddAfter`, `UpdateAfter`, `DeleteAfter` | Routing and index |
| Language add/update/delete | Language object hooks and `actionUpdateLangAfter` | Routing and index in affected shops |
| Module configuration save | Module controller | Global settings |
| Module enable/disable | Module lifecycle/configuration path | Global settings |

`LLMSCATALOG_*` writes are handled explicitly by the module configuration
service and excluded from the generic configuration hook to prevent duplicate
or recursive invalidation.

## 10. Multistore scoping

Hook handlers must not assume the current `Context` shop is the affected shop.

Scope resolution order:

1. explicit `id_shop` from hook parameters;
2. object-level `id_shop`;
3. object shop associations read from the primary database;
4. current single-shop context, if the mutation is explicitly context-bound;
5. all active shops as the safe fallback.

Rules with `id_shop = 0` are global.

Shared-stock changes invalidate the full stock-sharing group.

Module settings remain shared across shops, as previously decided. `Settings`
must use global configuration reads and writes for `LLMSCATALOG_*`. Generated
data, revisions, cache rows, URLs, language, prices, and catalog eligibility
remain shop-isolated.

During upgrade, existing effective module settings from the default shop become
the shared global values. Existing shop-specific rows may remain for rollback
but are ignored by the new settings reader.

## 11. Stampede control

Generation uses a database advisory lock derived from the stable cache key.

Lock name:

```text
llmscat:<first 48 characters of cache-key SHA-256>
```

Flow:

1. Attempt `GET_LOCK(lockName, 0)` on the primary connection.
2. If acquired, double-check the cache and revisions.
3. Generate only if still necessary.
4. Always call `RELEASE_LOCK(lockName)` in `finally`.

When the lock is already held:

- serve a dependency-current stale entry if allowed;
- otherwise return `503 no-store` with `Retry-After: 1`;
- do not start a duplicate 10,000-product generation.

If advisory locks are unavailable:

- emit one rate-limited operational warning;
- fall back to a local `_PS_CACHE_DIR_` `flock` when available;
- if neither mechanism works, generate without coalescing rather than
  permanently fail the route.

The degraded mode affects load protection, not cache correctness.

## 12. Error and stale behavior

Keep an expired entry until `stale_until` so it can be used during transient
failures.

Stale grace:

```text
min(configured_ttl, 900 seconds)
```

A stale entry may be served only when:

- its complete dependency snapshot still matches;
- it was previously a successful `200` representation;
- the current time is before `stale_until`;
- generation failed or another request currently owns the generation lock.

A stale entry must not be served when:

- a product, pricing, routing, or settings revision changed;
- the module was disabled;
- the product became inactive, hidden, deleted, or inaccessible;
- the representation is a cached error;
- the stale grace expired.

A stale response uses:

```http
Cache-Control: public, max-age=0, s-maxage=0, must-revalidate, no-transform
```

It is not reusable by a shared cache without revalidation.

Exceptions are logged without exposing stack traces or database details in the
response. Cache writes occur only after successful complete generation.

## 13. Scale behavior for 10,000 products

### 13.1 Mutation cost

Invalidating one product performs a constant number of indexed revision
upserts. It does not:

- scan product documents;
- enumerate cache keys;
- delete all shop cache entries;
- regenerate the index;
- regenerate other product documents.

Broad pricing or routing changes update one revision per affected shop or one
global revision.

### 13.2 Index generation

The index repository must use deterministic keyset pagination rather than
loading an unbounded result eagerly.

Recommended batch size: 250 products.

Generation must:

1. read from the primary database;
2. preserve catalog ordering plus product-ID tie-breaking;
3. render incrementally;
4. stop at `max_products`;
5. stop once the byte limit can no longer admit another complete entry;
6. avoid calculating prices for products that cannot fit in the remaining
   output;
7. preserve the explicit truncation notice.

Product documents remain generated and cached on demand. Do not pre-generate
all 10,000 documents after invalidation.

### 13.3 Cache storage bounds

Stable document keys cap normal rows at approximately:

```text
shops × languages × (1 index + accessed product documents)
```

Revision changes overwrite rows instead of creating a new versioned key.

A bounded lazy cleanup runs at most once per request that writes cache data:

```sql
DELETE FROM ...cache_document
WHERE stale_until < UTC_TIMESTAMP()
LIMIT 100
```

Operational or upgrade tooling may perform a full expired-row cleanup
separately.

## 14. Implementation structure

### 14.1 Existing files to change

- `llmscatalog/llmscatalog.php`
  - bump module and compatibility versions;
  - register the expanded hook set;
  - delegate hook handling to an invalidation service;
  - create/drop cache tables during install/uninstall.

- `llmscatalog/src/Service/CacheManager.php`
  - replace request-local key storage with database-backed document storage;
  - expose fresh, stale, miss, and invalid-dependency states.

- `llmscatalog/src/Service/CatalogRepository.php`
  - use primary reads;
  - calculate canonical anonymous prices explicitly;
  - expose next applicable promotion transition;
  - support deterministic batched index reads.

- `llmscatalog/src/Service/LlmsGenerator.php`
  - cache both index and product representations;
  - return document metadata rather than a bare string;
  - render the index incrementally.

- `llmscatalog/src/Service/Settings.php`
  - use shared global module settings;
  - validate the TTL range;
  - remove configuration-version key coupling.

- Both front controllers
  - centralize successful/error/cache headers;
  - support ETag, Last-Modified, GET/HEAD, and 304;
  - use `no-store` for errors.

- `llmscatalog/src/Controller/Admin/ConfigurationController.php`
  - bump the global settings revision after a successful save;
  - stop enumerating and clearing keys.

- Documentation and operations guides
  - replace the old global-key invalidation description;
  - document the external-CDN staleness boundary.

### 14.2 New services

Add narrowly scoped services or value objects for:

- `CanonicalPriceContext`;
- `CacheIdentity`;
- `CacheEntry`;
- `RevisionSnapshot`;
- `RevisionStore`;
- `InvalidationService`;
- `GenerationLock`;
- `GeneratedDocument`;
- `HttpCacheResponder`;
- `PromotionBoundaryResolver`;
- `HookScopeResolver`.

The exact class split may be reduced if some remain small, but pricing, revision
persistence, HTTP semantics, and hook scope resolution must not remain embedded
in controllers.

### 14.3 Database lifecycle

Add:

```text
upgrade/upgrade-1.1.0.php
```

The upgrade must:

1. create both tables idempotently;
2. migrate shared configuration;
3. register every new hook idempotently;
4. set the module version only after successful schema and hook setup.

Uninstall removes both tables after module configuration is removed.

A code rollback may leave the tables in place; the previous module version
ignores them. Only uninstall deletes them.

## 15. Tests

### 15.1 Unit tests

Cover:

- normalized cache identity;
- shop/language/product isolation;
- canonical price-context fingerprinting;
- dependency snapshot matching and mismatch;
- atomic revision-operation selection;
- hook scope resolution;
- request-local invalidation deduplication;
- configured TTL and promotion-boundary minimum;
- remaining `s-maxage`;
- stable ETag generation;
- preserving Last-Modified for identical content;
- `If-None-Match` list, wildcard, and weak comparison;
- `If-Modified-Since` fallback;
- ETag precedence over date validators;
- GET versus HEAD;
- `200`, `304`, `404`, and `503` header sets;
- stale-on-error eligibility and rejection;
- no stale response after a revision change;
- no caching of partial or error output.

### 15.2 Database integration tests

Cover:

- schema install and idempotent upgrade;
- concurrent revision bumps without lost updates;
- stable cache-row overwrite;
- multistore global and shop-specific revisions;
- lock acquisition, double-check, and release;
- expired-row cleanup limits;
- all reads involved in regeneration using the primary connection.

### 15.3 PrestaShop test-shop matrix

Run on the minimum supported 9.0.2 release and the latest supported PrestaShop 9
release.

For at least two shops and two languages, verify:

1. Product add, edit, deactivate, hide, reactivate, and delete.
2. Category add, rename, move, reorder, disable, and delete.
3. Product category assignment and default-category changes.
4. Manual stock changes.
5. Order placement reducing quantity.
6. Cancellation/refund/restock increasing quantity.
7. Combination add, edit, delete, stock change, and default selection.
8. Product-specific price add, edit, and delete.
9. Catalog price rule add, edit, and delete.
10. A scheduled promotion begins and ends without an administrative write at
    the boundary.
11. Default currency change.
12. Currency exchange-rate update.
13. Currency formatting and precision change.
14. Tax rate and tax-rule change.
15. Product tax-rules-group change.
16. Unidentified-group reduction change.
17. Shop name, domain, SSL, and friendly-route changes.
18. Default-language and fallback-content changes.
19. Module title, summary, include flags, size limits, and TTL changes.
20. Shared-stock changes affecting more than one shop.
21. A direct SQL change appears after TTL even though no hook ran.
22. Visitor cookies, carts, logged-in customers, selected currency, and
    geolocation do not change document bytes.
23. A fresh browser revalidation returns `304`.
24. A changed representation returns `200` with a different ETag.
25. `s-maxage` decreases as an origin entry ages.
26. Error responses are `no-store`.

### 15.4 Scale and concurrency tests

Seed 10,000 eligible products in one shop.

Acceptance checks:

- one product mutation performs a constant number of revision writes;
- another product's cached document remains a hit;
- the affected product and index become misses;
- a warm conditional index request performs no catalog regeneration and returns
  `304`;
- 20 simultaneous cold requests result in at most one generation for the same
  cache key when advisory locking is available;
- competing cold requests receive the completed representation, a safe stale
  representation, or a short `503`, but do not start 20 catalog scans;
- byte-limited generation stops fetching/pricing products after the output
  bound is reached;
- no hook enumerates 10,000 cache entries.

Record wall time, query count, peak memory, cache-table size, and lock wait
outcomes. Establish release thresholds from the PrestaShop test environment
rather than claiming hardware-independent latency.

## 16. Observability

Log through PrestaShop's logger:

- schema or revision-write failures;
- generation exceptions;
- advisory-lock unavailability;
- unexpected all-shop scope fallback;
- stale-on-error delivery;
- malformed cached rows;
- cache write failures.

Do not log every hit or miss in production.

Each message should include non-sensitive dimensions:

- resource type;
- shop ID;
- language ID;
- product ID when applicable;
- cache-key prefix;
- invalidation source hook;
- whether scope was explicit or conservative.

Never log product content, customer cookies, cart IDs, or request authorization
data.

## 17. Rollout

1. Implement database schema, revisions, canonical pricing, and persistent
   caching.
2. Add unit and database tests.
3. Install on a PrestaShop 9.0.2 test shop and the latest 9.x test shop.
4. Run the complete mutation matrix.
5. Run the 10,000-product scale and concurrency tests.
6. Deploy the module upgrade during a normal low-traffic window.
7. Confirm table creation and hook registration.
8. Request each shop/language index once to warm only the index variants.
9. Do not prewarm 10,000 product documents.
10. Verify browser revalidation, CDN headers, and one live non-destructive
    product edit.
11. Monitor generation errors, stale responses, and lock warnings.
12. Rebuild the distributable ZIP only after runtime validation passes.

## 18. Rollback

Code rollback to the previous module version is allowed because the new tables
are additive and disposable.

During rollback:

- leave cache and revision tables in place;
- clear any external CDN entries if one is configured;
- accept that the old version has the freshness and public-price-context defects
  documented in this specification;
- do not drop tables unless uninstalling the module.

If the upgrade script fails before completion, keep the prior module version
active and surface the upgrade failure in the back office. Do not run partially
migrated freshness code without both tables.

## 19. Acceptance criteria

The change is complete when all of the following are true:

- both index and product documents use persistent cross-request caching;
- output is independent of visitor cart, customer, cookie, currency selection,
  and geolocation;
- browser responses use `max-age=0`;
- shared-cache freshness uses the remaining effective TTL as `s-maxage`;
- strong ETags, Last-Modified, HEAD, and `304` work correctly;
- errors and not-found responses use `no-store`;
- scheduled public price transitions cap cache expiry;
- product, category, stock, order-driven quantity, combination, price, currency,
  tax, shop, assignment, and module-setting changes follow the documented
  invalidation matrix;
- one product invalidation does not invalidate unrelated product documents;
- broad pricing changes do not enumerate catalog products or cached rows;
- regeneration reads from the primary database;
- stampedes are coalesced when database advisory locks are available;
- stale content is never served across a dependency revision change;
- the module supports 10,000 products without invalidation work proportional to
  catalog size;
- minimum compatibility is PrestaShop 9.0.2;
- runtime validation passes on both minimum and latest supported PrestaShop 9
  versions.

## 20. PrestaShop feasibility references

- [`actionUpdateQuantity`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatequantity/)
  is invoked by PrestaShop stock update paths and carries product, combination,
  quantity, delta, and shop information.
- [`actionUpdateDefaultCombinationAfter`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatedefaultcombinationafter/)
  carries product, default combination, and shop scope.
- [`actionAttributeCombinationSave`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionattributecombinationsave/)
  covers combination attribute membership.
- [PrestaShop 9.0 changes](https://devdocs.prestashop-project.org/9/modules/core-updates/9.0/)
  document the 9.0.2 combination-hook correction.
- [The PrestaShop hook list](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/)
  documents dynamic `actionObject<ClassName>...` hooks and the generic modern
  form-handler hooks used by this design.
- [PrestaShop 9.1.5 `ObjectModel`](https://github.com/PrestaShop/PrestaShop/blob/9.1.5/classes/ObjectModel.php)
  dispatches dynamic object add, update, and delete hooks.
- [PrestaShop 9.1.5 `Product`](https://github.com/PrestaShop/PrestaShop/blob/9.1.5/classes/Product.php)
  shows the pricing dimensions and the lack of a dedicated hook inside every
  category-assignment helper.
- [PrestaShop 9.1.5 `Cache`](https://github.com/PrestaShop/PrestaShop/blob/9.1.5/classes/cache/Cache.php)
  shows that its static `store`, `retrieve`, and `isStored` methods use
  request-local memory.
