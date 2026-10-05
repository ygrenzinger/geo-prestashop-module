# Canonical routes and duplicate prevention

## Status

Implemented; runtime validation pending PHP-enabled CI and a PrestaShop test shop.

## Compatibility

- Module target: next minor release after `1.0.0`.
- Approved implementation release: `1.1.0` (Must grouping); runtime validation pending.
- Minimum supported PrestaShop version: **9.0.2**.
- Public output remains deterministic, localized, shop-isolated, and free of external runtime dependencies.
- Existing URLs receive bounded backward compatibility through redirects; invalid or ambiguous URLs never silently fall back.

## Problem

The initial implementation embeds a two-letter language ISO code in its module routes:

- `/{locale}/llms.txt`
- `/{locale}/llms/product/{id}/{rewrite}.md`

This conflicts with PrestaShop's own multilingual routing. In PrestaShop 9.0.2, the dispatcher recognizes and removes one leading lowercase two-letter ISO segment before matching module routes. `Link::getModuleLink()` may also prepend the same ISO segment.

Consequences include:

- generated double-language paths such as `/fr/fr/llms.txt`;
- documented single-language paths that may not match in multilingual shops;
- invalid language segments serving the current context language;
- inactive or shop-unassigned languages remaining reachable;
- arbitrary product rewrites returning the same document;
- trailing-slash, casing, legacy-controller, and query-string duplicates;
- missing canonical and language-alternate response metadata;
- publicly cacheable error responses.

The fix must establish one canonical URL per shop, locale, resource, and product rewrite without redirecting invalid resources into valid ones.

## Goals

1. Use normalized full PrestaShop locales such as `fr-FR` in canonical language path segments.
2. Accept only active languages associated with the current shop.
3. Redirect valid but noncanonical requests directly to their canonical URL.
4. Return `404` for invalid, inactive, unassigned, ambiguous, disabled, missing, or non-public resources.
5. Enforce the current localized product rewrite.
6. Publish self-canonical, reverse-representation, and hreflang `Link` metadata.
7. Preserve useful query parameters without making them part of the canonical identity.
8. Make application and HTTP caching consistent with canonical resolution.
9. Preserve unambiguous legacy URLs without preserving duplicate content.
10. Work predictably with friendly URLs enabled or disabled.

## Non-goals

- Changing product eligibility, catalog ordering, field-level language fallback, or document structure.
- Replacing PrestaShop's canonical handling for ordinary HTML pages.
- Adding new SEO configuration.
- Adding HTML-page hreflang tags that could conflict with the theme.
- Introducing customer-specific, currency-specific, or private catalog representations.
- Canonicalizing unrelated shop domains or schemes beyond using PrestaShop's configured shop base URL.

## Canonical locale identity

### Authoritative value

The canonical locale for a language is the normalized `Language::locale` value:

- language subtag lowercase;
- region subtag uppercase;
- one hyphen separator;
- example: `fr-FR`, `en-GB`, `de-DE`.

The module must derive candidates from:

```php
Language::getLanguages(true, $shopId)
```

This restricts resolution to languages that are both active and associated with the current shop.

An active language is publishable only when its stored `locale` is a valid full PrestaShop locale. The module must not synthesize a region from a two-letter ISO code. An active language with an empty or invalid locale is excluded from public routes and hreflang output and should be reported during operational validation.

`language_code` may be accepted as a legacy alias when it is a valid full locale and resolves unambiguously to the same active shop language. It is never the canonical emitted value when `locale` is available.

### Input matching

Full-locale input is matched case-insensitively, then redirected when its spelling differs from the normalized stored locale.

| Request segment | Stored locale | Result |
| --- | --- | --- |
| `fr-FR` | `fr-FR` | Canonical |
| `fr-fr` | `fr-FR` | `301` to `fr-FR` |
| `FR-fr` | `fr-FR` | `301` to `fr-FR` |
| `fr-CA` | only `fr-FR` active | `404` |
| `fr-FR` | language inactive | `404` |
| `fr-FR` | language not assigned to shop | `404` |

### Legacy ISO matching

A two-letter ISO segment is a legacy alias, never a canonical locale.

It redirects only when exactly one active language assigned to the current shop has that ISO code.

| Active shop languages | Legacy request | Result |
| --- | --- | --- |
| only `fr-FR` for ISO `fr` | `/fr/llms.txt` | `301` to `/fr-FR/llms.txt` |
| `fr-FR` and `fr-CA` share legacy ISO `fr` | `/fr/llms.txt` | `404` |
| no active `fr` language | `/fr/llms.txt` | `404` |

No default-language or context-language fallback is permitted during route resolution.

