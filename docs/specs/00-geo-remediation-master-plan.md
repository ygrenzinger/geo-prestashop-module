# llmscatalog GEO remediation master plan

**Status:** Remediations 01–09 and Organization contact-data enrichment are implemented locally. Host and Docker suites passed for version 1.5.0; supported-version and broader runtime acceptance remain pending.
**Target:** llmscatalog 1.1.0 through 1.5.0
**Minimum PrestaShop version:** 9.0.2

## Purpose

Coordinate nine focused specifications that address reliability, discovery, coverage, and broader ecommerce search foundations in the `llmscatalog` module. Implement one child specification per change set, in the priority order below. A change set is complete only when its acceptance criteria pass in PHP-enabled CI and a PrestaShop test shop.

The module remains deterministic and dependency-free at runtime. It does not promise inclusion, ranking, or citations from generative search systems. `llms.txt` is a discovery aid; it does not replace normal SEO, structured product data, merchant feeds, or crawler access controls.

## Approved shared decisions

- Minimum supported PrestaShop version is 9.0.2.
- Each public URL has one deterministic anonymous representation. Use the shop's default currency and country/tax rules, unidentified visitor group, quantity one, and the default combination. Ignore customer, cart, cookie, geolocation, and visitor-selected currency state.
- Browsers revalidate successful responses. Shared caches may reuse them for a bounded `s-maxage`; responses use ETag and Last-Modified validators. Errors and disabled or missing documents use `Cache-Control: no-store`.
- Canonical language segments use normalized full PrestaShop locales, such as `fr-FR`. Preserve unambiguous legacy ISO paths with permanent redirects; reject unknown, inactive, ambiguous, or shop-unassigned locales.
- Availability describes the canonical/default offer, is shared across Markdown and storefront Product/Offer JSON-LD, and never publishes exact stock quantities.
- Support a design target of 10,000 public products per shop, with deterministic complete traversal through bounded indexes.
- Use one installed module with isolated publishing, audit, and optional structured-data augmentation responsibilities. The audit is read-only. Augmentation is opt-in and conflict-checked; Product fills missing Offer fields on one canonical entity, Organization fills missing contact/address fields on one homepage-matched entity, and Breadcrumb remains missing-only.
- Ship reviewed `en-US` and `fr-FR` module translations initially.

## Priority and dependency order

### Must — correctness and discovery

| Order | Specification | Outcome | Dependencies |
| --- | --- | --- | --- |
| 1 | [Price context and cache safety](01-price-context-and-cache-safety.md) | Canonical public price calculation and identical public output across visitors. | None |
| 2 | [Accurate product availability](02-accurate-product-availability.md) | One resolver for orderability, default combinations, packs, backorders, and JSON-LD parity. | 1 |
| 3 | [Full localization](07-full-localization.md) | Active-shop locale resolution, full-locale formatting and translations, and fallback disclosure. | 1; coordinate shared locale contract with 4 and 5 |
| 4 | [Canonical routes and duplicate prevention](04-canonical-routes-and-duplicates.md) | One canonical route per resource and locale, with explicit redirect and 404 behavior. | 3 |
| 5 | [Freshness and invalidation](03-freshness-and-invalidation.md) | Persistent shared cache, dependency revisions, mutation coverage, validators, and bounded staleness. | 1–4; cache schema supports later index document types |
| 6 | [llms.txt discovery](05-llms-discovery.md) | Root language directory and standard HTML/HTTP discovery relations. | 3–4 |

### Should — useful and complete catalog coverage

| Order | Specification | Outcome | Dependencies |
| --- | --- | --- | --- |
| 7 | [Large-catalog completeness](08-large-catalog-completeness.md) | Bounded root plus deterministic cursor indexes that reach every eligible product. | 4–5 |
| 8 | [Rich product Markdown](06-rich-product-markdown.md) | Bounded, accurate product identity, offer, variants, media, and optional provider data. | 1–3, 5, 7 |

### Nice to have — broader GEO foundations

| Order | Specification | Outcome | Dependencies |
| --- | --- | --- | --- |
| 9 | [Ecommerce GEO foundations](09-ecommerce-geo-foundations.md) | Audit-first diagnostics with conflict-checked structured-data augmentation; Organization fills missing contact/address fields on one matched entity, Breadcrumb remains missing-only, and Product fills missing Offer fields. | 1–8 for shared product and locale facts |

## Release grouping

- **1.1.0:** Must specifications 1–6, including the shared price, availability, locale, route, cache, and discovery contracts.
- **1.2.0:** Should specifications 7–8 for complete catalog navigation and richer product documents.
- **1.3.0:** Nice-to-have specification 9 for the audit subsystem and optional augmentation.
- **1.4.0:** Product offer completeness audit for JSON-LD/Microdata and bounded variant AggregateOffer augmentation.

