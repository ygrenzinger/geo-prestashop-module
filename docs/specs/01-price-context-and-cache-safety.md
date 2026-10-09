# Price context and public-cache safety

Status: Implemented; runtime validation pending  
Target module release: `1.1.0`  
Supported baseline: PrestaShop `9.0.2`

Implementation status: Code and unit-test artifacts are present in this
workspace. Runtime validation is pending PHP-enabled CI and PrestaShop test
shops; the acceptance criteria below are not yet declared complete.

## 1. Objective

Make every `llmscatalog` response independent of the requesting customer,
cart, cookies, geolocation, or selected currency.

For a given URL and catalog state, anonymous, logged-in, VIP,
alternate-currency, and alternate-country requests must produce identical
bytes and the same ETag. Only the current shop, requested active language,
module settings, canonical anonymous price context, and public catalog state
may affect the representation.

## 2. Current risks

The current implementation is unsafe for shared caching:

- `Product::getPriceStatic()` receives no explicit customer, cart, address,
  currency, country, or group in
  [`CatalogRepository.php`](../../llmscatalog/src/Service/CatalogRepository.php).
  PrestaShop therefore derives those inputs from `Context`, which can contain
  cookie-derived customer, cart, currency, and country state. PrestaShop's
  documented context includes all of those request-specific objects
  ([Context documentation](https://devdocs.prestashop-project.org/9/development/components/context/)).
- The index cache key contains only shop, language, and configuration version
  in [`CacheManager.php`](../../llmscatalog/src/Service/CacheManager.php). A
  request-specific price can therefore populate a cache entry reused by other
  visitors.
- Price formatting reads `Context::getContext()->currency` again in
  [`LlmsGenerator.php`](../../llmscatalog/src/Service/LlmsGenerator.php),
  independently of the price calculation inputs.
- Both controllers send `Cache-Control: public, max-age=3600`, including
  disabled and not-found responses.
- Invalid language codes fall back to the requester's current language, making
  a URL's representation cookie-dependent.
- Product Markdown is not server-cached, while the index is, so the two
  surfaces have inconsistent freshness.
- The module uses `Cache::store()`, `retrieve()`, and `isStored()`. In
  PrestaShop 9.0.2 these methods operate on the process-local static array, not
  the configured persistent cache backend. Persistent caching uses
  `Cache::getInstance()->set/get/delete`
  ([PrestaShop 9.0.2 Cache source](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.0.2/classes/cache/Cache.php)).

## 3. Decisions

| Concern | Decision |
| --- | --- |
| URL variants | Keep the existing language-only URLs. Do not add currency, country, group, or tax query/path variants. |
| Currency | Use the current shop's configured default currency. Ignore request cookies and currency parameters. |
| Country and tax address | Use the shop country, state, and postcode; fall back to the shop's default country configuration only when the shop address is incomplete. |
| Customer group | Use `PS_UNIDENTIFIED_GROUP`, the anonymous visitor group. Do not use `PS_GUEST_GROUP`, which represents guest-checkout customers. |
| Customer and cart | Use customer ID `0`, cart ID `0`, quantity `1`, and no customization. |
| Combination | Price the product's default combination. |
| Taxes | Follow the unidentified visitor group's configured price-display method. Include an explicit `tax included`, `tax excluded`, or `tax not applied` label. |
| Promotions | Include only active specific prices and group reductions applicable to the canonical anonymous context. |
| Private pricing | Exclude customer-specific, cart-specific, non-visitor-group, non-default-country, and non-default-currency prices. |
| Browser caching | Require revalidation with `max-age=0, must-revalidate`. |
| CDN caching | Permit bounded shared caching with `s-maxage`, capped at five minutes and never longer than the remaining origin-cache lifetime. |
| Validation | Use a strong SHA-256 ETag over the exact response body. |
| Cookies | The endpoints must not emit `Set-Cookie`. |
| Errors | Disabled, not-found, invalid-context, and generation-failure responses use `Cache-Control: no-store`. |
| Compatibility | First safe release supports PrestaShop 9.0.2 through 9.1.x. Do not claim 9.2 support until its stable release passes the matrix. |

## 4. Canonical public price context

Introduce an immutable `PublicPriceContext` containing only scalar values:

- shop ID and shop-group ID;
- requested active language ID and locale;
- default currency ID, ISO code, precision, and conversion rate;
- shop country ID, state ID, and normalized postcode;
- unidentified visitor group ID;
- group price-display method, price visibility, and public reduction;
- global tax-enabled flag;
- resolved tax mode;
- quantity `1`;
- customer ID `0`;
- cart ID `0`;
- default-combination marker;
- pricing-adapter schema version.

`PublicPriceContextFactory` must read configuration with explicit shop and
shop-group scope. It must validate that the currency, country, language, group,
and shop are loaded and mutually associated.

An invalid context is a service failure. It must never fall back to the current
customer, current cart, or another customer group.

## 5. Core price calculation

Add a single `PublicPriceCalculator` adapter around PrestaShop's core pricing
API.

For PrestaShop 9.0.2-9.1.x, the adapter will call
`Product::getPriceStatic()` with all supported parameters supplied explicitly:

- canonical tax mode;
- default combination;
- quantity one;
- reductions enabled;
- customer zero;
- cart zero;
- no customer-specific pricing;
- group reductions enabled;
- canonical context;
- specific-price output captured for diagnostics and expiry calculation.

The full positional call must exist in only this adapter. A reflection-based
compatibility test will verify the expected signature against every supported
PrestaShop version. PrestaShop 9.0.2 and 9.1.5 currently expose the required
inputs in their core API
([9.0.2 source](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.0.2/classes/Product.php),
[9.1.5 source](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/Product.php)).

Run all price calculations inside an `AnonymousContextRunner`:

1. Snapshot the current context fields.
2. Replace customer, cart, currency, country, language, and price-related state
   with canonical anonymous values.
3. Calculate and format all prices needed by the document.
4. Restore the original context in `finally`.

Do not use `Context::setInstanceForTesting()` in production.

This context scope ensures that core code and compliant
`actionProductPriceCalculation` extensions see anonymous values. Pricing
extensions that read raw request cookies or superglobals instead of the
supplied pricing context are unsupported and must be identified during the
target-shop compatibility test.

## 6. Specific-price rules

The canonical price includes:

- unrestricted public specific prices;
- prices restricted to the canonical shop;
- prices restricted to the default currency or all currencies;
- prices restricted to the shop country or all countries;
- prices restricted to the unidentified visitor group or all groups;
- active catalog-price rules materialized as matching specific prices;
- quantity-one rules;
- the default combination's price impact and eligible reduction;
- the unidentified group's public group reduction.

It excludes:

- `id_customer != 0`;
- `id_cart != 0`;
- another customer group;
- another currency or country;
- quantity thresholds greater than one;
- a non-default combination;
- cart rules, voucher codes, loyalty prices, and checkout-only reductions.

Specific prices are inherently scoped by currency, country, group, customer,
cart, quantity, combination, and time
([specific-price documentation](https://devdocs.prestashop-project.org/9/webservice/resources/specific_prices/));
those dimensions must therefore never be taken implicitly from a request.

## 7. Catalog eligibility

Retain active and visible product filtering, and strengthen "public" to mean
accessible to the unidentified visitor group:

- product belongs to the current shop;
- `active = 1`;
- `visibility != 'none'`;
- at least one associated category is accessible to the unidentified visitor
  group;
- the product passes the corresponding core access rule under the canonical
  context.

For categories, require current-shop association, active status, and
unidentified-group access.

Select `show_price`. When it is false, omit the price even if the module-wide
price option is enabled.

Availability semantics are unchanged by this work, but stock changes must
invalidate cached documents.

## 8. Output behavior

### Index

The localized index must:

- resolve only an active language associated with the current shop;
- reject unknown or inactive codes instead of falling back to cookie language;
- use the canonical public context for every product;
- state the price context once when prices are enabled, for example:
  `Prices use EUR for France, tax included, anonymous visitor, quantity 1,
  default combination.`;
- use the same formatted price representation as product Markdown;
- remain bounded by the configured product and byte limits.

### Product Markdown

Product Markdown must:

- use the same `PublicPriceContext` and formatter as the index;
- include the canonical storefront URL;
- label currency and tax treatment explicitly;
- omit price when `show_price` is false;
- return not-found for an inactive, hidden, inaccessible, or wrong-shop
  product.

Both endpoints must ignore representation-affecting query parameters and
request cookies.

## 9. Generation results

Replace empty-string and nullable sentinel values with a typed result:

- body;
- content type;
- language;
- status;
- cacheable flag;
- strong ETag;
- origin expiry;
- shared-cache expiry;
- optional `Link` headers.

Only complete, non-degraded `200` results are stored in the server cache.

If one product's price calculation fails:

- keep the non-price catalog information;
- omit that product's price;
- mark the response degraded;
- serve it as `200` with `Cache-Control: no-store`;
- log the product ID, shop ID, and exception class without customer or cookie
  data.

Context-resolution or catalog-query failure returns `503`.

## 10. Server cache

Replace the request-local static cache calls with the configured PrestaShop
cache backend:

- `Cache::getInstance()->get()`;
- `Cache::getInstance()->set($key, $record, $ttl)`;
- `Cache::getInstance()->delete()` when a precise deletion is available.

If persistent caching is disabled or unavailable, bypass the server cache and
generate normally. A cache backend failure must not turn into a public-data
failure.

Use a versioned key tuple:

```text
llmscatalog:v2:
  document-type:
  shop-id:
  language-id:
  product-id-or-zero:
  configuration-version:
  cache-generation:
  price-context-fingerprint
```

Hash the canonical tuple before passing it to the backend.

The price-context fingerprint includes:

- currency ID, ISO, precision, and conversion rate;
- country, state, and postcode;
- unidentified group ID, display method, price visibility, and reduction;
- tax-enabled state and resolved tax mode;
- quantity and default-combination policy;
- customer/cart zero markers;
- adapter schema version.

Index and product documents use separate keys. Product keys include the
product ID.

Do not maintain a mutable global list of cached keys. Invalidation bumps
`LLMSCATALOG_CACHE_GENERATION`; old entries become unreachable and expire
naturally. This avoids wildcard deletion and registry races.

## 11. Freshness and invalidation

The configured cache TTL remains the upper bound.

When prices are enabled:

- cap the origin-cache lifetime at five minutes;
- shorten it further to the active specific price's finite end time when
  available;
- rely on the five-minute cap for future promotion start times that are not
  selected by the current calculation.

Shared CDN lifetime is:

```text
min(remaining origin lifetime, 300 seconds)
```

Invalidate by bumping the cache generation on:

- product add, update, or delete;
- category add, update, or delete;
- stock quantity update;
- combination update, deletion, or default-combination change;
- specific-price add, update, or delete;
- specific-price-rule add, update, or delete;
- currency add, update, or delete;
- group or group-reduction change;
- tax, tax-rule, or tax-rules-group change;
- country or state change;
- shop configuration save;
- language update;
- module configuration save.

Use documented hooks such as `actionUpdateQuantity` where available and
ObjectModel after-hooks for the relevant entities. The hook set must be
asserted on both 9.0.2 and 9.1.x. A missed or third-party database update
remains bounded by TTL.

## 12. HTTP caching contract

Successful `200` and matching `304` responses send:

```http
Cache-Control: public, max-age=0, must-revalidate, s-maxage=<bounded-seconds>, no-transform
ETag: "<sha256-of-exact-body>"
Content-Language: <requested-locale>
X-Content-Type-Options: nosniff
```

Additional rules:

- Do not send `Vary: Cookie`.
- Preserve infrastructure-added `Vary: Accept-Encoding` where applicable.
- Call `disallowWriting()` on the context cookie before generation.
- Remove any `Set-Cookie` header before sending the response.
- Support only `GET` and `HEAD`.
- `HEAD` returns the same headers as `GET` without a body.
- Parse `If-None-Match`; a match returns `304` without regenerating when the
  cached record contains the ETag.
- Product responses retain their `Link: <index>; rel="describedby"` header.
- The controller must not run theme rendering or unrelated display hooks for
  these raw endpoints.

Errors send:

```http
Cache-Control: no-store
X-Content-Type-Options: nosniff
X-Robots-Tag: noindex
```

A `503` also sends `Retry-After: 60`.

## 13. Failure behavior

| Condition | Status | Cache policy |
| --- | ---: | --- |
| Module disabled | 404 | `no-store` |
| Unknown or inactive language | 404 | `no-store` |
| Missing, hidden, inaccessible, or wrong-shop product | 404 | `no-store` |
| Unsupported method | 405 with `Allow: GET, HEAD` | `no-store` |
| Invalid shop currency/country/group/tax context | 503 | `no-store` |
| Catalog query failure | 503 | `no-store` |
| Complete generation failure | 503 | `no-store` |
| One product price fails | 200, price omitted | `no-store` |
| Persistent cache unavailable | 200 generated response | Normal HTTP policy |
| Valid `If-None-Match` hit | 304 | Same public policy as the cached 200 |

Never expose exception messages, SQL, stack traces, customer identifiers,
cookies, or cart data in public output.

## 14. File-level implementation plan

| File | Planned change |
| --- | --- |
| `llmscatalog/llmscatalog.php` | Set minimum PrestaShop to 9.0.2; bump module version; register pricing, stock, tax, currency, group, and language invalidation hooks; construct new services. |
| `llmscatalog/src/ValueObject/PublicPriceContext.php` | Add immutable canonical-context DTO and fingerprint serialization. |
| `llmscatalog/src/ValueObject/PublicPrice.php` | Carry raw amount, formatted amount, ISO currency, tax mode, and optional promotion expiry. |
| `llmscatalog/src/ValueObject/PublicDocument.php` | Carry status, body, headers, ETag, expiry, and cacheability. |
| `llmscatalog/src/Service/PublicPriceContextFactory.php` | Resolve and validate shop-scoped currency, address, group, tax, and language. |
| `llmscatalog/src/Service/AnonymousContextRunner.php` | Apply and restore canonical PrestaShop context in `try/finally`. |
| `llmscatalog/src/Service/PublicPriceCalculator.php` | Isolate the full core pricing call and specific-price metadata. |
| `llmscatalog/src/Service/CatalogRepository.php` | Remove implicit `publicPrice()`; return `show_price`; enforce anonymous public access; accept explicit context. |
| `llmscatalog/src/Service/LlmsGenerator.php` | Use explicit context and price values; cache both document types; produce typed results; keep index/product formatting identical. |
| `llmscatalog/src/Service/CacheManager.php` | Use the persistent backend, v2 keys, generation-based invalidation, TTL records, and graceful bypass. |
| `llmscatalog/src/Service/UrlBuilder.php` | Resolve exact current-shop languages and reject invalid codes. |
| `llmscatalog/src/Service/PublicResponseEmitter.php` | Centralize methods, ETags, 304/HEAD behavior, headers, no-store errors, and cookie suppression. |
| `llmscatalog/controllers/front/catalogindex.php` | Become a thin raw-document controller using the response emitter. |
| `llmscatalog/controllers/front/product.php` | Use the same response pipeline and cache policy. |
| `llmscatalog/src/Service/Settings.php` | Add cache-generation access and bumping; preserve existing merchant settings. |
| `llmscatalog/upgrade/upgrade-1.1.0.php` | Register new hooks, initialize generation, and invalidate old cache entries without deleting merchant settings. |
| `phpunit.xml.dist` | Replace the single-source bootstrap with a test bootstrap capable of loading all pure services and test doubles. |
| `tests/Unit/` | Add context, key, TTL, ETag, conditional request, renderer, and failure-policy tests. |
| `tests/Integration/` | Add PrestaShop-backed price-context, hook, cache, multistore, and response tests. |
| Existing documentation | Update architecture, operations, cache ADR, and compatibility claims after production implementation. |

## 15. Test matrix

### Determinism and leakage prevention

For each endpoint, assert identical body and ETag when requests differ only by:

- anonymous versus authenticated customer;
- ordinary versus VIP customer group;
- empty versus populated cart;
- default versus alternate currency cookie;
- default versus alternate country or delivery address;
- geolocation country;
- customer-specific price;
- cart-specific price.

Assert no `Set-Cookie` and no `Vary: Cookie`.

### Pricing semantics

Cover:

- base price;
- default combination impact;
- global public promotion;
- unidentified-group promotion;
- another-group promotion excluded;
- customer-specific and cart-specific prices excluded;
- country-specific and currency-specific matching;
- nonmatching country/currency excluded;
- quantity-one rule included;
- quantity-two rule excluded;
- group reduction;
- tax-included visitor group;
- tax-excluded visitor group;
- global taxes disabled;
- ecotax;
- `show_price = false`;
- finite promotion expiry.

### Catalog and multistore

Cover:

- active/hidden/inactive products;
- unidentified-group category restrictions;
- active and inactive languages;
- two shops with different currencies, countries, taxes, domains, and
  catalogs;
- no cross-shop cache reuse;
- matching price text between index and linked product Markdown.

### Cache behavior

Cover:

- persistent backend hit and miss;
- backend unavailable;
- key changes for every fingerprint dimension;
- separate index and product keys;
- configuration-version change;
- generation bump;
- TTL cap;
- specific-price expiry cap;
- product, stock, combination, specific-price, currency, group, tax, language,
  and settings invalidation;
- concurrent first requests producing identical results.

### HTTP behavior

Cover:

- `GET`, `HEAD`, and `304`;
- quoted strong ETags;
- multiple `If-None-Match` values;
- success headers;
- no-store 404/405/503 responses;
- `Retry-After`;
- empty HEAD and 304 bodies;
- no public caching of degraded documents.

## 16. Compatibility policy

The first implementation release supports:

- PrestaShop 9.0.2;
- the latest 9.0.x patch;
- the latest stable 9.1.x patch;
- every PHP minor officially supported by those PrestaShop releases.

PrestaShop 9.2 is currently pre-release, so it is a non-blocking compatibility
job rather than a production claim
([official releases](https://github.com/PrestaShop/PrestaShop/releases)). Until
9.2 stable passes the same matrix, set `ps_versions_compliancy.max` to
`9.1.99`.

No direct database price calculation is permitted. All pricing must continue
through the supported PrestaShop core calculation path so taxes, specific
prices, group reductions, ecotax, combinations, rounding, and
price-calculation hooks remain compatible.

## 17. Rollout

1. Implement pure value objects, cache-key generation, HTTP policy, and unit
   tests.
2. Add canonical context resolution and the isolated pricing adapter.
3. Refactor repository and generators.
4. Add persistent server caching and generation-based invalidation.
5. Refactor both front controllers.
6. Run the full matrix on clean 9.0.2 and current 9.1.x shops.
7. Repeat tests with representative third-party pricing modules installed.
8. Run multistore and CDN-proxy tests.
9. Package as module `1.1.0`.
10. Purge existing CDN entries for all localized index and product Markdown
    paths during deployment. The old release advertised a one-hour public
    lifetime, so deployment is not complete until those entries are purged or
    their old lifetime has elapsed.
11. Canary requests with anonymous, logged-in, VIP, alternate-currency, and
    alternate-country sessions; compare bodies and ETags.
12. Monitor 404, 503, generation latency, cache-backend failures, and degraded
    price calculations.

Rollback must not restore unsafe shared caching. If the release must be
withdrawn:

- disable price publication or disable the module;
- purge CDN entries;
- retain the no-store/error and cookie-suppression behavior;
- do not redeploy version 1.0.0 behind public caching.

## 18. Acceptance criteria

The work is complete only when:

- the same URL returns byte-identical content and ETag across all request
  customer, cart, currency, and country variants;
- anonymous visitor promotions are included and private prices are excluded;
- currency and tax treatment are explicit;
- index and product Markdown use the same price context;
- server cache keys contain every representation dimension;
- persistent caching uses the configured backend rather than request-local
  static storage;
- successful responses revalidate in browsers and have at most five minutes
  of shared freshness;
- all errors and degraded responses are `no-store`;
- neither endpoint sends `Set-Cookie`;
- stock and pricing changes invalidate or age out within the documented bound;
- multistore responses cannot cross shops;
- the matrix passes on PrestaShop 9.0.2 and current 9.1.x;
- the deployment includes CDN purge verification.
