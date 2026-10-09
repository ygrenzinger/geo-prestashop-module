# Accurate product availability

## Status

Implemented in source; PHP-enabled CI and PrestaShop test-shop validation pending.

## Compatibility

- Minimum supported PrestaShop version: 9.0.2.
- Validate against PrestaShop 9.0.2 and the latest stable 9.1.x release.
- No new runtime dependency or external service.
- No database table or merchant-facing configuration change.
- Preserve deterministic generation and shop/language-isolated output.

## Problem

The current module determines availability with:

```php
StockAvailable::getQuantityAvailableByProduct($idProduct) > 0
```

This is insufficient because it:

- Ignores `available_for_order`.
- Ignores catalog mode and hidden-price orderability.
- Treats backorders as out of stock.
- Does not resolve the shop-wide out-of-stock policy.
- Uses the aggregate product stock row for products with combinations.
- Does not respect minimum purchase quantities.
- Bypasses PrestaShop's pack quantity calculation.
- Hardcodes English labels.
- Does not invalidate the cached index when stock changes.
- Can generate personalized output when the route is requested by a logged-in customer.
- Can disagree with the `Offer.availability` value emitted by the storefront.

PrestaShop documents `StockAvailable` as the authoritative stock source and
defines `out_of_stock` as deny orders, allow orders, or inherit the global
policy. See the
[PrestaShop stock documentation](https://devdocs.prestashop-project.org/9/faq/stock/).

Availability must describe whether the canonical public offer can be ordered,
not merely whether a stock row is positive. Google likewise requires
availability to match the landing page, checkout behavior, and structured data,
and classifies products for which orders are not accepted as out of stock. See
the
[Google Merchant availability requirements](https://support.google.com/merchants/answer/6324448?hl=en).

## Decisions

### Canonical offer

A product Markdown document represents the product's canonical/default offer.

For a product with combinations:

- Price, reference, minimum quantity, stock, date, and labels come from the
  shop-specific default combination.
- Availability is not based on the sum of all combinations.
- An unavailable default combination remains unavailable even if another
  combination is orderable.
- The product document may state that another variant is orderable, but this
  does not change the canonical offer's state.

This matches the module's existing one-product-URL and one-price contract and
avoids representing multiple offers as one.

### Quantities

Exact inventory quantities are internal calculation inputs and must not be
published.

The output may publish:

- A stable availability state.
- A localized merchant-facing label.
- An availability date.
- Whether another variant is orderable.

It must not publish the numerical sellable quantity, component quantity, or
aggregate combination quantity.

### Structured data

The module may correct storefront `seo_availability` through
`actionPresentProduct`.

The correction must be conflict-safe:

- Change only `seo_availability`.
- Do not alter price, offer presence, add-to-cart state, or other presented
  product fields.
- Preserve an availability value already changed by another module.
- Apply a correction only when the current value still equals the value
  PrestaShop core would have produced from the same presented product.
- Allow modules running later in hook order to supersede the correction.

The relevant PrestaShop hook passes the presented product by reference. See the
[PrestaShop `actionPresentProduct` documentation](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionpresentproduct/).

### Anonymous public context

All llmscatalog documents use one canonical anonymous context, regardless of
the requesting browser's session.

The canonical context is:

- The route-resolved shop.
- The route-resolved active language.
- The language's full BCP 47 locale.
- The shop's default currency.
- The shop's default country.
- The configured unidentified-customer group.
- No customer ID.
- No persisted cart, address, customization, or cart quantity.

A signed-in customer and an anonymous customer requesting the same URL must
receive byte-identical output.

### Locale URLs

Generated routes use the language's full `Language::locale`, such as `en-US` or
`fr-FR`, rather than its two-character `iso_code`.

PrestaShop exposes `locale` as the BCP 47 value and distinguishes it from
`iso_code`. See the
[PrestaShop language variables](https://devdocs.prestashop-project.org/9/themes/reference/template-variables/).

Canonical routes are:

```text
/{locale}/llms.txt
/{locale}/llms/product/{id}/{rewrite}.md
```

Rules:

- Match full five-character PrestaShop locales case-insensitively.
- Generate the stored canonical casing.
- Resolve only active languages in the current shop.
- Unknown or inactive locales return `404`; they never fall back to the request
  context's language.
- Existing two-letter routes issue a permanent redirect to the full-locale
  route when the mapping is unambiguous.
- All newly generated links use only the full-locale form.
- Responses include `Content-Language` with the full locale.

## Availability model

Introduce an immutable `AvailabilityResult` containing:

```text
state
schemaUrl
localizedLabel
availabilityDate
selectedCombinationId
otherVariantsOrderable
reason
```

`reason` is diagnostic and test-facing. It is not rendered publicly.

Supported states are:

| State | Schema.org value | Meaning |
|---|---|---|
| `in_stock` | `https://schema.org/InStock` | The canonical offer can be ordered in its minimum valid quantity from current stock. |
| `backorder` | `https://schema.org/BackOrder` | Current stock is insufficient, but effective out-of-stock policy accepts orders. |
| `out_of_stock` | `https://schema.org/OutOfStock` | The offer is intended for sale, but cannot currently be ordered. |
| `not_for_sale` | `https://schema.org/OutOfStock` | The catalog entry is public, but public ordering is disabled. |

`not_for_sale` remains a separate Markdown state so consumers can distinguish
an ordering policy from depleted stock. It maps to `OutOfStock` for
`Offer.availability` because orders are not accepted.

Do not infer `PreOrder` from a future `available_date`. PrestaShop's native
presenter maps zero quantity with allowed out-of-stock ordering to `BackOrder`.
See the
[PrestaShop product presenter](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/src/Adapter/Presenter/Product/ProductLazyArray.php).

## Resolution algorithm

Resolve availability in this order.

### 1. Product eligibility

Existing eligibility remains unchanged:

- Product is active in the current shop.
- Visibility is not `none`.
- Requested product belongs to the current shop.

Inactive or hidden products are excluded from the index and their Markdown
route returns `404`.

The structured-data hook does not alter PrestaShop's `Discontinued` handling
for inactive products.

### 2. Public ordering controls

Return `not_for_sale` when any of these applies in the canonical context:

- `available_for_order` is false.
- Shop catalog mode is enabled.
- PrestaShop would not show the offer price and therefore would disable add to
  cart.

A product with `show_price=false` must not leak its price through llmscatalog
even when the module's "include prices" setting is enabled.

Required customization does not imply `not_for_sale`: a customizable product
remains orderable after the required customization is provided.

### 3. Stock management disabled

If ordering is permitted and `PS_STOCK_MANAGEMENT` is disabled, return
`in_stock`.

This follows PrestaShop's treatment of disabled stock management.

### 4. Required purchase quantity

Determine the quantity needed for the smallest valid purchase:

- Use the selected combination's minimum quantity when a default combination
  exists.
- Otherwise use the product's minimum quantity.
- Normalize invalid values below one to one.
- Do not subtract items in a visitor cart because the canonical context has no
  cart.

### 5. Sellable quantity

Use PrestaShop's product-aware quantity path:

```php
Product::getQuantity($productId, $defaultCombinationIdOrNull)
```

This is required because it:

- Honors quantity override hooks.
- Selects combination stock correctly.
- Delegates packs to `Pack::getQuantity()`.
- Applies pack-only, component-only, both, and global-default pack policies.

PrestaShop explicitly delegates pack quantity calculation through this path.
See the
[PrestaShop product quantity implementation](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/Product.php)
and
[PrestaShop pack implementation](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/Pack.php).

If calculated quantity is greater than or equal to the required purchase
quantity, return `in_stock`.

Negative quantities are treated as insufficient stock.

### 6. Backorder policy

When stock is insufficient, resolve the effective policy through:

```php
Product::isAvailableWhenOutOfStock(
    StockAvailable::outOfStock($productId, $shopId)
)
```

This handles:

- Product policy: deny.
- Product policy: allow.
- Product policy: inherit `PS_ORDER_OUT_OF_STOCK`.
- Stock management disabled, although that case has already returned earlier.

If the effective policy accepts orders, return `backorder`; otherwise return
`out_of_stock`.

### 7. Availability date

Include `available_date` only when:

- The resolved state is `backorder`.
- The value is a valid non-null PrestaShop date.
- The date is today or in the future.

Render it in ISO `YYYY-MM-DD` format.

A missing or invalid date does not change `backorder` to another state. It is
omitted and may be surfaced through diagnostics or validation logs.

A future date on an in-stock product does not change the state.

## Combinations

### Default combination

Resolve the default combination for the current shop, not globally.

Use the explicit default combination ID when valid and associated with the
shop.

A product declaring combinations but lacking a valid default combination must
not silently select an arbitrary variant or mutate catalog data during a GET
request. Resolve it conservatively as `out_of_stock`, record reason
`missing_default_combination`, and log a warning suitable for merchant
diagnosis.

### Other variants

On the detailed product Markdown route only, determine whether at least one
non-default combination is orderable.

`otherVariantsOrderable` is true if another combination resolves to either:

- `in_stock`, or
- `backorder`.

This check:

- Uses shop-associated combinations only.
- Stops after the first orderable combination.
- Does not expose the combination's quantity.
- Is omitted from compact index generation to avoid unnecessary catalog-wide
  combination scans.

Render only when the canonical combination is not orderable:

```text
- Other variants orderable: yes
```

## Packs

Pack availability must be calculated by PrestaShop, not reconstructed in the
module.

Cover all policies:

- Pack stock only.
- Component stock only.
- Both pack and component stock.
- Inherit the global pack stock setting.

The limiting component quantity and component pack ratios remain internal.

If PrestaShop throws while resolving a malformed or recursive pack, fail closed
as `out_of_stock`, record a diagnostic reason, and log the product ID without
exposing internals in the public document.

## Localized labels

Render a stable machine state separately from the localized label.

### In-stock label fallback

1. Default combination `available_now`.
2. Product `available_now`.
3. Localized `PS_LABEL_IN_STOCK_PRODUCTS`.
4. Module translation: "In stock".
5. Default-language equivalent.

### Backorder label fallback

1. Default combination `available_later`.
2. Product `available_later`.
3. Localized `PS_LABEL_OOS_PRODUCTS_BOA`.
4. Module translation: "Available on backorder".
5. Default-language equivalent.

### Out-of-stock label fallback

1. Default combination `available_later`.
2. Product `available_later`.
3. Localized `PS_LABEL_OOS_PRODUCTS_BOD`.
4. Module translation: "Out of stock".
5. Default-language equivalent.

### Not-for-sale label

Use the module translation "Not available for order".

Do not reuse `available_later`, because the reason is an ordering policy rather
than stock.

All merchant-provided labels pass through Markdown text normalization. Empty
labels continue through the fallback chain.

## Markdown output

Detailed product Markdown uses:

```text
- Availability: backorder
- Availability schema: https://schema.org/BackOrder
- Availability label: Disponible sous 7 jours
- Availability date: 2026-10-15
- Other variants orderable: yes
```

Rules:

- `Availability` and `Availability schema` are stable and unlocalized.
- `Availability label` is localized.
- `Availability date` is conditional.
- `Other variants orderable` is conditional.
- Numerical stock is never rendered.

Compact index descriptions use:

```text
Availability: Disponible sous 7 jours [backorder].
```

Both outputs consume the same `AvailabilityResult`; they must not implement
their own state logic.

## Canonical anonymous context implementation

Add a scoped context runner for llmscatalog generation.

It must:

1. Snapshot relevant `Context` properties.
2. Install the route's shop, language, locale, default currency, default
   country, unidentified group, anonymous customer, and empty cart context.
3. Flush PrestaShop's static product price cache before calculation.
4. Execute catalog generation.
5. Restore the original context in `finally`.
6. Flush the price cache again after restoration.

The runner must be used only by llmscatalog route generation. The
`actionPresentProduct` correction uses facts from the already presented
storefront product and must not replace the visitor's global context.

Tests must prove that restoration occurs after both successful generation and
exceptions.

## Structured-data correction

Register `actionPresentProduct`.

Apply the following algorithm:

1. Exit unless the module is enabled.
2. Exit unless the presented object represents a product offer.
3. Exit if the product is inactive; retain PrestaShop's `Discontinued`.
4. Exit if `show_price` is false and no `Offer` will be rendered.
5. Read the presented combination, quantity, minimum quantity,
   `available_for_order`, `allow_oosp`, and other required facts.
6. Independently calculate the native core value that `getSeoAvailability()`
   would produce.
7. Read the current `seo_availability`.
8. If the current value differs from the independently calculated native value,
   assume another module has changed it and preserve it.
9. Resolve the accurate availability for the presented offer.
10. If its Schema.org value differs from the untouched native value, replace
    only `seo_availability`.

Primary corrections include:

- Positive stock but `available_for_order=false`: `InStock` -> `OutOfStock`.
- Stock below the offer's minimum purchase quantity with backorders denied:
  `InStock` -> `OutOfStock`.
- Equivalent combination-specific cases.

On the canonical product page, the presented offer and llmscatalog's default
offer must produce the same Schema.org value.

A directly selected non-default combination may legitimately produce a
different value because it is a different offer.

## Caching and invalidation

### Application cache key

The index cache key includes:

- Shop ID.
- Stock scope: shop or shared-stock shop group.
- Language ID.
- Full locale.
- Default currency ID.
- Default country ID.
- Unidentified group ID.
- Module configuration version.
- Availability configuration fingerprint.

The fingerprint includes at least:

- `PS_STOCK_MANAGEMENT`.
- `PS_ORDER_OUT_OF_STOCK`.
- `PS_PACK_STOCK_TYPE`.
- `PS_CATALOG_MODE`.
- Default currency, country, and unidentified group.
- Localized stock-label configurations.
- Language locale.
- Module availability and price settings.

This makes global configuration changes miss the cache even on PrestaShop
9.0.2, where the later configuration-update hook is unavailable.

### Event invalidation

Register and handle:

- `actionUpdateQuantity`.
- Product add, update, and delete.
- Combination create, update, and delete.
- Product attribute update.
- Default-combination update when the hook exists.
- Category and shop changes already handled.
- Module configuration changes already handled.

`actionUpdateQuantity` is dispatched from product, combination, order, and
stock update paths. See the
[PrestaShop hook documentation](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatequantity/).

Invalidation rules:

- Clear only index keys for the affected shop.
- When stock is shared, clear index keys for every shop in the affected shop
  group.
- A component stock change invalidates the shop index, which also covers packs
  containing that component.
- Keep per-scope known-key registries; do not use wildcard cache deletion.
- Retain the configured TTL as a safety net.

### HTTP caching

Application invalidation cannot purge arbitrary browser or CDN caches.
Responses therefore use revalidation:

```text
Cache-Control: public, no-cache
ETag: "<content-hash>"
Content-Language: fr-FR
```

Honor `If-None-Match` with `304 Not Modified`.

For errors and unknown locales:

```text
Cache-Control: no-store
```

The product Markdown route can remain uncached at application level; ETag
revalidation limits transfer cost while preserving fresh stock semantics.

Remove the controllers' hardcoded one-hour HTTP freshness headers.

## Planned code structure

Add:

```text
llmscatalog/src/Availability/AvailabilityFacts.php
llmscatalog/src/Availability/AvailabilityResult.php
llmscatalog/src/Availability/AvailabilityResolver.php
llmscatalog/src/Service/AvailabilityProvider.php
llmscatalog/src/Service/CanonicalCatalogContext.php
llmscatalog/upgrade/upgrade-1.1.0.php
tests/Unit/AvailabilityResolverTest.php
tests/Unit/AvailabilityLabelTest.php
tests/Unit/LocaleRouteTest.php
```

Update:

```text
llmscatalog/llmscatalog.php
llmscatalog/src/Service/CatalogRepository.php
llmscatalog/src/Service/LlmsGenerator.php
llmscatalog/src/Service/UrlBuilder.php
llmscatalog/src/Service/CacheManager.php
llmscatalog/controllers/front/catalogindex.php
llmscatalog/controllers/front/product.php
phpunit.xml.dist
```

Responsibilities:

- `CatalogRepository`: retrieve product, default-combination, labels, and shop
  facts without deciding availability.
- `AvailabilityProvider`: call PrestaShop quantity and out-of-stock APIs and
  assemble `AvailabilityFacts`.
- `AvailabilityResolver`: pure precedence logic.
- `AvailabilityResult`: immutable output contract.
- `CanonicalCatalogContext`: isolate generation from customer sessions.
- `LlmsGenerator`: render the result only.
- `UrlBuilder`: full-locale generation, strict lookup, and legacy-route redirect
  targets.
- `CacheManager`: scoped keys, fingerprints, ETags, and targeted invalidation.
- Module entrypoint: register hooks, delegate invalidation, and perform
  conflict-safe presenter correction.

Set:

```php
$this->version = '1.1.0';
$this->ps_versions_compliancy = [
    'min' => '9.0.2',
    'max' => '9.99.99',
];
```

The upgrade script registers new hooks for existing installations. Hooks
introduced after 9.0.2 must be registered only when they already exist;
installation must not create fake core hooks.

## Test matrix

### Pure resolver tests

Cover:

- Positive quantity.
- Quantity equal to minimum.
- Positive quantity below minimum.
- Zero and negative quantity.
- Stock management disabled.
- `available_for_order=false`.
- Catalog mode.
- Hidden price.
- Backorders allowed.
- Backorders denied.
- Inherited global allow and deny policies.
- Future, current, past, null, and malformed availability dates.
- Stable state-to-Schema.org mapping.

### Combination tests

Cover:

- Default combination in stock.
- Default combination backordered.
- Default combination out of stock.
- Default unavailable while another variant is in stock.
- Default unavailable while another variant is backordered.
- Aggregate quantity positive but default quantity zero.
- Combination-specific minimum quantity.
- Combination labels overriding product labels.
- Missing default combination.
- Combination associated with another shop only.

### Pack tests

Cover:

- Pack-only stock.
- Components-only stock.
- Both stock sources.
- Inherited global pack policy.
- Component quantity limited by pack ratio.
- Backorder policy on an unavailable pack.
- Malformed pack failure behavior.

### Localization and routing tests

Cover:

- `en-US`, `fr-FR`, and mixed-case input.
- Canonical generated casing.
- Legacy ISO redirect.
- Unknown locale.
- Inactive language.
- Same ISO code mapping ambiguity.
- `Content-Language`.
- Requested-language label.
- Default-language label fallback.
- Module translation fallback.
- Label Markdown sanitization.

### Anonymous-context tests

Cover:

- Logged-in versus anonymous request byte equality.
- Customer-specific price not leaking.
- Cart quantity not reducing availability.
- Default currency and country.
- Context restoration after success.
- Context restoration after exception.
- Price-cache isolation.

### Cache tests

Cover:

- Key separation by shop, shared-stock scope, language, locale, currency,
  country, and group.
- Configuration fingerprint changes.
- `actionUpdateQuantity` invalidation.
- Shared-stock group invalidation.
- Pack component update invalidation.
- Combination and default-combination invalidation.
- TTL fallback.
- ETag and conditional `304`.
- `no-store` error responses.

### Structured-data tests

In PrestaShop runtime fixtures, parse rendered JSON-LD and compare
`Offer.availability` with the product Markdown state for:

- Simple in-stock product.
- Backordered product.
- Out-of-stock product.
- Positive stock with `available_for_order=false`.
- Quantity below minimum.
- Default combination unavailable while another is available.
- Product with no rendered Offer.
- Another module changing `seo_availability` before llmscatalog.
- Another module changing it after llmscatalog.

Run storefront checks with:

- PrestaShop 9.0.2 and its supported Classic theme.
- Latest stable PrestaShop 9.1.x and Hummingbird.
- Multistore with isolated stock.
- Multistore with shared stock.

## Rollout

1. Implement pure value objects and resolver.
2. Add provider integration for simple products, combinations, and packs.
3. Switch both Markdown surfaces to the resolver.
4. Add canonical anonymous context.
5. Introduce full-locale canonical routes and legacy redirects.
6. Add scoped cache invalidation and HTTP revalidation.
7. Add conflict-safe structured-data correction.
8. Add the `1.1.0` upgrade script.
9. Run static, unit, integration, installation, and upgrade checks.
10. Deploy to a test shop with representative catalog fixtures.
11. Compare the visible product page, add-to-cart state, JSON-LD, index entry,
    and product Markdown.
12. Roll out to one production shop before broader deployment.

No cache prewarming is required. The first request may generate the new key.

## Rollback

Rollback consists of restoring the previous module package.

There is no database schema migration. Extra registered hooks are harmless if
the restored module has no corresponding handler, but the rollback procedure
should clear llmscatalog cache keys and the PrestaShop cache.

Legacy two-letter route compatibility prevents immediate link breakage during
rollback or forward deployment.

## Acceptance criteria

Implementation is complete when:

- `available_for_order=false` never renders as `in_stock` or `backorder`.
- Backorder policy respects product and global configuration.
- Minimum purchase quantity affects availability.
- Default-combination availability is never inferred from aggregate combination
  stock.
- Packs use PrestaShop's pack-aware quantity calculation.
- Exact stock quantities never appear publicly.
- Product and index Markdown use the same availability result.
- Product Markdown uses stable states and localized labels.
- Canonical product Markdown and canonical `Offer.availability` agree.
- A prior structured-data modification by another module is preserved.
- Signed-in and anonymous requests produce identical documents.
- Generated routes use full locales.
- Invalid locales return `404` without language fallback.
- Stock movements invalidate the affected index scope.
- Shared stock invalidates all affected shops.
- Error responses are not cached.
- Installation and upgrade pass on PrestaShop 9.0.2.
- Runtime behavior passes on PrestaShop 9.0.2 and latest stable 9.1.x.
- PHP syntax, PHPUnit, package integrity, and test-shop smoke tests pass before
  release.

## Out of scope

- Publishing each combination as a separate Markdown document.
- Emitting `AggregateOffer` or multiple JSON-LD offers.
- Exact inventory publication.
- Customer-, group-, address-, or cart-specific availability.
- Carrier or geographic delivery eligibility.
- Preorder inference.
- CDN-specific purge integrations.
- Merchant-facing availability configuration beyond existing PrestaShop
  settings.

## References

- [PrestaShop Stock FAQ](https://devdocs.prestashop-project.org/9/faq/stock/)
- [PrestaShop product presenter](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/src/Adapter/Presenter/Product/ProductLazyArray.php)
- [PrestaShop Product implementation](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/Product.php)
- [PrestaShop StockAvailable implementation](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/stock/StockAvailable.php)
- [PrestaShop Pack implementation](https://raw.githubusercontent.com/PrestaShop/PrestaShop/9.1.5/classes/Pack.php)
- [PrestaShop `actionUpdateQuantity`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatequantity/)
- [PrestaShop `actionPresentProduct`](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionpresentproduct/)
- [PrestaShop language variables](https://devdocs.prestashop-project.org/9/themes/reference/template-variables/)
- [Google Merchant availability requirements](https://support.google.com/merchants/answer/6324448?hl=en)