## Canonical endpoints

With friendly URLs enabled:

```text
/{locale}/llms.txt
/{locale}/llms/product/{id_product}/{rewrite}.md
```

Examples:

```text
/fr-FR/llms.txt
/fr-FR/llms/product/42/chemise-en-lin.md
```

The locale is owned by the module route. URL generation must not add PrestaShop's normal two-letter `getLangLink()` prefix.

`UrlBuilder` must generate the route through `Dispatcher::createUrl()` and prepend the configured shop base URL directly. It must not use `Link::getModuleLink()` for these canonical friendly URLs because that method adds PrestaShop's ISO-oriented language prefix.

### Friendly URLs disabled

When PrestaShop friendly URLs are disabled, the module remains functional through the canonical query-form route generated by `Dispatcher::createUrl()`.

The canonical query form must use the full locale parameter:

```text
index.php?fc=module&module=llmscatalog&controller=index&llms_locale=fr-FR
```

The product form additionally contains the canonical numeric product ID and rewrite.

Whenever a locale appears as a path segment or query value, it must be the normalized full locale. Enabling friendly URLs changes the canonical transport form from query to path; it does not change locale identity or document content.

## Route registration

The module must register:

1. Full-locale index and product routes.
2. Compatibility routes for ISO paths that PrestaShop strips before module route matching.
3. Compatibility handling for existing double-ISO paths.
4. Existing route names `module-llmscatalog-index` and `module-llmscatalog-product` wherever required by PrestaShop link generation and installed-shop compatibility.

The locale route keyword must accept full locales and two-letter legacy aliases so the controller can distinguish canonical input from redirectable legacy input. Validation against actual shop languages occurs in a resolver, not only in the route regular expression.

Compatibility routes must carry an explicit internal route-source marker. The resolver must also inspect the request path relative to the shop base URI so it can distinguish:

- canonical full-locale input;
- stripped ISO input;
- explicit ISO input;
- duplicate-language input;
- legacy query-controller input.

Routing parameters are untrusted input.

## Request resolution order

Controllers must resolve the request before invoking the generator or reading the document cache.

For both index and product endpoints:

1. Allow only `GET` and `HEAD`.
2. Determine the current shop and its active assigned languages.
3. Parse the route source and raw request path.
4. Resolve the requested full locale or unambiguous legacy alias.
5. Reject invalid, inactive, unassigned, or ambiguous language input.
6. Build the canonical URL for the resolved language and resource.
7. For products, load and validate the public product before revealing or redirecting its slug.
8. Compare the requested path with the canonical path.
9. Redirect valid noncanonical requests.
10. Generate content only for a canonical request.

`HEAD` returns the same status and headers as `GET` with no response body.

Other methods return:

```http
405 Method Not Allowed
Allow: GET, HEAD
Cache-Control: no-store
```

## Redirect and error policy

### `301 Moved Permanently`

Return `301` when the requested resource is valid and the target is unambiguous, but the URL is noncanonical.

This includes:

- unambiguous legacy ISO locale;
- incorrect locale casing;
- legacy `language_code` alias;
- current double-ISO paths such as `/fr/fr/llms.txt`;
- a redundant outer ISO plus matching full locale;
- trailing slash;
- leading zeros in a product ID;
- stale, arbitrary, differently cased, or differently normalized product rewrite;
- legacy module-controller URLs;
- legacy `llms_lang` query parameters;
- friendly/query-form migration when PrestaShop's friendly URL setting changes.

Every redirect must:

```http
Location: <absolute canonical URL>
Link: <absolute canonical URL>; rel="canonical"
Cache-Control: public, max-age=300
```

The short redirect TTL prevents a long-lived cached redirect chain when a product rewrite changes again. Every request redirects directly to the current canonical URL, never through an intermediate legacy URL.

### `404 Not Found`

Return `404` without redirect for:

- malformed locale;
- unknown locale;
- inactive language;
- language not associated with the current shop;
- ambiguous two-letter ISO alias;
- conflicting nested language values;
- invalid or overflowing product ID;
- missing product;
- product not assigned to the current shop;
- inactive product;
- product with `visibility = 'none'`;
- product whose effective rewrite cannot produce a valid non-empty slug;
- disabled module publication.

Errors use the endpoint's normal media type, a generic body, and:

```http
Cache-Control: no-store
X-Content-Type-Options: nosniff
```

They must not include canonical or hreflang links. Product existence or canonical rewrites must not be disclosed through redirects for non-public products.

## Nested-language compatibility

Legacy generated paths may contain two language components because PrestaShop supplied an outer ISO prefix and the module route supplied an inner ISO segment.

