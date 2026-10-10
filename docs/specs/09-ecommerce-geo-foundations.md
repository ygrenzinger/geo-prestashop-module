# Ecommerce GEO foundations

**Status:** Implemented locally; current host and Docker PrestaShop 9.2.0 suites pass. Supported-version, security, hook, and theme acceptance remains pending. Admin UI scope is the current single-page configuration and audit interface described below.
**Target:** `llmscatalog` v1.4.0
**Updated:** 2026-10-10


> **Current UI scope:** The implemented back-office experience is one module configuration page with publishing/rich-product settings, per-shop augmentation switches, a current-shop/current-language read-only audit, cancelable batches, JSON report download, and one-time XML/TSV comparison. The save path requires a completed audit with no blocker findings and acknowledgement that the audit report was reviewed. The checkbox is an acknowledgement; the page does not show a generated proposed-JSON-LD preview pane. This interface does not implement a multi-section dashboard, interactive entity/field controls, persistent audit history, analytics measurement UX, or search-console integrations. References to those experiences elsewhere in this specification are future-work design guidance, not current acceptance criteria. Current acceptance covers only the implemented page and route behavior.

## 1. Context

`llmscatalog` publishes deterministic localized catalog Markdown through `llms.txt` and product Markdown routes. It also audits rendered structured data and offers opt-in storefront augmentation; robots, XML sitemaps, and ordinary SEO work remain outside its ownership.

This specification broadens the installed module without turning it into the authoritative owner of every ecommerce SEO surface.

Current Google guidance treats generative-search optimization as an extension of normal SEO, not a separate collection of AI-specific hacks. Google also states that `llms.txt` neither improves nor harms visibility in Google Search. Foundational crawlability, useful first-party content, ecommerce data, and technical consistency therefore matter more than adding more AI-only files. [Google generative AI optimization guide](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide)

## 2. Decision

Ship one installed module with three isolated responsibilities:

1. **Publishing**
   - Retain the existing deterministic `llms.txt` and product Markdown behavior.
   - Preserve its current public-data, localization, caching, and privacy contracts.

2. **Foundation audit**
   - Inspect ecommerce structured data, crawl surfaces, localization, images, content signals, and cross-surface entity consistency.
   - Remain read-only.
   - Explain the owning system and exact remediation for every finding.

3. **Structured-data augmentation**
   - Optional and disabled by default.
   - Complete missing Offer fields on one unambiguous canonical Product entity; supplement incomplete Microdata with a canonical Product JSON-LD node.
   - Complete missing Organization contact and postal-address details from the current shop settings on one homepage-matched entity. Never overwrite populated values or mutate malformed or ambiguous markup. Breadcrumb JSON-LD remains missing-only.

The module diagnoses but never rewrites:

- `robots.txt`;
- XML or image sitemaps;
- canonical links;
- `hreflang`;
- Merchant Center feeds;
- product descriptions, buying guides, comparisons, or FAQ content;
- analytics or Search Console configuration.

## 3. Design principles

- **Audit first:** merchants see current evidence before enabling augmentation.
- **Bounded ownership:** Product augmentation fills only absent Offer properties on an exact canonical entity; Organization augmentation fills only missing contact/address properties on one homepage-matched entity; Breadcrumb augmentation remains missing-only.
- **Rendered-output truth:** storefront checks inspect the final HTML rather than inferring behavior from database fields alone.
- **Public-context parity:** price, currency, availability, URLs, and visibility use the anonymous public shop context.
- **Conflict means no mutation:** uncertainty suppresses augmentation.
- **No external LLM:** all checks, comparisons, and generated JSON-LD are deterministic.
- **No credentials or hosted dependency:** v1 does not connect to Merchant Center, Search Console, GA4, or a remote scoring service.
- **No opaque score:** findings use explicit severities and evidence rather than a proprietary “GEO score.”
- **Shop isolation:** foundation settings, audit results, identity fields, and augmentation switches are shop-scoped.
- **No visibility promise:** passing an audit does not guarantee crawling, indexing, ranking, citation, or inclusion in an AI response.

## 4. Responsibility matrix

