# llms.txt v2 discovery

Status: Implemented locally; current host and Docker PrestaShop 9.2.0 suites pass. Supported-version and theme acceptance remains pending; see the [status matrix](00-geo-remediation-master-plan.md).
Target module version: `1.1.0`  
Scope: Discovery and localization behavior for `llmscatalog`

## 1. Purpose

Strengthen discovery of the module's deterministic catalog documents while
aligning with the llms.txt v2 proposal.

The module will provide:

- A predictable shop-root `llms.txt`.
- Full-locale catalog indexes.
- Standard `alternate` and `describedby` web links.
- Equivalent HTML and HTTP-header discovery signals.
- Shop-, domain-, and language-isolated URLs in multistore installations.
- Backward-compatible installation and upgrade behavior.

The change does not alter the catalog-selection, price, availability,
size-limit, or deterministic-generation contracts.

## 2. Decisions

| Concern | Decision |
| --- | --- |
| Shop-root endpoint | Publish a small language-directory index at `/llms.txt`, relative to the current shop base URI. |
| Localized indexes | Keep catalog content in `/{locale}/llms.txt`, using the canonical PrestaShop locale such as `en-US` or `fr-FR`. |
| Page coverage | Homepage and category pages publish `describedby`. Eligible product pages publish `alternate` and `describedby`. |
| Missing homepage/category Markdown | Do not invent Markdown alternates. `alternate` is emitted only when a genuine equivalent product Markdown document exists. |
| Discovery representations | Emit the same relationships through HTML `<link>` elements and HTTP `Link` headers. |
| New-install default | Enable discovery metadata by default. |
| Upgrade behavior | Preserve the existing `LLMSCATALOG_INJECT_LINKS` value. Do not silently opt existing shops in. |
| Endpoint publication | Root and localized endpoints remain controlled by the existing module publishing setting, independently from metadata injection. |
| Robots | Do not invent an `LLMs:` robots directive or use llms.txt as an authorization mechanism. |
| Sitemap | Document optional inclusion of the root and localized indexes in an existing XML sitemap; do not create or mutate a sitemap in this release. |
| Multistore | Resolve every response and URL from the current shop, its base URI, domain, active languages, and catalog context. |
| Dependencies | Add no external runtime dependency and no external LLM call. |

## 3. Standards basis

The llms.txt v2 proposal permits `llms.txt` at a site root or subpath. A file
covers URLs beneath its path, and the most-specific applicable file wins.

It recommends:

- `rel="alternate" type="text/markdown"` for a Markdown representation of a
  page.
- `rel="describedby"` for the applicable llms.txt index.
- HTML `<link>` elements or HTTP `Link` response headers as equivalent
  discovery mechanisms.

`alternate` and `describedby` are registered web-link relations. The module
must use those relations rather than creating an unregistered `llms-txt`
relation.

References:

- [llms.txt v2 proposal](https://llmstxt.org/)
- [IANA link-relation registry](https://www.iana.org/assignments/link-relations/)
- [RFC 8288: Web Linking](https://www.rfc-editor.org/rfc/rfc8288.html)
- [RFC 9309: Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309.html)
- [Sitemaps protocol](https://www.sitemaps.org/protocol.html)
- [PrestaShop 9 language template variables](https://devdocs.prestashop-project.org/9/themes/reference/template-variables/)
- [PrestaShop 9 Link component](https://devdocs.prestashop-project.org/9/development/components/link/)

## 4. Existing behavior

Version `1.0.0` currently:

- Publishes `/{iso}/llms.txt`.
- Publishes `/{iso}/llms/product/{id}/{rewrite}.md`.
- Has no shop-root `/llms.txt`.
- Uses two-letter `Language.iso_code` in generated URLs.
- Injects discovery HTML only on product pages.
- Emits product-page HTML metadata only when
  `LLMSCATALOG_INJECT_LINKS` is enabled.
- Defaults `LLMSCATALOG_INJECT_LINKS` to disabled.
- Emits only a `describedby` HTTP header from product Markdown responses.
- Silently falls back to the current language when an unknown language token
  is requested.
- Hard-codes a one-hour public response cache header instead of using the
  configured TTL.

The discovery implementation must remove the silent language fallback and
make discovery semantics consistent across routes, HTML, and HTTP headers.

## 5. Public URL contract

### 5.1 Canonical endpoints

| Endpoint | Content type | Purpose |
| --- | --- | --- |
| `/{shop-base}/llms.txt` | `text/plain; charset=UTF-8` | Directory of the current shop's active localized indexes |
| `/{shop-base}/{locale}/llms.txt` | `text/plain; charset=UTF-8` | Localized catalog index |
| `/{shop-base}/{locale}/llms/product/{id}/{rewrite}.md` | `text/markdown; charset=UTF-8` | Localized product representation |

`{shop-base}` is the configured physical and virtual URI of the current
PrestaShop shop. It is `/` for an origin-root shop but may be `/shop-a/` for a
path-based multistore.

"Root `/llms.txt`" therefore means the root of the resolved shop, not
necessarily the root of the network origin.

Examples:

```text
https://example.com/llms.txt
https://example.com/en-US/llms.txt
https://example.com/en-US/llms/product/42/example-product.md
```

Path-based multistore:

```text
https://example.com/shop-a/llms.txt
https://example.com/shop-a/fr-FR/llms.txt
```

### 5.2 Locale identity

Canonical localized paths use `Language.locale`, preserving standard casing
such as `en-US`.

Resolution order:

1. Case-insensitive exact match against an active language's `locale`.
2. Case-insensitive exact match against its `language_code`.
3. A two-letter legacy ISO code may resolve only when it identifies exactly
   one active language for the current shop.
4. Unknown or ambiguous values return `404`; they never fall back to the
   current request language.

If `locale` is missing from a legacy language record:

1. Use a valid full `language_code`.
2. Otherwise retain the lowercase ISO code as a compatibility fallback.
3. Surface an administrative warning that the language lacks a full canonical
   locale.

Canonicalization behavior:

- Non-canonical case redirects permanently to the canonical locale path.
- A uniquely resolvable legacy ISO path redirects permanently to the canonical
  full-locale path.
- Redirect targets are generated for the current shop and domain.
- Ambiguous ISO codes return `404`.

Existing `/{iso}/llms.txt` and product Markdown URLs therefore remain
recoverable without remaining canonical.

### 5.3 Supported methods and statuses

Public document routes support `GET` and `HEAD`.

| Situation | Result |
| --- | --- |
| Module publishing enabled and resource valid | `200` |
| Canonicalization or legacy unique-ISO migration | `301` |
| Publishing disabled | `404` |
| Unknown or ambiguous locale | `404` |
| Inactive language for current shop | `404` |
| Missing, inactive, or non-public product | `404` |

A disabled endpoint must not return a successful placeholder document.

## 6. Root language-directory index

`/llms.txt` is a small deterministic index, not a duplicate of the
default-language catalog.

Its structure is:

```markdown
# Example Shop

> Language-specific llms.txt catalog indexes for Example Shop.

Select the index matching the language and locale of the requested catalog.

## Languages

- [English (United States)](https://example.com/en-US/llms.txt): Catalog index in en-US.
- [Français (France)](https://example.com/fr-FR/llms.txt): Catalog index in fr-FR.
```

Rules:

- Include only languages active for the current shop.
- Put the shop's default language first.
- Sort remaining languages deterministically by canonical locale, then
  language ID.
- Use absolute URLs generated from trusted PrestaShop shop configuration.
- Never derive the authoritative domain directly from an unvalidated `Host`
  header.
- Never list another shop's languages or URLs.
- Do not duplicate products or categories in the root document.
- Cache by shop, configuration version, and an active-language fingerprint.
- Invalidate or rotate the cache when language, shop URL, shop association, or
  module configuration changes.

The root directory remains useful for single-language shops and lists their
one localized index.

## 7. Page discovery contract

### 7.1 Relationship matrix

| Page or resource | `alternate` | `describedby` |
| --- | --- | --- |
| Homepage HTML | None | Current locale index |
| Category HTML | None | Current locale index |
| Eligible product HTML | Product Markdown | Current locale index |
| Product Markdown | None | Current locale index |
| Root language directory | None | None |
| Localized catalog index | None | None |
| Error, redirect, disabled, or non-public page | None | None |

Homepage and category pages do not receive `alternate` because this release
does not provide equivalent Markdown representations for them.

### 7.2 Homepage and category HTML

HTML:

```html
<link rel="describedby" href="https://example.com/en-US/llms.txt">
```

HTTP:

```http
Link: <https://example.com/en-US/llms.txt>; rel="describedby"
```

### 7.3 Product HTML

HTML:

```html
<link
  rel="alternate"
  type="text/markdown"
  href="https://example.com/en-US/llms/product/42/example-product.md"
>
<link rel="describedby" href="https://example.com/en-US/llms.txt">
```

HTTP:

```http
Link: <https://example.com/en-US/llms/product/42/example-product.md>; rel="alternate"; type="text/markdown", <https://example.com/en-US/llms.txt>; rel="describedby"
```

The product alternate is emitted only if the Markdown endpoint would be
public:

- The product belongs to the current shop.
- It is active.
- Its visibility is not `none`.
- Its localized document can be generated successfully.

### 7.4 Product Markdown response

The product Markdown response continues to identify its localized catalog
index:

```http
Link: <https://example.com/en-US/llms.txt>; rel="describedby"
Content-Language: en-US
Content-Type: text/markdown; charset=UTF-8
```

This intrinsic response metadata is emitted whenever the product document is
published. It is not controlled by the HTML-page discovery toggle.

### 7.5 Header behavior

The implementation must:

- Append its links without replacing `Link` headers added by PrestaShop, the
  theme, another module, a reverse proxy, or a CDN.
- Avoid duplicate link values if a hook executes more than once.
- Serialize `alternate` before `describedby` for deterministic output.
- Use absolute HTTPS URLs when the current shop is configured for SSL.
- Reject or encode control characters before header serialization.
- Emit metadata only for successful public page responses.
- Keep HTTP discovery functional even when a custom theme omits
  `displayHeader`.

## 8. PrestaShop integration design

### 8.1 Routes and controllers

Add a thin root controller for `/llms.txt`.

Retain thin localized index and product controllers, but replace permissive
language fallback with strict locale resolution.

Suggested responsibilities:

- `RootModuleFrontController`
  - Resolve the current shop.
  - Generate the language directory.
  - Set content, language-neutral headers, and configured cache TTL.
- `IndexModuleFrontController`
  - Resolve and canonicalize the requested locale.
  - Generate the localized catalog index.
  - Set `Content-Language`.
- `ProductModuleFrontController`
  - Resolve and canonicalize the locale.
  - Generate the eligible product representation.
  - Set `Content-Language` and intrinsic `describedby`.

The root route must be registered before the parameterized locale route to
avoid accidental capture.

### 8.2 Discovery hooks

Continue using `displayHeader` for HTML `<link>` elements.

Add `actionFrontControllerInitAfter` for HTTP `Link` headers. This action hook
is independent of theme markup and receives the initialized front controller.

Both hooks delegate to the same discovery service so they cannot diverge.

The resolver recognizes only:

- Homepage controller.
- Category controller.
- Product controller.

It excludes:

- Back-office pages.
- Module document controllers.
- Authentication and customer pages.
- Cart and checkout.
- Search and listing pages outside category.
- Error and not-found controllers.
- Preview or non-public product states.

### 8.3 Services

Introduce or extract the following testable responsibilities:

- `LanguageResolver`
  - Enumerates active languages for the current shop.
  - Resolves full locales.
  - Handles legacy ISO redirects.
  - Rejects ambiguity and inactive languages.
- `DiscoveryLinkBuilder`
  - Maps a page context to link objects.
  - Serializes HTML and HTTP representations.
  - Deduplicates deterministic link values.
- `RootIndexGenerator`
  - Renders the shop-root language directory.
- `UrlBuilder`
  - Adds root-index URLs.
  - Uses canonical locale tokens.
  - Generates URLs from the current shop's configured domain and base URI.
- `Settings`
  - Exposes discovery as a single semantic setting.
  - Continues reading and writing `LLMSCATALOG_INJECT_LINKS` for compatibility.

A separate configuration key is unnecessary because HTML tags and HTTP
headers are intentionally one feature.

## 9. Configuration and defaults

### 9.1 Merchant-facing setting

Rename the current form label from:

```text
Inject HTML discovery links
```

to:

```text
Publish discovery metadata on catalog pages
```

Help text:

```text
Adds standard describedby links to the homepage and category pages, and
alternate plus describedby links to public product pages. Links are published
in both HTML metadata and HTTP response headers.
```

The backing key remains:

```text
LLMSCATALOG_INJECT_LINKS
```

### 9.2 New installations

For new installations:

```text
LLMSCATALOG_INJECT_LINKS = 1
```

This is safe because it publishes only links to already-public deterministic
module resources.

The root and localized endpoints are published whenever:

```text
LLMSCATALOG_ENABLED = 1
```

### 9.3 Existing installations

The `1.1.0` upgrade must:

- Preserve an existing `LLMSCATALOG_INJECT_LINKS` value exactly.
- Seed it as disabled only if the key is unexpectedly absent.
- Register `actionFrontControllerInitAfter`.
- Register any new language-invalidation hooks.
- Clear module and PrestaShop route caches as supported.
- Preserve all titles, summaries, inclusion rules, size limits, and cache
  settings.
- Create no database table.
- Require no merchant re-entry of configuration.

For an existing installation where discovery is enabled, the upgrade expands
the feature from product-only HTML metadata to the complete page and
HTTP-header matrix.

For an existing installation where discovery is disabled, the public indexes
continue to exist when publishing is enabled, but no metadata is injected into
core HTML pages.

## 10. Multistore and domain isolation

Every request is resolved in current-shop context.

The implementation must guarantee:

- The root index lists only languages active for that shop.
- Localized catalogs query only that shop's categories and products.
- Generated links use that shop's primary domain, SSL policy, physical URI,
  and virtual URI.
- Cache keys contain the shop ID.
- No `alternate` or `describedby` link crosses into another shop.
- Shops on distinct domains each receive their own `/llms.txt`.
- Shops sharing a domain but using distinct base URIs receive separate
  shop-root indexes.
- Shop URL changes invalidate or rotate affected cached documents.

If two shops are misconfigured with the same domain and identical base URI,
the module cannot publish two distinct root resources. The administration page
should surface this as a configuration conflict rather than guessing a shop.

Configuration inheritance remains consistent with the existing shared-default
model. Runtime content and URLs remain isolated even when setting values are
inherited.

## 11. Robots and sitemap behavior

### 11.1 Robots

llms.txt is a discovery document, not an access-control mechanism.

The module must not:

- Add `Allow` or `Disallow` rules.
- Treat the presence or absence of an index as permission to crawl.
- Expose private content based on robots policy.
- Invent a non-standard `LLMs:` or `Llms:` directive.
- Add a `Sitemap:` directive pointing directly to `llms.txt`.

The Robots Exclusion Protocol is advisory crawler behavior, not authorization.
Product visibility and endpoint eligibility remain enforced by the module's
public-catalog rules.

Operational documentation should tell merchants to verify that their existing
robots rules do not unintentionally block:

```text
/llms.txt
/{locale}/llms.txt
/{locale}/llms/
```

The module will not silently rewrite a merchant-controlled `robots.txt`.

### 11.2 XML sitemaps

The root and localized indexes are stable public URLs and may be included in
the shop's existing XML sitemap.

This release will not:

- Generate a second sitemap.
- Modify an existing sitemap file.
- Depend on a particular third-party sitemap module.
- Enumerate every product Markdown endpoint in a sitemap.

The localized index already provides the bounded discovery map for product
Markdown resources.

The administration page and operations documentation should expose copyable
root and localized URLs so merchants or sitemap integrations can include them
explicitly.

A future sitemap-provider integration can be added separately when there is a
stable PrestaShop extension point and a tested provider contract.

## 12. Caching and invalidation

All public responses use the configured cache TTL rather than a hard-coded
`3600`.

Localized index cache identity continues to include:

- Shop ID.
- Language ID.
- Configuration version.

The root directory cache identity additionally includes a deterministic
fingerprint of:

- Active language IDs.
- Canonical locales.
- Default-language identity.
- Current shop domain and base URI.

Invalidate or rotate caches following:

- Product changes.
- Category changes.
- Language add, update, activation, deactivation, deletion, or shop
  association.
- Shop URL or shop-configuration changes.
- Module configuration changes.
- Module upgrade.

Redirects and `404` responses should not receive the same long-lived public
cache policy as successful documents.

## 13. Security and privacy

Discovery must never broaden data eligibility.

The change preserves these boundaries:

- Only public routes are linked.
- Only active and catalog-visible products receive alternates.
- No customer, order, customer-group-only, back-office, or unpublished content
  is exposed.
- Generated URLs come from PrestaShop link and shop configuration APIs.
- Labels and attribute values are HTML-escaped.
- HTTP header values are validated against control-character injection.
- Unknown locale input cannot select an unrelated fallback language.
- Domain selection cannot be overridden merely by supplying a forged `Host`
  header.

## 14. Test plan

### 14.1 Unit tests

Add tests for:

- Full-locale resolution.
- Case canonicalization.
- Unique legacy ISO resolution and redirect.
- Ambiguous or unknown ISO rejection.
- Inactive-language rejection.
- Missing-locale compatibility fallback and warning.
- Root index ordering with default language first.
- Root index exclusion of inactive and foreign-shop languages.
- Shop-base-aware absolute URLs.
- Discovery relationship matrix.
- Homepage/category `describedby` only.
- Product `alternate` plus `describedby`.
- Hidden, inactive, or missing product suppression.
- HTML serialization and escaping.
- HTTP `Link` serialization and header deduplication.
- Existing-header preservation.
- Root cache language fingerprint.
- Configured cache TTL propagation.
- New-install default enabled.
- Upgrade preservation of enabled, disabled, and missing settings.

### 14.2 PrestaShop integration tests

Test on supported PrestaShop 9.x versions with:

- One shop and one language.
- One shop and multiple languages.
- Multiple shops on different domains.
- Multiple shops on one domain with different base URIs.
- Friendly URLs enabled.
- Friendly URLs disabled, documenting any core limitation affecting clean
  module routes.
- SSL and non-SSL test environments.
- Default and custom themes.

Verify:

1. `/llms.txt` returns the current shop's language directory.
2. Every listed locale index returns `200`.
3. Legacy ISO routes redirect only when unambiguous.
4. Unknown locales return `404`.
5. Homepage source and headers contain `describedby`.
6. Category source and headers contain `describedby`.
7. Product source and headers contain `alternate` and `describedby`.
8. Homepage and category pages contain no false Markdown alternate.
9. Product Markdown contains intrinsic `describedby` and `Content-Language`.
10. Metadata is absent when the setting is disabled.
11. Metadata is absent from error and non-catalog pages.
12. Existing `Link` headers are preserved.
13. One shop never produces another shop's hostname, locale, or catalog
    content.
14. `GET` and `HEAD` are consistent.
15. Cached documents change after language, domain, product, category, or
    configuration updates.

### 14.3 Upgrade tests

Install `1.0.0`, then upgrade to `1.1.0` with:

- Discovery enabled.
- Discovery disabled.
- Discovery key absent.
- Multiple shops and inherited configuration.

Verify hook registration, configuration preservation, cache invalidation,
canonical redirects, and administration-form values.

### 14.4 Static and package checks

Run:

```bash
find llmscatalog tests -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit -c phpunit.xml.dist
```

Also verify:

- YAML parsing.
- Documentation links.
- ZIP integrity.
- Correct top-level `llmscatalog/` directory in the package.
- No external runtime dependency.
- No stale generated package after source changes.

Runtime installation and route validation remain mandatory because static
checks alone do not prove PrestaShop behavior.

## 15. Implementation surface

Expected files to add or modify:

```text
docs/specs/05-llms-discovery.md
README.md
docs/architecture.md
docs/testing-and-operations.md
llmscatalog/llmscatalog.php
llmscatalog/controllers/front/root.php
llmscatalog/controllers/front/catalogindex.php
llmscatalog/controllers/front/product.php
llmscatalog/src/Service/Settings.php
llmscatalog/src/Service/UrlBuilder.php
llmscatalog/src/Service/CacheManager.php
llmscatalog/src/Service/LanguageResolver.php
llmscatalog/src/Service/DiscoveryLinkBuilder.php
llmscatalog/src/Service/RootIndexGenerator.php
llmscatalog/src/Controller/Admin/ConfigurationController.php
llmscatalog/views/templates/admin/configuration.html.twig
llmscatalog/upgrade/upgrade-1.1.0.php
tests/Unit/
```

Exact class decomposition may be adjusted during implementation, but locale
resolution, root generation, and discovery serialization must remain
independently testable.

## 16. Delivery sequence

1. Commit this specification and its approved decisions.
2. Add strict locale resolution and canonical URL generation.
3. Add the root language-directory controller and generator.
4. Migrate localized URLs to canonical full locales with legacy redirects.
5. Extract the shared discovery-link builder.
6. Expand `displayHeader` to homepage, category, and eligible product pages.
7. Add HTTP headers through `actionFrontControllerInitAfter`.
8. Update new-install defaults and add the `1.1.0` upgrade script.
9. Add language and shop-URL cache invalidation.
10. Update administration help, README, architecture, and operations
    documentation.
11. Run unit, syntax, YAML, packaging, and documentation checks.
12. Validate installation and upgrade paths in a real PrestaShop 9
    environment.
13. Rebuild `outputs/llmscatalog.zip` only after validation.
14. Roll out first to a canary shop.
15. Enable discovery manually on upgraded shops that previously had it
    disabled.
16. Monitor route `404`s, redirect behavior, header duplication, cache
    freshness, and cross-shop URL correctness before wider rollout.

## 17. Rollback

Rollback to `1.0.0` requires:

- Replacing the module package with the prior artifact.
- Clearing PrestaShop route and application caches.
- Confirming localized legacy routes again.
- Removing the newly registered action hook if the downgrade process does not
  do so automatically.

No schema rollback is required because the change creates no database table
and retains the existing configuration key.

Full-locale URLs introduced during the rollout should not be promoted
externally until the canary upgrade and downgrade paths have both been
validated.

## 18. Acceptance criteria

The work is complete when:

- Each enabled shop has a deterministic shop-root `/llms.txt`.
- Root documents list only that shop's active localized indexes.
- Localized canonical paths use full PrestaShop locales where available.
- Legacy ISO routes redirect safely without ambiguous fallback.
- Homepage and category pages advertise only `describedby`.
- Eligible product pages advertise `alternate` and `describedby`.
- HTML and HTTP discovery representations agree.
- Discovery is enabled on new installations.
- Existing installations preserve their prior discovery setting.
- Robots files are neither modified nor treated as authorization.
- Sitemap guidance uses standard XML sitemap semantics.
- Domain, base-URI, language, catalog, URL, and cache isolation are verified
  across shops.
- Static, unit, upgrade, route, header, and real PrestaShop smoke tests pass.
- The packaged ZIP is rebuilt from the validated source.