An old path redirects only when all language signals resolve to the same active shop language.

| Request | Active mapping | Result |
| --- | --- | --- |
| `/fr/fr/llms.txt` | `fr` uniquely maps to `fr-FR` | `301` to `/fr-FR/llms.txt` |
| `/fr/fr-FR/llms.txt` | both identify `fr-FR` | `301` to `/fr-FR/llms.txt` |
| `/en/fr/llms.txt` | conflicting languages | `404` |
| `/fr/de-DE/llms.txt` | conflicting languages | `404` |
| `/xx/fr/llms.txt` | outer language invalid | `404` |

The resolver must not simply discard one component.

## Product rewrite canonicalization

Product identity is the validated integer product ID. The rewrite is a canonical presentation component.

For a public product:

1. Read the effective localized `link_rewrite` using the existing field-level fallback to the shop default language.
2. Normalize it with `Tools::link_rewrite()`.
3. Require a non-empty value accepted by PrestaShop's link-rewrite rules.
4. Build the canonical Markdown URL with that normalized value.
5. Compare the requested decoded segment with the canonical segment.
6. Return `301` on any mismatch.

The route must support the same Unicode rewrite characters supported by PrestaShop 9.0.2 when accented URL characters are enabled. URL output must percent-encode path segments according to RFC 3986 without encoding path separators.

Malformed percent escapes, encoded slash or backslash separators, NUL bytes, and invalid UTF-8 return `404`.

A slug mismatch is checked only after product eligibility. This prevents canonical-slug disclosure for hidden or disabled products.

## Query-string policy

Query strings are not part of the canonical identity of an index or product document.

### Canonical requests with unrelated parameters

A canonical path with unrelated query parameters returns `200`; it does not redirect merely to remove analytics or crawler parameters.

Its canonical `Link` omits the query string.

### Path redirects

When redirecting a noncanonical path, preserve unrelated query parameters.

Remove route and dispatcher parameters, including:

```text
fc
module
controller
llms_locale
llms_lang
isolang
id_lang
id_product
rewrite
```

The destination query must be rebuilt with RFC 3986 encoding. Redirect destinations must always use the configured shop base URL and must never derive their host from untrusted request input.

Routing parameters supplied in both the path and query must not override the path identity. Conflicting route parameters are discarded when the path identifies a valid resource; conflicting language signals used to form nested legacy paths still produce `404` as defined above.

## Response metadata

### Index response

A canonical index `200` includes:

```http
Content-Type: text/plain; charset=UTF-8
X-Content-Type-Options: nosniff
Link: <canonical-index>; rel="canonical"
```

It also includes one `alternate` link per publishable active shop language:

```http
Link: <localized-index>; rel="alternate"; hreflang="fr-FR"; type="text/plain"
```

If the shop default language is active, assigned, and has a valid locale, add:

```http
Link: <default-language-index>; rel="alternate"; hreflang="x-default"; type="text/plain"
```

### Product Markdown response

A canonical product `200` includes:

```http
Content-Type: text/markdown; charset=UTF-8
X-Content-Type-Options: nosniff
Link: <canonical-markdown>; rel="canonical"; type="text/markdown"
Link: <canonical-html-product>; rel="alternate"; type="text/html"
Link: <localized-index>; rel="describedby"; type="text/plain"
```

It also includes Markdown `alternate` links for every publishable active shop language in which the product remains publicly eligible:

```http
Link: <localized-markdown>; rel="alternate"; hreflang="fr-FR"; type="text/markdown"
```

Add `x-default` only when the shop default language meets the same conditions.

The Markdown endpoint is self-canonical as a machine-readable representation. The normal HTML product remains the canonical human-facing product entity and remains identified in the Markdown body as `Canonical URL`. These are distinct concerns and are not contradictory.

### Deterministic header order

Emit links in this order:

1. self canonical;
2. HTML alternate, for product Markdown only;
3. localized index `describedby`, for product Markdown only;
4. locale alternates sorted by normalized locale;
5. `x-default`.

The implementation may use multiple `Link` header fields. It must append rather than replace previously emitted link relations.

## Reverse HTML discovery

When HTML discovery injection is enabled and publication is enabled, an eligible HTML product page retains:

```html
<link rel="alternate" type="text/markdown" href="...">
<link rel="describedby" href="...">
```

The Markdown URL uses the full canonical locale.

The module must not inject additional HTML hreflang tags. Themes and PrestaShop installations may already own that metadata, and duplicate hreflang systems could conflict.

No discovery links are emitted for inactive, hidden, unassigned, missing, or unpublishable products.

## Hreflang implications