| Surface | Validate | Optional augmentation | Authoritative owner |
| --- | --- | --- | --- |
| Product and Offer JSON-LD / Microdata | Yes | Missing Offer fields; Microdata is supplemented with JSON-LD | Theme, existing SEO module, or `llmscatalog` when explicitly enabled |
| Organization JSON-LD | Yes | Missing contactPoint/address fields on one matching entity | Merchant/theme, or `llmscatalog` when explicitly enabled |
| Breadcrumb JSON-LD | Yes | Missing-only | Theme, or `llmscatalog` when explicitly enabled |
| Product variant prices | Yes | AggregateOffer range for bounded combination sets | Theme or `llmscatalog` when explicitly enabled |
| Merchant Center feed | Optional snapshot comparison | No | Merchant Center integration/feed provider |
| XML and image sitemaps | Yes | No | PrestaShop/core or sitemap module |
| `robots.txt` | Yes | No | Merchant/server/hosting configuration |
| AI crawler policy | Yes | No; snippets only | Merchant/server/WAF |
| Canonical links | Yes | No | Core, theme, or SEO module |
| `hreflang` | Yes | No | Core, theme, or SEO module |
| Product images and alt text | Yes | No | Catalog and theme |
| Buying/comparison/FAQ content | Structural audit | No | Merchant/editorial workflow |
| Search Console | Guided measurement | No API access | Merchant |
| AI referral analytics | Guided measurement | No data ingestion | Merchant analytics stack |

When Organization augmentation is enabled and no Organization entity already
exists, the generated entity includes the current language's shop meta
description. If that value is blank, it uses the default-language shop meta
description; when both values are blank, it omits `description`.

## 5. Module architecture

### 5.1 Existing publishing subsystem

Existing routes, generation, rendering, URLs, cache behavior, and catalog reads remain logically separate from the new functionality.

The audit layer may reuse public catalog and URL abstractions, but it must not make the Markdown generator responsible for SEO decisions.

### 5.2 Audit subsystem

Proposed services:

- `AuditRunner`
- `AuditScope`
- `PageInventory`
- `RenderedPageFetcher`
- `HtmlDocumentInspector`
- `StructuredDataInspector`
- `StructuredDataValidator`
- `ProductConsistencyAuditor`
- `OrganizationAuditor`
- `BreadcrumbAuditor`
- `RobotsAuditor`
- `SitemapAuditor`
- `CanonicalHreflangAuditor`
- `ImageAuditor`
- `ContentAuditor`
- `FeedSnapshotReader`
- `EntityConsistencyAuditor`
- `AuditIssue`
- `AuditReport`

Auditors return data objects and must not write configuration, catalog data, theme files, or public resources.

### 5.3 Augmentation subsystem

Proposed services:

- `StructuredDataAugmenter`
- `StructuredDataConflictDetector`
- `ProductSchemaBuilder`
- `OrganizationSchemaBuilder`
- `BreadcrumbSchemaBuilder`
- `JsonLdSerializer`

Augmentation uses PrestaShop’s `actionOutputHTMLBefore` hook because it receives the complete rendered front-office HTML by reference. This allows the module to inspect JSON-LD emitted by earlier theme and module rendering before deciding whether insertion is safe. [PrestaShop hook documentation](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionoutputhtmlbefore/)

For a missing Product entity, the augmenter inserts one JSON-LD script immediately before `</head>`. For a unique canonical JSON-LD Product, it fills absent Offer properties in the existing script while preserving its other data. Incomplete Microdata is retained and supplemented with a canonical Product JSON-LD node. If the document lacks a usable head, parsing fails, or a relevant conflict exists, it leaves the response unchanged.

## 6. Audit execution model

### 6.1 Scope

Each run is bound to:

- one shop;
- one or all active languages;
- the shop’s current public currency;
- the anonymous public customer context;
- an explicit rendered-page sample limit.

Database-level catalog checks inspect all active, publicly visible products. Rendered-page checks use a deterministic sample.

Default rendered sample per shop and language:

- homepage;
- up to 10 categories, ordered by depth, position, then ID;
- up to 25 products, stratified to include where available:
  - simple and combination products;
  - in-stock and out-of-stock products;
  - regular and discounted prices;
  - products with and without identifiers;
  - products with and without cover images.

The sample is stable until the underlying catalog changes.

### 6.2 Bounded execution

The current configuration page runs rendered checks in AJAX batches of at most five URLs, with progress and cancellation between requests.

Per fetch:

- maximum timeout: 5 seconds;
- maximum response body: 2 MiB;
- maximum redirects: 3;
- only `http` and `https`;
- redirects must remain on an authorized shop or feed host;
- no credentials or customer cookies;
- no arbitrary user-supplied URL fetching.

The latest report is cached by shop, language, audit-version, and configuration-version. V1 does not add audit-history tables. Reports can be downloaded as JSON before cache expiry.

## 7. Finding model

Every finding contains:

- stable issue code;
- severity;
- surface;
- shop and language;
- affected URL or entity ID;
- observed value;
- expected rule;
- evidence;
- owning system;
- remediation;
- validation source;
- whether the module can augment the missing entity;
- scan timestamp.

Severities:

- **Blocker:** the page or data cannot be reliably crawled, parsed, or reconciled.
- **High:** a material ecommerce fact is absent, contradictory, or points to the wrong entity.
- **Medium:** incomplete discovery, accessibility, localization, or recommended metadata.
- **Info:** valid state, limitation, policy choice, or manual-review item.

There is no composite score.

## 8. Structured-data validation

JSON-LD inspection must support:

- a single object;
- arrays;
- `@graph`;
- `@type` expressed as a string or array;
- linked entities referenced through `@id`;
- multiple JSON-LD script elements.

Product Microdata inspection must recognize Product/ProductGroup item scopes,
nested Offer/AggregateOffer scopes, and their public price, currency, and
availability values.

Validation has two levels:

1. Schema.org shape and internal consistency.
2. Google-specific eligibility checks for supported ecommerce features.