- **1.4.1:** Documentation and release-process policy update; no storefront behavior change.
- **1.5.0:** Organization contact-point and postal-address enrichment from PrestaShop shop settings.

The source module declares version **1.5.0** (minimum PrestaShop **9.0.2**). Earlier local version labels are development history; cumulative current behavior is represented by source version 1.5.0. Do not downgrade an installation that has recorded a higher version without checking its deployment history. The archive is a regenerated source package; package integrity does not constitute runtime acceptance.

## Remediation and release status

| Remediation | Source implementation | Focused tests | Runtime validation | Detail |
|---|---|---|---|---|
| 01 Price context and cache safety | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version, visitor-state, and cache matrix | [Spec 01](01-price-context-and-cache-safety.md) |
| 02 Product availability | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version, stock, and catalog-mode matrix | [Spec 02](02-accurate-product-availability.md) |
| 03 Freshness and invalidation | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version and multi-node cache matrix | [Spec 03](03-freshness-and-invalidation.md) |
| 04 Canonical routes | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version route/install/upgrade matrix | [Spec 04](04-canonical-routes-and-duplicates.md) |
| 05 Discovery | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version and theme checks | [Spec 05](05-llms-discovery.md) |
| 06 Rich product Markdown | Present; off by default | Covered by passing current host and Docker suites | Pending supported-version and 10k-product checks | [Spec 06](06-rich-product-markdown.md), [guide](../rich-product-markdown.md) |
| 07 Full localization | Present in 1.3.0 source | Covered by passing current host and Docker suites | Pending supported-version locale/multistore matrix | [Spec 07](07-full-localization.md) |
| 08 Large catalog completeness | Present; enabled by new-install default | Covered by passing current host and Docker suites | Pending 10k-product performance and shop matrix | [Spec 08](08-large-catalog-completeness.md) |
| 09 GEO audit and augmentation | Present; augmentation off by default | Present; host and Docker PHPUnit passed | Pending supported-version, security, hook, and theme checks | [Spec 09](09-ecommerce-geo-foundations.md) |

For version 1.5.0 (2026-10-10), `composer lint` passed and `composer test` passed locally (106 tests; 10,735 assertions; 6 shop-dependent skips); the Docker PrestaShop 9.2.0 suite passed (106 tests; 10,761 assertions). The source archive at `outputs/llmscatalog.zip` is rebuilt from the current `llmscatalog/` directory and checked for integrity, expected archive root, excluded `.DS_Store`, and file parity. These checks do not complete supported-version install/upgrade or broader runtime acceptance. See [testing and operations](../testing-and-operations.md) for repeatable gates.

## Cross-spec implementation contract

- Resolve shop and active full locale once per request; pass the resulting context to generators, repositories, URL builders, renderers, and cache identity.
- Use the same canonical anonymous price and availability results in every representation that publishes those facts.
- Cache keys and dependency revisions must distinguish shop, locale, resource type, product/category identity, cursor, settings, price context, and relevant catalog state. Cache invalidation must work across web nodes.
- Database tables, if introduced, contain disposable derived cache/revision state only. Install, upgrade, uninstall, and rollback behavior must be idempotent and documented.
- Preserve current settings on upgrade. Enable new discovery metadata by default only on fresh installs; preserve the existing setting for upgraded shops.
- Legacy ISO routes are redirect-only compatibility paths. All new generated links use canonical full-locale routes.
- Keep each child spec self-contained, with its own tests, migration notes, acceptance criteria, and rollback behavior. Resolve conflicts in favor of this master contract and record any necessary change here.

## Validation gates

For each implementation change set:

1. Run PHP syntax checks and PHPUnit in a PHP-enabled environment.
2. Install and upgrade-test on PrestaShop 9.0.2 and the latest supported 9.1.x release.
3. Verify single-store, multistore, shared-stock, multilingual, Unicode, and RTL cases as applicable.
4. Verify output does not vary with customer, cart, cookie, geolocation, or selected currency state.
5. Verify canonical redirects, 404s, GET/HEAD behavior, response headers, caching, invalidation, and promotion boundaries.
6. For catalog traversal, verify exact-once coverage and query budgets with 10,000 eligible products.
7. For structured-data augmentation, verify Product fills only missing fields on an unambiguous canonical entity, Organization fills only missing contact/address fields on one homepage-matched entity, Breadcrumb remains missing-only, and conflicting/ambiguous markup is unchanged.
8. Validate the packaged module ZIP and perform a representative test-shop smoke test.

The current results cover the host suite and one Docker PrestaShop 9.2.0 shop. Report the supported-version, multistore, security, hook/theme, and 10,000-product gates as pending until those specific checks pass.

## Explicitly deferred

Additional reviewed translation packs, merchant-pinned or sales-based curation, CDN purge adapters, Search Console/Merchant Center/analytics API credentials, third-party marketplace adapters, and automatic editorial-page generation are outside the initial releases.