- Hreflang values use normalized full locales, never legacy two-letter ISO aliases.
- Only active languages associated with the current shop are emitted.
- Languages without a valid full stored locale are omitted.
- Field-level content fallback does not suppress an alternate. The route still represents the requested locale even when an individual field falls back.
- Product alternates are emitted only when the product is publicly eligible in the current shop.
- Cross-shop alternates are out of scope; each shop advertises only its own localized routes.
- `x-default` points to the current shop's configured default language and is omitted if that language is not publishable.

## Cache behavior

Canonical resolution occurs before generator or cache access.

### Application cache

- Redirects, `404`, and `405` responses never read or populate the content cache.
- Index cache identity remains based on shop ID, resolved language ID, and configuration version.
- Locale aliases converge on the same language ID but are redirected before cache access.
- Query parameters do not alter application cache identity.
- Product Markdown remains generated on request unless a separate product-cache design is approved later.

### HTTP cache

Successful canonical responses use:

```http
Cache-Control: public, max-age=<configured cache TTL>
```

The HTTP TTL must come from `Settings::cacheTtl()` rather than a hard-coded value.

Public caching is valid only if price, currency, tax, availability, and URLs are generated from one deterministic anonymous public shop context. They must not vary with visitor cookies or customer state. If implementation cannot guarantee that invariant, the affected response must use private caching until the public-context defect is corrected.

Errors use `no-store`. Redirects use the five-minute TTL defined above.

No `Vary: Cookie` is emitted when the deterministic public-context invariant is satisfied.

## Backward compatibility

The implementation retains redirect-only support for:

- two-letter ISO paths from the documented `1.0.0` contract;
- double-ISO paths generated in multilingual shops;
- matching outer ISO plus inner full locale;
- legacy `llms_lang` controller parameters;
- direct module-controller URLs;
- previous product rewrites;
- case and trailing-slash variations.

Compatibility routes remain supported throughout the next module minor release series and may be removed only in a future major release with explicit migration documentation.

No compatibility behavior may return duplicate `200` content.

The implementation must:

- retain existing configuration keys;
- require no merchant data migration;
- bump the module version;
- set `ps_versions_compliancy['min']` to `9.0.2`;
- clear PrestaShop route caches during upgrade;
- clear llmscatalog content caches during upgrade;
- regenerate the packaged module ZIP only after implementation and validation.

## Proposed internal responsibilities

### `LanguageResolver`

- load active shop languages;
- normalize stored locales;
- resolve full locale, `language_code`, and ISO aliases;
- detect ambiguity and conflicting language signals;
- expose canonical locale, language ID, default-language status, and alternates.

### `CanonicalRequestResolver`

- identify path versus query route form;
- classify canonical and legacy route sources;
- validate methods;
- parse the raw path relative to the shop base URI;
- reject malformed encodings;
- filter preserved query parameters;
- return canonical, redirect, not-found, or method-not-allowed decisions.

### `UrlBuilder`

- build full-locale index and product Markdown URLs;
- use `Dispatcher::createUrl()` plus the configured shop base URL;
- avoid `getLangLink()` and `getModuleLink()` for friendly canonical paths;
- generate query-form canonical URLs when friendly URLs are disabled;
- continue using PrestaShop `Link` APIs for ordinary HTML product URLs.

### Front controllers

- apply the resolver decision before calling the generator;
- emit status, content type, cache, `Location`, `Allow`, and `Link` headers;
- suppress bodies for `HEAD`;
- avoid duplicating resolution logic between index and product controllers.

### `LinkHeaderBuilder`

- produce deterministic canonical, alternate, describedby, hreflang, and `x-default` relations;
- prevent header injection;
- append multiple `Link` fields safely.

## Test plan

### Unit tests

Cover:

- canonical locale normalization;
- active and shop-assigned filtering;
- invalid stored locale exclusion;
- full-locale case redirects;
- unique and ambiguous ISO mapping;
- conflicting nested languages;
- canonical URL construction with and without friendly URLs;
- shop base URI and multistore domains;
- routing-query filtering and preservation;
- product ID normalization;
- localized rewrite fallback and normalization;
- empty or invalid rewrite handling;
- deterministic Link header ordering;
- default-language `x-default`;
- cache-policy selection.

The implementation adds pure tests for path encoding and normalization,
shop-base path handling, query filtering, product-ID bounds, locale aliases,
invalid stored locales, and index Link relation ordering. The remaining
controller matrix below is a PHP-enabled CI/test-shop gate and has not been run.

### Controller integration matrix

For both index and product controllers, test:

| Case | Expected |
| --- | --- |
| canonical full locale | `200` |
| canonical `HEAD` | same headers, empty body |
| lowercase region | `301` |
| unique legacy ISO | `301` |
| ambiguous ISO | `404` |
| inactive locale | `404` |
| unassigned locale | `404` |
| unknown locale | `404` |
| matching double ISO | `301` |
| conflicting nested locales | `404` |
| trailing slash | `301` |
| canonical path with analytics query | `200`, query-free canonical Link |
| noncanonical path with analytics query | `301`, analytics query preserved |
| unsupported method | `405` |
| module publication disabled | `404`, `no-store` |

Product-specific cases:

| Case | Expected |
| --- | --- |
| correct current rewrite | `200` |
| stale rewrite | `301` directly to current rewrite |
| arbitrary rewrite | `301` |
| leading-zero ID | `301` |
| missing product | `404` |
| inactive product | `404` |
| visibility `none` | `404` |
| product absent from current shop | `404` |
| invalid effective rewrite | `404` |
| localized field fallback | canonical locale route remains valid |

### Header assertions

Verify:

- exact media type;
- `nosniff`;
- query-free self canonical;
- HTML reverse relation for product Markdown;
- localized index `describedby`;
- normalized full-locale hreflang values;
- no inactive or unassigned alternates;
- correct `x-default`;
- no Link metadata on errors;
- configured TTL on `200`;
- short redirect TTL;
- `no-store` on errors;
- no response body for `HEAD`.

### Cache assertions

Verify that:

- redirects do not call `LlmsGenerator`;
- errors do not call `LlmsGenerator`;
- aliases do not create cache entries;
- canonical and query-variant requests use the same application cache entry;
- cache keys remain isolated by shop and resolved language ID;
- HTTP TTL follows configuration changes;
- product or locale changes redirect directly without stale chains.

### PrestaShop 9.0.2 smoke tests

Run on a real PrestaShop 9.0.2 shop with:

- friendly URLs enabled and disabled;
- one active language;
- multiple active languages;
- default-language URL prefix enabled and disabled;
- multistore with different language assignments and domains;
- a shop installed below a base URI;
- accented URL characters enabled and disabled;
- changed product rewrites;
- disabled and hidden products;
- route and application cache warming followed by invalidation.

## Acceptance criteria

- Every valid public document has exactly one canonical URL for the current routing mode.
- Canonical language segments use normalized full PrestaShop locales.
- No invalid, inactive, unassigned, or ambiguous locale serves fallback content.
- No alternate product rewrite returns duplicate `200` content.
- Legacy valid URLs redirect in one hop.
- Invalid resources return `404` without revealing a canonical target.
- Canonical and hreflang links contain no request query parameters.
- Query preservation cannot create an open redirect or override resource identity.
- Redirects and errors bypass document generation and application caching.
- Successful cache headers use the configured TTL.
- Index and product output remain isolated by shop and language.
- The module passes syntax, unit, integration, and PrestaShop 9.0.2 smoke tests before packaging.

## Implementation sequence

1. Raise the minimum PrestaShop version to 9.0.2 and add the module upgrade step.
2. Add shop-scoped full-locale resolution.
3. Replace ISO-owned URL generation with module-owned full-locale URLs.
4. Register canonical and compatibility routes.
5. Add canonical request classification and query filtering.
6. Enforce product rewrite canonicalization after eligibility validation.
7. Centralize statuses and response headers.
8. Add self-canonical, reverse-representation, and hreflang links.
9. Align HTTP cache headers with settings and deterministic public context.
10. Update HTML discovery links.
11. Add the unit and integration test matrix.
12. Run the PrestaShop 9.0.2 smoke tests.
13. Update architecture, operations, ADR, README, and package artifacts only after the implementation passes validation.

## Source constraints

- PrestaShop 9.0.2's dispatcher recognizes a leading language prefix as exactly two lowercase letters and removes it before route matching: [Dispatcher.php](https://github.com/PrestaShop/PrestaShop/blob/9.0.2/classes/Dispatcher.php).
- PrestaShop 9.0.2 exposes full `locale`, `language_code`, shop-scoped active-language lookup, and locale lookup on `Language`: [Language.php](https://github.com/PrestaShop/PrestaShop/blob/9.0.2/classes/Language.php).
- `Link::getPageLink()` and `getModuleLink()` add PrestaShop's ISO-oriented language prefix, while `Dispatcher::createUrl()` can build the module route directly: [Link.php](https://github.com/PrestaShop/PrestaShop/blob/9.0.2/classes/Link.php).
- Module routes are registered through PrestaShop's [`moduleRoutes` hook](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/moduleroutes/).