Future UI may link to the Rich Results Test and Schema Markup Validator; current scope reports findings and evidence in its JSON response. Google recommends the former for supported rich-result eligibility and the latter for generic Schema.org validation. [Google structured-data testing guidance](https://developers.google.com/search/docs/appearance/structured-data)

### 8.1 Product and Offer

Audit purchasable product pages for:

- one identifiable Product or ProductGroup entity;
- stable `@id`;
- localized name and description;
- canonical product URL;
- image URLs;
- SKU;
- GTIN or MPN when present in catalog data;
- brand when present;
- Offer or AggregateOffer;
- price and currency;
- availability;
- item condition where known;
- consistency with the anonymous public page context.

Structured data, landing-page data, and Merchant Center data should agree, especially for price, currency, condition, and availability. Structured data complements Merchant Center feeds but does not replace regular feed updates. [Merchant Center structured-data guidance](https://support.google.com/merchants/answer/6069143)

#### Product/Offer augmentation

Product augmentation is allowed only when:

- the page is a public, canonical product page and its rendered JSON-LD is parseable;
- the product is active and publicly visible, with a public price and currency;
- the target Product entity is unique and can be associated with the canonical URL;
- the augmentation switch is enabled for the current shop.

When there is no Product entity, emit Product JSON-LD with an Offer. When one JSON-LD Product entity exists, fill only missing Offer fields. When incomplete Product Microdata exists, retain it and supplement it with a complete JSON-LD Product node. Existing populated values are never overwritten; conflicting or ambiguous markup is reported and left unchanged.

For products with combinations, emit AggregateOffer low/high prices across the complete active shop combination set, with one public currency and aggregate availability. Use the configured variant limit; if the full set exceeds the limit or any price calculation fails, omit the range rather than publish a partial one. The module does not synthesize separate variant URLs or ProductGroup markup. [Google product-variant guidance](https://developers.google.com/search/docs/appearance/structured-data/product-variants)

Review and rating markup is never synthesized.

### 8.2 Organization

Audit the homepage and any explicitly configured organization page for:

- Organization, OnlineStore, OnlineBusiness, or LocalBusiness;
- stable `@id`;
- shop name and legal name where supplied;
- canonical homepage;
- logo;
- contact details;
- postal address where applicable;
- `sameAs` references;
- consistent identity across languages and shops;
- return, shipping, or loyalty policies when explicitly configured elsewhere.

Google recommends the most specific applicable Organization subtype and generally recommends placing organization information on the homepage or one organization page rather than every page. [Google Organization guidance](https://developers.google.com/search/docs/appearance/structured-data/organization)

#### Organization augmentation

Organization augmentation requires merchant confirmation of all non-derived fields.

Shop-scoped settings may contain:

- organization subtype;
- legal name;
- public trading name;
- logo URL;
- telephone;
- postal address;
- public contact URL;
- `sameAs` URLs.

The module may derive only the shop name, canonical homepage URL, and configured shop logo. It does not infer policies, legal identifiers, or social profiles.

If any Organization-compatible entity exists, it reports issues and emits nothing.

### 8.3 Breadcrumbs

Audit product and category pages for:

- BreadcrumbList;
- at least two ListItem entries;
- consecutive positions starting at one;
- localized names;
- absolute URLs;
- URLs matching visible and accessible hierarchy;
- no hidden or disabled category nodes.

Breadcrumb markup should represent a useful user path, not merely reproduce URL segments. [Google Breadcrumb guidance](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb)

#### Breadcrumb augmentation

When BreadcrumbList is absent:

- category pages use Home → visible ancestor categories → current category;
- product pages use Home → visible default-category ancestors → product;
- the default category must belong to the current shop and be publicly accessible;
- missing localized labels or URLs suppress augmentation.

If more than one plausible path exists, use the product’s configured default-category path. The admin explains this decision.

## 9. Merchant Center and feed boundary

The module does not:

- create a Merchant Center account;
- create, schedule, upload, or submit a feed;
- call Merchant APIs;
- manage free-listing enrollment;
- change automatic-item-update settings;
- repair feed rows;
- claim feed approval.

Optional feed diagnostics accept either:

- a one-shot admin upload; or
- an HTTPS URL on the shop host or an explicitly authorized hostname.

Uploads are not persisted. Remote inputs block IP literals, private or reserved destinations, cross-host redirects, and oversized responses.

V1 reads Google Merchant Center XML/RSS and tab-delimited snapshots using streaming parsers. It compares only:

- ID;
- item group ID;
- title;
- description;
- link;
- image link;
- price and sale price;
- availability;
- condition;
- brand;
- GTIN;
- MPN.

Findings present feed, storefront, catalog, JSON-LD, and Markdown values side by side. The module never silently declares one source correct; it identifies the mismatch and reminds the merchant that the landing page and submitted product data must agree.

## 10. XML sitemap diagnostics

Discover sitemaps from:

1. `Sitemap:` entries in `robots.txt`;
2. an optional shop-scoped configured sitemap URL.

Validate:

- successful retrieval;
- supported XML or gzip format;
- well-formed XML with external-entity resolution disabled;
- sitemap index recursion;
- absolute URLs;
- correct shop host;
- canonical URL inclusion;
- active product and category coverage;
- exclusion of inactive, private, redirecting, or `noindex` URLs;
- valid `lastmod` values when present;
- image entries where supplied;
- localized alternate annotations where supplied.

Google limits one sitemap to 50 MiB uncompressed or 50,000 URLs and treats sitemap submission as a hint rather than an indexing guarantee. [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap)

V1 streams at most 100,000 sitemap URLs per run and reports truncation. It does not generate, rewrite, ping, or submit sitemaps.

## 11. Robots and AI crawler diagnostics

Fetch the root `robots.txt` for each active shop host and show an access matrix for:

- Googlebot;
- Bingbot;
- OAI-SearchBot;
- GPTBot;
- ChatGPT-User;
- Claude search, training, and user-fetch agents;
- PerplexityBot;
- Perplexity-User.

The registry distinguishes:

- search/discovery crawlers;
- model-training crawlers;
- user-triggered fetchers;
- crawlers whose published IP ranges can be verified;
- fetchers for which robots behavior may differ.

This distinction prevents a misleading single “allow AI” toggle. OpenAI, for example, documents OAI-SearchBot and GPTBot as independent controls for search and training. [OpenAI crawler guidance](https://developers.openai.com/api/docs/bots) Perplexity similarly distinguishes its search crawler from user-triggered retrieval. [Perplexity crawler guidance](https://docs.perplexity.ai/docs/resources/perplexity-crawlers)

Default intent:

- search crawlers: allowed;
- training crawlers: no recommendation until the merchant selects a policy;
- user-triggered fetchers: informational because robots enforcement may differ.

Checks include:

- missing or unreachable file;
- malformed groups;
- broad disallow rules affecting products, categories, images, or `llms.txt`;
- conflicting user-agent groups;
- inaccessible sitemap references;
- difference between configured intent and effective rule.

The module supplies copyable example snippets but never writes `robots.txt`.

Robots rules do not prove WAF or CDN reachability. The UI explicitly labels external network access and published-IP verification as unverified.

## 12. Canonical and `hreflang` diagnostics

### Canonicals

Validate rendered pages for:

- exactly one canonical link;
- absolute HTTPS URL;
- expected shop host;
- public `200` target;
- no redirect chain;
- no canonical to an inactive or non-equivalent entity;
- agreement with sitemap and structured-data URLs;
- self-canonical behavior for localized primary pages.

Canonical annotations are signals rather than absolute directives, and sitemap inclusion is a weaker canonical signal. [Google canonical guidance](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)

### `hreflang`

Validate:

- valid language and optional region codes;
- absolute URLs;
- self-reference;
- bidirectional return links;
- equivalent product or category identity;
- canonical alignment;
- active language/shop availability;
- `x-default` where the shop has a genuine language selector or fallback page.

Google treats HTML, HTTP-header, and sitemap `hreflang` methods as equivalent and requires self and reciprocal references. The audit reports multiple simultaneously maintained methods as complexity, not as an automatic error. [Google localized-version guidance](https://developers.google.com/search/docs/specialty/international/localized-versions)

The module never inserts or modifies canonical or `hreflang` tags.

## 13. Image accessibility and discoverability

Database checks cover every active product. Rendered checks cover the sampled pages.

Validate:

- at least one accessible product image;
- successful image response;
- image URL consistency between page, Product JSON-LD, and feed;
- standard `<img>` or `<picture>` usage for primary images;
- meaningful alternative text for informative product images;
- empty `alt=""` only where an image is decorative;
- functional image alternatives that describe the action or destination;
- image links with usable accessible names;
- duplicate or filename-only alternatives as manual-review findings;
- crawl blocking affecting product images;
- image sitemap coverage where one exists.

Automated checks cannot fully determine whether an alternative is contextually correct. Empty or repetitive values therefore produce “review needed” evidence rather than automatic replacement.

W3C guidance distinguishes informative, decorative, and functional images and requires their text alternatives to reflect purpose rather than blindly describe pixels. [W3C Images Tutorial](https://www.w3.org/WAI/tutorials/images/)

The module does not generate alt text, rename files, transform images, or edit catalog captions.

## 14. First-party buying, comparison, and FAQ content

The module audits existing merchant-authored content; it does not create it.

Checks are deterministic and structural:

- product has meaningful visible description content;
- normalized descriptions are not exact duplicates across large product groups;
- specifications are exposed as text rather than only in images;
- material decision factors are present where the catalog provides them;
- shipping and return claims link to merchant-owned policy pages;
- comparison pages clearly identify compared products and criteria;
- buying guides link to relevant products and disclose authorship or expertise where supplied;
- FAQ questions and answers are visible in normal HTML;
- content is reachable through crawlable internal links.

The module does not impose arbitrary word-count thresholds or use an LLM to score quality.

Google recommends unique, useful, first-hand, non-commodity content and warns against producing many low-value pages for query variations. [Google generative AI optimization guide](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide) [Google scaled-content policy](https://developers.google.com/search/docs/essentials/spam-policies)

FAQ content remains useful to shoppers, but v1 does not emit FAQPage markup or promise an FAQ rich result. Google removed the FAQ rich-result feature in 2026. [Google documentation updates](https://developers.google.com/search/updates)

## 15. Entity consistency

Build a normalized `EntitySnapshot` per shop, language, product, and public offer.

Compare across:

- PrestaShop catalog data;
- rendered storefront HTML;
- JSON-LD;
- canonical URL;
- sitemap;
- optional feed snapshot;
- `llms.txt` product Markdown.

Fields include:

- entity ID;
- name;
- URL;
- language;
- SKU;
- GTIN;
- MPN;
- brand;
- image;
- price;
- currency;
- sale price;
- availability;
- condition.

Normalization may reconcile formatting differences such as whitespace, decimal representation, or absolute URL encoding. It must not hide substantive differences.

The report shows all observed values and the affected systems. It does not automatically overwrite any source.

Customer-group, personalized, location-dependent, tax-exempt, or negotiated prices are outside v1. Audits use the shop’s anonymous default public context and visibly state that limitation.

## 16. Admin experience (implemented scope)

The current admin experience is one Symfony configuration page. It contains the publishing and rich-product settings, per-shop augmentation switches, the audit controls, JSON report download, and one-time XML/TSV feed snapshot comparison. The audit is scoped to the current shop and language and displays the returned report as JSON. It samples the homepage, up to 10 categories, and up to 25 products. Audit work runs in batches of at most five, can be canceled between requests, and report/session state expires after 15 minutes.

When saving augmentation settings, the controller requires a completed audit for the current shop and language, no blocker findings, and acknowledgement that the audit report was reviewed. The page does not render a generated JSON-LD preview; the checkbox records review of the audit report and the deterministic conflict-checked behavior.

The page does not provide persistent audit history, configurable audit sections, dashboard metrics, visual entity inspectors, copyable crawler policy controls, external measurement integrations, or analytics ingestion.

### Future admin UX (not implemented or required for current acceptance)

The following remains design guidance for a possible later release: a multi-section dashboard for structured data, discovery, internationalization, images/content, consistency, and measurement; parsed entity and exact JSON-LD preview panels; per-field augmentation controls; authorized feed-host configuration; crawler-policy snippets; and Search Console/analytics setup guidance. None of these should be described as current product behavior.

## 17. Augmentation configuration and safeguards

All augmentation switches default to off and are shop-scoped:

- Organization;
- Breadcrumb;
- Product/Offer for eligible products, with AggregateOffer for bounded variant sets.

Enabling a switch requires:

1. a completed audit;
2. no unresolved blocker for that surface;
3. acknowledgement that the current audit report was reviewed;
4. a warning that Product augmentation fills only missing fields on one canonical entity and stands down on conflicting or ambiguous markup.

At runtime, augmentation is suppressed when:

- any JSON-LD block is malformed;
- a complete Product offer already exists, or a conflicting Product value would need replacement;
- an Organization or BreadcrumbList entity already exists;
- page type or entity identity is ambiguous;
- required source fields are missing;
- canonical or indexability checks fail;
- current shop/language cannot be resolved;
- generated JSON exceeds the bounded size;
- HTML cannot be safely modified.

Generated JSON uses `JSON_UNESCAPED_SLASHES`, `JSON_UNESCAPED_UNICODE`, and hex escaping appropriate for embedding in HTML. All URLs and strings come from validated public or merchant-confirmed inputs.

Disabling augmentation removes output on the next uncached response. No theme or catalog rollback is required.

## 18. Measurement

### 18.1 Search Console

The module provides a checklist for:

- property verification;
- Page Indexing;
- sitemap status;
- URL Inspection;
- Merchant listings;
- Product snippets;
- Core Web Vitals where relevant;
- the Generative AI performance report.

As of August 31, 2026, Google documents the generative-AI report as available worldwide. It reports impressions by page, country, date, and device for supported generative Search features. [Search Console generative-AI report](https://support.google.com/webmasters/answer/16984139)

V1 does not authenticate to Search Console or import its data.

### 18.2 AI referral analytics

Provide a configurable starter list for session-source inspection, including referrers associated with:

- ChatGPT;
- Perplexity;
- Claude;
- Microsoft Copilot;
- Gemini.

The list is guidance, not a complete attribution taxonomy. Referrers can be absent, changed, or classified as direct traffic.

Recommended measurements:

- sessions by AI referral source;
- landing page;
- engaged sessions;
- product views;
- add-to-cart;
- checkout;
- purchase;
- conversion rate and revenue;
- assisted-conversion analysis where the analytics stack supports it.

GA4 traffic-source dimensions identify source, medium, and referral acquisition, but missing referral data can be classified as direct traffic. [GA4 traffic-source guidance](https://support.google.com/analytics/answer/15567068)

### 18.3 Baseline

Before enabling augmentation:

- save the audit report;
- record 28 days of Search Console and analytics baseline where available;
- record current indexed-page and merchant-listing issue counts.

Review again after 28 and 56 days. Treat changes as correlation, not proof of module causation.

## 19. Security and privacy

- Admin routes require normal PrestaShop authorization and CSRF protection.
- Audit requests never include customer cookies or authentication headers.
- Feed fetching uses strict host authorization and SSRF controls.
- XML parsing disables external entities and network resolution.
- HTML and JSON evidence shown in admin is escaped.
- Downloaded reports contain public catalog facts and configuration evidence only.
- No customer, cart, order, private price, or account data enters reports or JSON-LD.
- No report or feed snapshot is sent to a third party.
- Untrusted page content is treated as data, never executable instructions.
- Runtime augmentation performs no network request.
- Error logging excludes feed contents and sensitive headers.

## 20. Performance

- Public requests incur no audit crawl.
- Augmentation runs only on supported page controllers.
- Parsed structured-data presence and generated JSON may be cached by shop, language, entity, and configuration version.
- Product and category changes invalidate relevant cached augmentation.
- Runtime failure is fail-open for page delivery and fail-closed for augmentation: the page is served unchanged.
- Audit batches can be canceled without leaving locks or partial configuration.
- Large feeds and sitemaps use streaming parsers.
- Truncated scans are explicitly reported.

## 21. Tests

### 21.1 Unit tests

Cover:

- JSON-LD object, array, and `@graph` parsing;
- string and array `@type`;
- malformed JSON-LD;
- duplicate entity detection;
- `@id` resolution;
- missing-only conflict decisions;
- Product/Offer mapping;
- Organization merchant-field validation;
- Breadcrumb path construction;
- price, currency, availability, and identifier comparison;
- robots group precedence;
- crawler classification;
- sitemap and sitemap-index streaming;
- canonical normalization;
- `hreflang` reciprocity and locale validation;
- image-alt classification;
- duplicate-content normalization;
- feed XML and TSV parsing;
- finding severity and serialization;
- URL authorization and SSRF rejection;
- JSON-LD HTML-safe encoding.

### 21.2 PrestaShop integration tests

Cover:

- installation and hook registration;
- `actionOutputHTMLBefore` mutation by reference;
- existing complete theme Product markup causes augmentation to stand down;
- partial canonical Product JSON-LD receives only missing Offer properties;
- incomplete Product Microdata receives a complete supplemental JSON-LD Product entity;
- malformed existing JSON-LD causes augmentation to stand down;
- simple Product/Offer injection;
- combination AggregateOffer ranges, stock aggregation, and configured bounds;
- sale, stock, currency, tax, and inactive-product cases;
- homepage Organization behavior;
- category and product breadcrumbs;
- two languages;
- two shops with distinct domains and identities;
- cache invalidation;
- admin authorization and CSRF;
- clean uninstall.

### 21.3 Compatibility smoke tests

Run against:

- a clean PrestaShop 9 shop;
- the Classic theme;
- the Hummingbird theme where supported;
- one representative third-party SEO/structured-data module;
- multistore;
- friendly URLs on and off;
- catalog mode;
- at least two languages and currencies.

### 21.4 Security tests

Include:

- arbitrary URL and private-address rejection;
- redirect-to-private-host rejection;
- XXE payloads;
- decompression and oversized-input limits;
- hostile JSON-LD strings;
- broken HTML;
- admin output escaping;
- unauthorized audit and report download;
- cross-shop data isolation.

### 21.5 Performance gates

On a representative catalog:

- normal pages with augmentation disabled show no material latency regression;
- enabled augmentation adds no network request;
- cached augmentation stays within a defined single-digit millisecond service budget excluding normal PrestaShop rendering;
- audit batches remain bounded and cancellable;
- peak memory remains stable while streaming large XML inputs.

## 22. Rollout (design guidance; runtime release gates remain pending)

### Phase 0 — implementation behind flags

- Add the audit architecture and admin navigation.
- Keep every augmentation switch off.
- Add static, unit, security, and package checks.

### Phase 1 — audit-only pilot

- Run on a staging shop with multiple languages and combinations.
- Validate issue accuracy against page source, Rich Results Test, Merchant Center diagnostics, and Search Console.
- Tune false positives.
- Require zero cross-shop leakage and zero storefront mutation.

### Phase 2 — Organization and Breadcrumb pilot

- Enable separately on a small staging scope.
- Confirm stand-down behavior with existing theme/module markup.
- Verify final HTML and rich-result validation.
- Roll back by disabling the switch.

### Phase 3 — Product/Offer pilot

- Enable on a staging shop for simple and combination products.
- Compare public price, currency, availability, canonical URL, and variant range with the rendered page and optional feed.
- Verify over-limit and conflicting markup remains unchanged and receives an audit finding.

### Phase 4 — general availability

- Keep audit enabled and augmentation opt-in.
- Document ownership boundaries and measurement limitations.
- Promote a release only after PrestaShop runtime validation succeeds; a source ZIP may be generated and inspected before that gate.

## 23. Acceptance criteria

For the implemented foundation and current single-page interface, acceptance requires that the implementation demonstrates that:

- existing `llms.txt` and product Markdown behavior remains compatible;
- audits are shop- and language-isolated;
- public catalog checks use an anonymous public context;
- Product/Offer, Organization, and Breadcrumb JSON-LD are parsed and validated;
- complete existing product markup prevents duplicate augmentation, while unambiguous missing Offer fields can be filled;
- Organization JSON-LD fills missing contact/address values from PrestaShop Contact details on one matching entity; Breadcrumb JSON-LD remains missing-only;
- malformed or ambiguous pages are never mutated;
- augmentation is disabled by default and reversible through configuration;
- robots, sitemaps, canonicals, `hreflang`, feeds, and editorial content remain read-only;
- sitemap and feed inputs are streamed and bounded;
- feed fetching is protected against SSRF;
- image findings distinguish missing, decorative, informative, and functional alternatives;
- no automatic content or FAQ page generation exists;
- current admin scope exposes settings, audit, report download, feed snapshot comparison, and the audit/no-blocker/report-review acknowledgement save gate;
- findings expose the evidence, severity, and remediation details supplied by the audit model;
- no external LLM or hosted runtime service is required;
- tests cover conflicts, multistore, localization, security, and rollback;
- full install, hook, storefront, admin, and multistore smoke tests pass in a real PrestaShop 9 environment.

The future admin UX list above is excluded from current acceptance.

## 24. Explicit non-goals

V1 does not:

- replace conventional SEO;
- claim that `llms.txt` improves Google visibility;
- guarantee rankings, citations, traffic, or sales;
- generate or submit Merchant Center feeds;
- authenticate to Google or analytics products;
- edit root crawl files or server configuration;
- manage WAF allowlists or provider IP ranges;
- generate XML sitemaps;
- insert canonical or `hreflang` tags;
- synthesize ProductGroup markup;
- synthesize reviews, ratings, return policies, shipping policies, or loyalty programs;
- create product descriptions, buying guides, comparison pages, or FAQs;
- emit FAQPage markup;
- replace or wholesale rewrite existing JSON-LD; only the bounded Product Offer merge described above is allowed;
- crawl arbitrary external sites;
- persist historical analytics or audit data;
- inspect private, group-specific, personalized, or logged-in prices;
- replace accessibility review by a qualified human.
