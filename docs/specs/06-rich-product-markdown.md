# Spec 06: Rich, trustworthy product Markdown

**Status:** Implemented in remediation 06; runtime acceptance pending CI and test shop  
**Date:** 2026-09-23  
**Target:** PrestaShop 9.x  
**Module:** `llmscatalog`  
**Document profile:** `llmscatalog/product-markdown/v1`

## 1. Summary

Replace the current minimal product Markdown with a bounded, deterministic
representation of the public storefront product.

The rich document will support all public core PrestaShop product types,
including combinations, packs, virtual products, and customizable products. It
will describe the product's identity, public offer, descriptions, categories,
variants, images, specifications, and selected policy information without
exposing back-office or customer data.

The implementation must remain:

- deterministic and dependency-free;
- isolated by shop and language;
- consistent with the anonymous public storefront;
- safe for catalogs containing at least 10,000 products;
- bounded by item counts and a 256 KiB hard response limit;
- extensible through validated provider contributions;
- backward-compatible through an opt-in rollout.

Core field mappings are based on PrestaShop's documented
[Product resource](https://devdocs.prestashop-project.org/9/webservice/resources/products/),
[Combination resource](https://devdocs.prestashop-project.org/9/webservice/resources/combinations/),
[category model](https://devdocs.prestashop-project.org/9/webservice/resources/categories/),
and [feature/value models](https://devdocs.prestashop-project.org/9/webservice/resources/product_features/).
Image ordering and localized legends are supported by PrestaShop's
[`Image` model](https://github.com/PrestaShop/PrestaShop/blob/develop/classes/Image.php),
while orderability must use `StockAvailable` semantics rather than treating
`quantity > 0` as the complete availability rule, as explained in the official
[stock FAQ](https://devdocs.prestashop-project.org/9/faq/stock/).

## 2. Current state and problem

The current implementation:

- reads only the base product name, reference, short description, URL rewrite,
  price, and stock;
- renders only the name, canonical URL, short description, optional price, and
  binary availability;
- retrieves `reference` but does not render it;
- flattens all description HTML and line structure;
- derives availability solely from whether quantity is greater than zero;
- uses the ambient front-office context for price calculation;
- does not bound individual product Markdown;
- does not cache product documents;
- invalidates only for broad product, category, shop, and configuration changes;
- tests only basic text normalization, link escaping, and index truncation.

Relevant implementation points are:

- `llmscatalog/src/Service/CatalogRepository.php`
- `llmscatalog/src/Service/LlmsGenerator.php`
- `llmscatalog/src/Service/MarkdownRenderer.php`
- `llmscatalog/src/Service/CacheManager.php`
- `llmscatalog/llmscatalog.php`
- `tests/Unit/MarkdownRendererTest.php`

Rich output cannot be added safely by merely selecting more database columns.
Price, stock, combinations, language fallback, storefront visibility, provider
data, invalidation, and truncation need explicit contracts.

## 3. Goals

- Represent every eligible core PrestaShop product type.
- Match what an anonymous storefront visitor would see in a canonical context.
- Include public identifiers, variants, descriptions, images, categories,
  offers, ratings, and policies when reliable sources exist.
- Preserve useful description structure without returning unsafe HTML.
- Distinguish manufacturer/brand, seller, and provider provenance.
- Avoid implying freshness or price validity that cannot be established.
- Keep response work independent of total catalog size.
- Provide stable extension points for review, marketplace, shipping, returns,
  and warranty modules.
- Preserve current minimal output until the merchant enables the rich profile.

## 4. Non-goals

- Calling an LLM or external enrichment service.
- Exposing supplier references, wholesale prices, storage locations, physical or
  reserved stock, downloads, licenses, customer customizations, or unpublished
  reviews.
- Calculating personalized, logged-in, cart-dependent, or customer-group-specific
  prices.
- Predicting shipping costs without a complete destination and cart.
- Copying arbitrary front-office hook HTML into Markdown.
- Guaranteeing crawler use, ranking, or citation.
- Enumerating all 10,000 products inside one document.
- Replacing Schema.org, XML sitemaps, or the storefront product page.

## 5. Public trust boundary

A field may be emitted only when all of these conditions hold:

1. The product is active and visible in the current shop.
2. The source is part of the current shop's catalog or an enabled provider.
3. The value is appropriate for anonymous public display.
4. The value applies to the requested language and canonical offer context.
5. The module can identify the field's meaning without inference.
6. The value passes type, size, URL-scheme, and Markdown-safety validation.

Empty, unsupported, private, ambiguous, or invalid fields are omitted. The
renderer must never invent values such as warranty duration, free shipping,
return periods, stock quantities, or review aggregates.

## 6. Canonical anonymous context

Product Markdown must not vary according to cookies, a logged-in customer, or
an active cart.

Create an immutable `CatalogContext` containing:

- current shop ID;
- requested active shop language;
- shop default currency;
- shop default country;
- anonymous/guest customer group used by the public storefront;
- tax-display mode applicable to that context;
- HTTPS/base-domain information;
- document schema version.

All prices and offers are computed explicitly from this context. Ambient
customer, cart, cookie, and currency state must not influence the result.

Cache keys must include every field that can affect rendered public output,
even when the current configuration gives it a single canonical value.

## 7. Eligibility and routing

A product document is available only when the product:

- exists;
- belongs to the current shop;
- is active in the current shop;
- has visibility other than `none`;
- has a usable localized name after fallback.

Additional routing rules:

- Unknown or inactive shop languages return `404`; they must not silently fall
  back.
- Hidden or ineligible products return the same `404` behavior as missing
  products.
- The integer product ID remains the lookup key.
- A stale or incorrect rewrite redirects to the canonical Markdown URL.
- All emitted storefront URLs are absolute and resolved for the current shop
  and requested language.
- The response declares `Content-Type: text/markdown; charset=UTF-8` and
  `Content-Language`.
- The current module-owned route remains unchanged.

## 8. Document structure

Sections with no public data are omitted. Remaining sections always use this
order:

```markdown
# Product name

> Public product information from Shop Name.

- Canonical product: [View on Shop Name](https://shop.example/...)
- Product type: Physical
- Locale: fr-FR
- Document profile: llmscatalog/product-markdown/v1

## Identity

- Brand / manufacturer: Example Brand
- Seller: [Shop Name](https://shop.example/)
- Reference / SKU: ABC-123
- EAN-13: 1234567890123
- UPC: 123456789012
- MPN: MPN-456
- Condition: New

## Offer

- Offer selection: Default variant — Blue / Medium
- Price amount: 49.90
- Currency: EUR
- Formatted price: 49,90 €
- Tax included: Yes
- Original price: 59,90 €
- Price valid until: 2026-10-31T23:59:59+01:00
- Availability: In stock
- Available date: 2026-09-30

## Description

### Short description

...

### Full description

...

## Categories

- Primary breadcrumb: [Home](...) › [Clothing](...) › [Shirts](...)
- Also listed in:
  - [New collection](...)

## Variants

| Default | Attributes | Reference / SKU | EAN-13 | MPN | Price | Availability |
| --- | --- | --- | --- | --- | --- | --- |
| Yes | Color: Blue; Size: M | ABC-BLU-M | ... | ... | 49,90 € | In stock |

> Showing 50 of 84 variants. 34 variants were omitted by the configured limit.

## Specifications

- Material: Cotton
- Sleeve length: Long

## Images

1. [Cover image](https://shop.example/img/...)
   - Alt text: Blue cotton shirt
   - Alt source: Image legend
2. [Product image](https://shop.example/img/...)
   - Alt text: Blue cotton shirt
   - Alt source: Product-name fallback

## Pack contents

- 2 × [Component product](https://shop.example/...)

## Customization

- Engraving — text, required
- Artwork — file, optional

## Ratings

- Aggregate rating: 4.6 / 5
- Review count: 127
- Source: [Public reviews](...)

## Shipping

...

## Returns and warranty

...

## Provenance and freshness

- Source scope: Anonymous public storefront
- Shop: Shop Name
- Core product record modified: 2026-09-22T14:30:00+02:00
- Optional providers: review-module, marketplace-module
- Translation fallback: Full description from en-GB
```

Labels are translated by the module. Product values remain in the requested
product language or their declared fallback language.

## 9. Core field contract

| Field | Source and rule |
| --- | --- |
| Name | Requested-language `product_lang.name`, then shop-default-language fallback |
| Canonical URL | PrestaShop product URL builder for current shop and requested language |
| Product type | Standard physical, virtual, pack, or customizable; combinations remain variants of the parent |
| Brand/manufacturer | Active current-shop manufacturer name; never use supplier as a substitute |
| Seller | Current shop name and public base URL by default; marketplace provider may supply a listing seller |
| Reference/SKU | Public product `reference`; combination reference overrides it within a variant |
| EAN-13, UPC, ISBN, MPN | Emit exact non-empty stored values; do not pad, convert, or synthesize a generic GTIN |
| Short description | Sanitized structured Markdown with field-level language fallback |
| Full description | Sanitized structured Markdown with field-level language fallback |
| Primary breadcrumb | Active public category chain for `id_category_default` |
| Other categories | Active current-shop assigned categories, bounded and deterministically ordered |
| Condition | Emit only when `show_condition` is enabled and the value is recognized |
| Core specifications | Localized feature names and values ordered by configured feature position and stable IDs |
| Images | Current-shop images, cover first and then image position |
| Image alt text | Requested-language legend, default-language legend, then product-name fallback |
| Product modified timestamp | Product/product-shop `date_upd`, labeled as a core-record timestamp rather than whole-document freshness |

Supplier reference, supplier identity, wholesale price, storage location,
low-stock thresholds, and raw quantities are never emitted.

## 10. Offer and availability semantics

### 10.1 Default offer

Top-level offer fields describe exactly one offer:

- the configured default combination when a valid default combination exists;
- otherwise the base product offer.

If combinations exist but no valid default is configured, use the base product
context and emit:

```markdown
- Offer selection: Base product; no valid default variant is configured
```

Do not select the cheapest or first in-stock variant, because that would
silently change the meaning of the product-level offer.

### 10.2 Price

Use PrestaShop's public price calculation rather than the stored base `price`
column.

The rendered price contains:

- normalized numeric amount at currency precision;
- ISO currency;
- storefront-formatted amount;
- whether the canonical context displays tax-inclusive or tax-exclusive
  pricing;
- original public amount only when a visible reduction is active;
- finite validity start/end values from the winning public specific price.

Do not emit `Price valid until` for an open-ended price. Do not include cart
rules, coupon discounts, quantity discounts requiring a larger quantity,
customer-specific prices, or logged-in group prices.

When `show_price` is false, omit price fields even if the database contains a
price.

### 10.3 Availability

Availability must combine:

- `available_for_order`;
- variant-aware `StockAvailable`;
- the out-of-stock ordering policy;
- applicable available date;
- default-offer selection.

Allowed values are:

- `In stock`
- `Available for backorder`
- `Out of stock`
- `Not available for order`

`available_date` is emitted separately and must not automatically be described
as a preorder date.

Raw quantities are not public output.

## 11. Variants

A combination is rendered as a variant with:

- default marker;
- ordered localized attribute group/value pairs;
- reference/SKU;
- EAN-13, UPC, ISBN, and MPN when present;
- public price in canonical context;
- availability;
- available date;
- one relevant public image URL when available;
- stable public storefront URL only when PrestaShop can generate one.

The renderer must not invent combination query strings or fragments. If
PrestaShop cannot provide a stable variant URL, use the parent canonical URL or
omit the variant URL.

Variant ordering is deterministic:

1. default combination;
2. configured attribute-group and attribute positions;
3. combination ID as final tie-breaker.

Default limit: 50 variants. The document reports total, included, and omitted
counts.

## 12. Product-type-specific behavior

### Physical products

Include the normal identity, offer, variants, specifications, images, and
optional policy sections.

Dimensions and weight are omitted from v1 unless they are visibly presented by
the storefront or explicitly enabled later.

### Virtual products

Include normal public product fields, but never expose:

- download file names or paths;
- download URLs;
- license information;
- download limits;
- customer-specific access details.

Shipping is omitted unless a provider supplies a meaningful public statement.

### Packs

Add a bounded `Pack contents` section containing public component product name,
quantity, and localized URL.

Do not expose an inactive or hidden component merely because it belongs to a
public pack. Such components are represented as an omitted item count.

Default pack-component limit: 50.

### Customizable products

Add public customization field labels, type (`text` or `file`), and required
status.

Never include customer-entered values, uploaded filenames, image previews, or
past customizations.

Default customization-field limit: 25.

## 13. Ratings, seller, shipping, returns, and warranty

These concepts are not uniformly represented by PrestaShop core, so they use
provider contracts.

### Core seller

For a normal single-merchant shop, the current shop is the seller. Render its
public name and base URL.

A marketplace provider may replace this with the public seller for the current
listing. Manufacturer and seller remain separate fields.

### Ratings

Only aggregate data is permitted:

- rating value;
- best and worst rating bounds;
- review/rating count;
- public source URL;
- provider identifier.

Individual review text, reviewer identity, email, avatar, order status, and
moderation state are excluded.

### Shipping

Core may contribute localized public delivery messages. Exact cost or delivery
estimates require a provider that can demonstrate they apply to the canonical
anonymous context.

Every shipping contribution requiring geography must state its destination
scope. Omit any estimate that depends on an unknown postcode, cart total,
carrier selection, or customer address.

### Returns and warranty

Only public summaries and public policy URLs are accepted. A provider must
identify whether the contribution is:

- store-wide;
- category-level;
- product-specific;
- seller-specific.

No default return period or warranty duration is inferred.

## 14. Provider architecture

Introduce a typed internal `ProductContributionProviderInterface` and a
validated hook bridge for third-party modules.

A provider contribution contains:

- provider technical ID;
- schema version;
- supported section;
- shop, language, and product scope;
- structured field values;
- public source URL where applicable;
- optional reliable `modifiedAt`;
- explicit public-visibility assertion.

Providers return structured values, never Markdown or HTML.

The module:

1. invokes providers in deterministic technical-ID order;
2. validates each contribution against the versioned section schema;
3. rejects unknown keys and unsafe URL schemes;
4. normalizes text through the standard renderer;
5. applies provider and global byte limits;
6. records accepted provider IDs in provenance;
7. omits failed providers without failing the core document;
8. records sanitized diagnostics without logging contributed content.

Providers must not perform remote network calls during the request. They may
use local or already-cached module data.

Default provider state is disabled until explicitly enabled by the merchant.

## 15. Localization

Field fallback remains independent:

1. requested active language for the current shop;
2. current shop's default language;
3. omit the field.

Never combine fragments from different languages inside one value.

When any rendered value uses fallback, add a provenance entry naming the field
and fallback locale. Provider contributions must declare their content language
and follow the same rule.

All module-owned labels must use PrestaShop translation facilities. URLs must
always remain localized to the requested route language even when a value falls
back to another language.

## 16. Description rendering and Markdown safety

Replace the current flattening behavior with a structured HTML-to-Markdown
renderer.

Preserve:

- paragraphs;
- line breaks;
- ordered and unordered lists;
- emphasis;
- safe public links;
- simple headings, demoted to remain below the document hierarchy.

Remove:

- scripts and styles;
- forms and controls;
- event attributes;
- iframes and embedded objects;
- comments;
- tracking markup;
- raw HTML;
- unsafe schemes such as `javascript:` and `data:`.

Tables may be converted to bounded rows or readable text. Malformed HTML must
produce valid UTF-8 Markdown without throwing.

All generated labels, cells, links, and provider fields must escape Markdown
metacharacters appropriate to their context.

## 17. Bounds and truncation

Default limits:

| Resource | Default |
| --- | ---: |
| Product document | 256 KiB |
| Variants | 50 |
| Images | 8 |
| Specifications | 50 |
| Other assigned categories | 10 |
| Pack components | 50 |
| Customization fields | 25 |
| Providers | 10 |
| Contribution from one provider | 16 KiB |
| Full description after conversion | 96 KiB |

The renderer builds typed sections before serialization. It must not truncate
an arbitrary byte string.

When the hard limit would be exceeded:

1. retain title, canonical URL, document profile, identity, default offer, and
   provenance;
2. retain the short description;
3. truncate the full description at a paragraph/list boundary;
4. reduce non-cover images;
5. reduce additional categories and specifications;
6. reduce non-default variants while retaining the default variant;
7. omit optional provider sections last;
8. add exact included/omitted counts.

The final UTF-8 response, including notices, must not exceed the configured byte
limit.

## 18. Performance

A request for one product must not scan the full catalog.

Requirements:

- preserve the lightweight index query; rich detail is fetched only from a
  product endpoint;
- resolve the base product by indexed product/shop keys;
- load each related collection in a bounded query, not one query per item;
- batch localized attributes, features, images, categories, and pack components;
- keep query count constant relative to total catalog size;
- perform no remote provider I/O;
- cache the completed product document.

The performance fixture must contain at least 10,000 products, including
products with more variants and images than the configured limits.
Product-render query count must remain constant when moving from a small fixture
to the 10,000-product fixture.

## 19. Cache, invalidation, and freshness

Add per-product caching.

Cache identity includes:

- shop ID;
- language ID;
- product ID;
- canonical currency;
- canonical country/group context;
- document profile version;
- relevant configuration version;
- enabled-provider fingerprint.

Invalidate affected product entries for changes to:

- product content or shop association;
- combinations and attributes;
- stock;
- images and image legends;
- feature assignments, feature names, and values;
- category assignments or category labels;
- manufacturer;
- specific prices;
- pack composition;
- customization fields;
- provider data.

Use documented core hooks such as
[`actionProductSave`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionproductsave/),
combination/attribute hooks, and
[`actionUpdateQuantity`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatequantity/),
supplemented by verified object hooks where required.

TTL remains a safety net, not the primary freshness mechanism.

### `dateModified`

`Product.date_upd` does not reliably represent changes to stock, prices,
images, reviews, or provider data. Therefore:

- render `Core product record modified` from the core product/shop record;
- do not call it whole-document `dateModified`;
- emit a document-level `dateModified` and HTTP `Last-Modified` only when every
  included freshness-sensitive source supplies a reliable modification
  timestamp;
- otherwise omit document-level `dateModified`;
- always emit a content-derived strong `ETag`.

This prevents a plausible-looking but false freshness claim.

## 20. Configuration

Add these settings:

- `Rich product Markdown` — default off;
- `Maximum product bytes` — default 262144;
- `Maximum variants` — default 50;
- `Maximum images` — default 8;
- `Maximum specifications` — default 50;
- `Maximum assigned categories` — default 10;
- `Maximum pack components` — default 50;
- `Maximum customization fields` — default 25;
- enabled provider IDs;
- optional section toggles for ratings, shipping, returns, and warranty.

Existing price and availability toggles continue to control those fields.

Validation must enforce safe upper bounds. Saving any setting bumps the
configuration version and clears affected caches.

## 21. Internal component changes

Introduce these responsibilities:

- `CatalogContextFactory` — constructs the canonical anonymous context.
- `ProductDocumentRepository` — loads core product details and bounded relations.
- `OfferResolver` — calculates the default and variant public offers.
- `AvailabilityResolver` — applies orderability and stock policy.
- `ProductDocument` and section DTOs — typed intermediate representation.
- `ProductContributionProviderInterface` — optional public contributions.
- `ProviderRegistry` and hook bridge — deterministic provider execution.
- `HtmlToMarkdownRenderer` — structured, sanitized descriptions.
- `ProductMarkdownRenderer` — serializes typed sections within limits.
- `ProductCacheManager` — product-specific keys and invalidation.
- `ProductDocumentGenerator` — orchestrates repository, resolvers, providers,
  renderer, and cache.

Keep the existing index generator separate so index requests never hydrate rich
product details.

## 22. Testing strategy

### Unit tests

Cover:

- product eligibility;
- canonical context independent of cookies/customer/cart;
- base and default-combination offer selection;
- public price and finite validity windows;
- backorder and not-for-sale availability;
- identifier mapping without GTIN transformation;
- condition visibility;
- localized field fallback and provenance;
- description HTML conversion and unsafe-scheme removal;
- image ordering and alt fallback;
- category breadcrumb ordering;
- deterministic variant ordering and limits;
- physical, virtual, pack, and customizable behavior;
- provider schema rejection and deterministic ordering;
- review privacy restrictions;
- structural truncation and exact byte ceiling;
- composite freshness and `dateModified` omission;
- cache key isolation.

### Repository and integration tests

Use fixtures for:

- two shops sharing a product with different activation, names, prices, images,
  and URLs;
- two languages with partial translations;
- standard, virtual, pack, customizable, and combination products;
- backorders and future available dates;
- active, expired, open-ended, and group-specific prices;
- hidden categories and pack components;
- malformed descriptions;
- missing image legends;
- optional providers with valid, oversized, stale, and invalid data.

Assert bounded query count and absence of cross-shop leakage.

### Snapshot tests

Maintain representative Markdown snapshots for:

- simple product;
- combination product;
- pack;
- virtual product;
- customizable product;
- localized fallback;
- truncated large product;
- provider-enriched product.

Normalize only intentionally variable data.

### PrestaShop smoke tests

On a real PrestaShop 9 shop:

1. Compare the Markdown offer with the anonymous product page.
2. Compare default and non-default combination prices and availability.
3. Verify localized URLs, descriptions, attributes, features, and image legends.
4. Confirm customer login and cookies do not change the document.
5. Confirm hidden products and components return no data.
6. Change stock, price, image, category, feature, manufacturer, and combination
   data and verify invalidation.
7. Enable and disable each provider.
8. Verify `ETag`, conditional requests, content type, language, and cache headers.
9. Exercise a 10,000-product catalog.
10. Verify the legacy minimal profile remains unchanged while rich mode is off.

## 23. Observability

Record aggregate operational metrics only:

- render duration;
- repository query count;
- cache hit/miss;
- response byte size;
- truncation count and section;
- accepted/rejected provider count;
- provider failures;
- generated `404` and `500` counts.

Do not log descriptions, provider payloads, identifiers, prices, cookies,
customer context, or review data.

Optional provider failures are fail-open: omit the affected section and preserve
the core document. Core eligibility or offer-resolution failures remain visible
as errors and must not produce partially misleading offers.

## 24. Rollout

1. Land DTOs, renderer, resolvers, and tests behind the rich-profile setting.
2. Keep rich mode disabled by default on install and upgrade.
3. Validate snapshots and repository contracts in CI.
4. Validate installation and runtime behavior in a PrestaShop 9 test shop.
5. Run the 10,000-product performance fixture.
6. Compare storefront and Markdown output for a matrix of product types and
   locales.
7. Enable providers individually after their public-data contracts are verified.
8. Enable rich mode in staging.
9. Enable it in production through the merchant setting.
10. Monitor cache, latency, truncation, provider rejection, and error metrics.
11. Retain the ability to return immediately to the minimal profile.

No URL migration is required because the product Markdown route remains stable.

## 25. Acceptance criteria

The work is complete when:

- all eligible core product types render valid bounded Markdown;
- the top-level offer matches the canonical anonymous storefront default offer;
- variants, images, specifications, categories, packs, and customization fields
  obey deterministic limits;
- full and short descriptions remain readable and safe;
- manufacturer, seller, identifiers, and condition are semantically distinct;
- optional data is emitted only through validated enabled providers;
- no customer, supplier, wholesale, internal-stock, download, or
  unpublished-review data appears;
- every URL is absolute, shop-correct, and language-correct;
- cross-shop, cross-language, customer-session, and currency leakage tests pass;
- response size never exceeds 256 KiB by default;
- product request complexity is independent of total catalog size;
- invalidation covers every core source included in the document;
- document-level `dateModified` is omitted unless it is complete and trustworthy;
- legacy output remains the default until rich mode is enabled;
- PHP lint, PHPUnit, packaging, installation, and PrestaShop smoke tests pass in
  a PHP/PrestaShop-enabled environment.

## 26. Implementation sequence

1. Add the spec and acceptance fixtures.
2. Introduce `CatalogContext`.
3. Add typed product-document DTOs.
4. Split lightweight index reads from detailed product reads.
5. Implement identity, descriptions, categories, images, and specifications.
6. Implement offer and availability resolvers.
7. Implement variants and product-type-specific sections.
8. Implement structured Markdown rendering and global budgeting.
9. Add product caching and complete invalidation.
10. Add provider interfaces and validated hook bridge.
11. Add configuration and opt-in migration.
12. Add unit, integration, snapshot, privacy, and performance tests.
13. Validate in a real PrestaShop 9 installation.
14. Update architecture, operations, ADRs, README, and package only after
    runtime validation.

## 27. Implementation record

The rich profile is gated off by default and preserves the minimal document
when disabled. The module configuration exposes bounded resource limits and
optional provider allowlisting/section switches. Rich responses reuse the
canonical anonymous offer/availability, existing product cache and revisions,
and suppress `Last-Modified` because complete source freshness cannot yet be
established. Provider contributions use structured v1 records and fail-open
validation. See [rich product Markdown operations](../rich-product-markdown.md).

Focused HTML-to-Markdown and provider-schema unit tests were added but were not
executed. Remaining acceptance checks are runtime gates: PHP lint and full
unit/integration/snapshot suites; PrestaShop 9.0.2 and supported 9.1.x install,
upgrade, and smoke tests; product-type and visitor-state comparisons; query and
memory budgets with 10,000 products; invalidation coverage; and final package
installation. ZIP creation remains with remediation 09.
