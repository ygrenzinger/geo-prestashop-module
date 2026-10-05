# Specification 07: Full localization

## Status

Implemented for module version `1.1.0`; PHP-enabled CI and test-shop validation pending.

Minimum supported PrestaShop version: `9.0.2`.

This specification replaces the partial localization behavior in `1.0.0`. It
covers generated documents, administration, locale resolution, formatting,
content fallback, multistore behavior, caching, upgrades, testing, and rollout.

## Goals

The module shall:

- Produce every `llms.txt` index and product Markdown document in the requested
  active shop language.
- Make every module-owned label, sentence, notice, stock term, and error message
  translatable.
- Ship human-reviewed `en-US` and `fr-FR` translations.
- Use normalized full PrestaShop locales such as `en-US`, `fr-FR`, and `pt-BR`
  in canonical public URLs.
- Preserve unambiguous legacy ISO URLs through permanent redirects.
- Format prices and numbers using the requested locale.
- Generate prices and availability from one deterministic anonymous public
  context.
- Preserve field-level catalog fallback while disclosing mixed-language output.
- Isolate documents and caches by shop, language, locale, currency, fallback,
  translation, and configuration context.
- Expose only languages that are active and assigned to the current shop.
- Remain deterministic and dependency-free, with no external translation or
  language model service.

## Non-goals

This change will not:

- Translate merchant-authored product or category content automatically.
- Bundle machine-generated translation dictionaries.
- Change catalog inclusion, product ordering, or output-size policy.
- Personalize output by visitor, customer, cookie, browser language,
  geolocation, or selected storefront currency.
- Add new catalog fields beyond those already represented.
- Guarantee inclusion or ranking by AI search systems.

## Translation ownership

English source wordings are the canonical message identifiers.

Ownership is divided as follows:

- Module maintainers own the English source wording, translation domains,
  placeholders, and string inventory.
- Reviewed translators own the bundled `fr-FR` targets.
- `en-US` and `fr-FR` XLIFF dictionaries are committed and packaged with the
  module.
- Merchants may override translations through PrestaShop's Installed Modules
  translation interface.
- Other locales initially use the defined fallback chain until reviewed
  dictionaries are added.
- Runtime machine translation is prohibited.

The module shall opt into PrestaShop's modern translation system through
`isUsingNewTranslationSystem(): bool`. PrestaShop discovers literal calls to
`trans()` and allows exported XLIFF dictionaries under `translations/<locale>/`.
See the
[PrestaShop module translation documentation](https://devdocs.prestashop-project.org/9/modules/creation/module-translation/new-system/).

## Translation domains

Use two domains:

| Domain | Purpose |
| --- | --- |
| `Modules.Llmscatalog.Shop` | Generated indexes, product Markdown, public notices, stock terms, and public error bodies |
| `Modules.Llmscatalog.Admin` | Module metadata, tab name, configuration labels, help text, buttons, validation messages, and flash messages |

All discoverable calls shall use literal English message identifiers and named
placeholders. Dynamic strings or domains must not be passed to `trans()` because
PrestaShop's extraction process cannot reliably discover them.

## Required wording inventory

### Public document wordings

The `Shop` domain shall include at least:

- `This index describes the public catalog of this shop. Product links point to concise Markdown representations.`
- `Categories`
- `Public catalog category`
- `Products`
- `Public product information from %shop_name%.`
- `Canonical URL`
- `Price`
- `Availability`
- `In stock`
- `Out of stock`
- `In stock.`
- `Currently out of stock.`
- `Price: %price%.`
- `This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.`
- `Some catalog content is shown in %fallback_locale% because it is unavailable in %requested_locale%.`
- `llms.txt is disabled`
- `Product not found`

Punctuation belongs inside the translation unit where sentence structure can
vary by language.

### Administrative wordings

The `Admin` domain shall include at least:

- `LLMs.txt for PrestaShop`
- `Publishes a localized, machine-readable catalog index for generative engines.`
- `Publish llms.txt`
- `Maximum products`
- `Maximum output bytes`
- `Cache TTL in seconds`
- `Include categories`
- `Include products`
- `Include public prices`
- `Include availability`
- `Inject HTML discovery links`
- `Title (%locale%)`
- `Summary (%locale%)`
- `Save`
- `LLMs.txt settings updated.`
- `These settings apply to all shops.`
- `This module publishes deterministic public catalog information. It does not guarantee AI-search inclusion or ranking.`

Module metadata and back-office tab names shall use the same domain rather than
the classic `$this->l()` system.

## Translation files

Create:

```text
llmscatalog/translations/
├── en-US/
│   ├── ModulesLlmscatalogAdmin.en-US.xlf
│   └── ModulesLlmscatalogShop.en-US.xlf
└── fr-FR/
    ├── ModulesLlmscatalogAdmin.fr-FR.xlf
    └── ModulesLlmscatalogShop.fr-FR.xlf
```

Requirements:

- XLIFF files must parse successfully.
- Source identifiers must exactly match PHP and Twig literals.
- Placeholders must be identical between source and target.
- No translation target may be empty.
- French translations require human review.
- Merchant database overrides must not be overwritten during upgrade.

## Locale resolution

Introduce a `LanguageResolver` returning a `ResolvedLanguage` value object
containing:

- Shop ID.
- Requested language ID.
- Canonical PrestaShop locale.
- ISO code.
- Shop-default language ID and locale.
- Whether the request requires a canonical redirect.
- Whether catalog fallback is possible.

Resolution rules:

1. Enumerate only languages that are active and assigned to the current shop.
2. Match the requested value case-insensitively against the full PrestaShop
   locale.
3. Normalize the result to the exact stored locale spelling.
4. If an ISO-only value matches exactly one active shop language, issue a
   permanent redirect to its full locale.
5. If an ISO value matches zero or multiple active shop languages, return
   `404`.
6. If a full locale is installed globally but inactive or unassigned for this
   shop, return `404`.
7. Never silently substitute the current context language.
8. Never resolve a language from browser headers or cookies.

Examples:

| Request | Active shop languages | Result |
| --- | --- | --- |
| `/fr-FR/llms.txt` | `fr-FR`, `en-US` | `200` |
| `/fr-fr/llms.txt` | `fr-FR`, `en-US` | `301` to `/fr-FR/llms.txt` |
| `/fr/llms.txt` | `fr-FR`, `en-US` | `301` to `/fr-FR/llms.txt` |
| `/en/llms.txt` | `en-US`, `en-GB` | `404` because ISO is ambiguous |
| `/de-DE/llms.txt` | `fr-FR`, `en-US` | `404` |
| `/xx/llms.txt` | Any | `404` |

Product redirects preserve the product ID and use the canonical localized
rewrite.

## Route contract

Canonical routes become:

```text
/{locale}/llms.txt
/{locale}/llms/product/{id_product}/{rewrite}.md
```

The locale route expression shall accept normalized BCP 47-style tags,
including language, script, and region subtags. The resolved value must still
correspond to an active language assigned to the current shop.

Legacy ISO routes remain redirect-only. They must never generate a second
cacheable representation.

Canonical responses shall include:

- `Content-Language: <canonical-locale>`
- UTF-8 content type.
- `X-Content-Type-Options: nosniff`
- Public cache control aligned with the configured TTL.
- The existing product-to-index `Link` relationship.

Invalid locale responses are not publicly cacheable for the normal document
TTL.

## Module-owned wording fallback

The fixed-wording fallback chain is:

1. Requested full locale.
2. Current shop's default full locale.
3. Canonical English source wording.

Implement the chain explicitly using the Symfony translation catalogue rather
than relying on an installation-wide fallback setting. Catalogue checks must
distinguish a translation defined in the requested locale from one inherited
from another locale.

The translation helper must contain literal message identifiers so wordings
remain discoverable by PrestaShop.

## Merchant content fallback

Catalog fields use:

1. Requested language.
2. Current shop's default language.
3. No value.

Apply fallback independently to:

- Product name.
- Product short description.
- Product link rewrite.
- Category name.
- Category link rewrite.
- Configured document title.
- Configured document summary.

Rules when both requested and default values are empty:

- Omit an optional description.
- Skip a category without a usable name or URL.
- Skip a product without a usable name or URL.
- Fall back to the current shop name for the document title.
- Fall back to the requested shop meta-description, then the default-language
  meta-description, then a translated deterministic public-catalog summary.

The repository shall return source-language metadata with each localized field.
SQL `COALESCE` alone is insufficient because the generator must know whether
fallback occurred.

When any emitted merchant-authored field uses the shop-default language, place
one localized notice after the introductory prose and before the first catalog
section:

```text
> Some catalog content is shown in fr-FR because it is unavailable in ar-SA.
```

Use canonical locale codes in the notice. Do not annotate every product or
field.

No fallback notice is required solely because a module-owned wording fell back
to its English source.

## Active shop languages and multistore configuration

The established contract remains:

- Settings are shared globally.
- Generated documents are isolated by shop and language.

All module settings must therefore be read and written explicitly at global
scope. They must not depend on the ambient back-office shop context. PrestaShop
configuration otherwise follows the current multistore context, so the
constraint must be explicit. See the
[PrestaShop configuration documentation](https://devdocs.prestashop-project.org/9/development/components/configuration/).

Administrative language fields are selected as follows:

- Single-shop context: languages active for that shop.
- Shop-group context: union of active languages in the group.
- All-shops context: all globally active languages.

Saving a form must merge submitted localized values into the existing global
map. Translations for languages hidden by the selected admin context must be
preserved.

The page must display a translated notice that settings apply to all shops.

Runtime fallback values such as shop name, meta-description, default language,
currency, country, and active-language assignments remain explicitly
shop-specific.

## Canonical anonymous public context

Generated output must be byte-identical for every anonymous or authenticated
request to the same shop, locale, and configuration version.

The canonical pricing context is:

- Current shop from the resolved host/shop context.
- Requested document language for text and formatting.
- Current shop's default currency.
- Current shop's default country for tax resolution.
- Customer ID `0`.
- Current shop's unidentified-customer group.
- No cart.
- No visitor-selected currency.
- No customer-specific price.
- No authenticated-customer group.
- Tax-included public price, preserving the existing public-price contract.

Create a scoped `PublicCatalogContext` service. It must supply explicit values
to core pricing APIs wherever supported. If a PrestaShop pricing API requires
global `Context`, snapshot all changed values and restore them in `finally`,
including exceptional paths.

Generated output must not depend on:

- Cookies.
- Session state.
- Customer login.
- Cart.
- Browser language.
- Geolocation.
- Query parameters unrelated to the route.
- Storefront currency selection.

## Price and number formatting

Replace `Tools::displayPrice()` with PrestaShop's locale formatter using:

- The resolved requested locale.
- The canonical shop-default currency.
- The calculated anonymous public price.

PrestaShop's locale component provides locale-sensitive `formatNumber()` and
`formatPrice()`. See the
[PrestaShop locale documentation](https://devdocs.prestashop-project.org/9/development/components/locale/).

Rendered prices retain an explicit ISO currency code for machine clarity:

```text
- Price: 1 234,56 € (EUR)
```

The same formatted value is reused in index product descriptions and product
Markdown.

Never format by casting a localized number back to a float. Calculations use raw
numeric values; localization occurs only at rendering time.

## Availability terminology

The existing boolean availability contract remains:

- Quantity greater than zero: `In stock`.
- Otherwise: `Out of stock`.

All occurrences, including sentence forms used in index descriptions, must use
translated wordings from `Modules.Llmscatalog.Shop`.

Stock is evaluated in the current shop and canonical anonymous context. Visitor
or customer state must not change it.

## Generated document structure

Localization must not change the structural `llms.txt` contract:

```text
# <localized merchant title>

> <localized merchant summary>

<localized explanatory prose>

> <optional localized fallback disclosure>

## <localized Categories heading>
- [...]

## <localized Products heading>
- [...]
```

Product documents retain:

```text
# <localized product name>

> <localized module prose with shop name>.

- <localized Canonical URL label>: ...
- <localized Price label>: ...
- <localized Availability label>: ...
```

Links and Markdown syntax remain ASCII structural tokens. Text remains UTF-8.

## Unicode, RTL, and truncation

The renderer must preserve:

- Arabic and Hebrew RTL text.
- CJK text.
- Combining characters.
- Accented Latin text.
- Emoji.
- Non-breaking and narrow non-breaking spaces produced by locale formatters.

Do not inject HTML direction attributes into plain Markdown. Do not
transliterate or strip non-Latin characters.

Replace byte-level arbitrary substring truncation with block-aware UTF-8-safe
truncation:

1. Reserve space for the localized truncation notice.
2. Append complete Markdown blocks or list entries while they fit.
3. Never split a UTF-8 sequence.
4. Never split a Markdown link.
5. Skip an oversized optional block rather than emitting malformed Markdown.
6. Guarantee the final byte length is at most the configured limit.

Byte limits remain byte limits, not character limits.

## Cache design

Cache both index and product Markdown documents.

Build cache keys from a canonical serialized context containing:

- Cache schema version.
- Document kind.
- Product ID for product documents.
- Shop ID.
- Requested language ID.
- Canonical requested locale.
- Shop-default fallback language ID and locale.
- Currency ID and ISO code.
- Currency conversion-rate fingerprint where relevant.
- Default country ID.
- Unidentified group ID.
- Tax display context.
- Module configuration version.
- Module version.
- Translation fingerprint.
- Output-format version.

Hash the canonical serialized context before passing it to PrestaShop's cache
abstraction.

The translation fingerprint is a stable hash of all resolved module-owned
wordings used by the document. This ensures a merchant translation override
changes the key even when no module hook is fired.

Do not include:

- Customer ID.
- Cart ID.
- Cookie.
- Session ID.
- Raw host casing.
- Product rewrite from the incoming request.

Tracked cache registries must be partitioned by shop. Provide:

- `clearShop(int $shopId)` for catalog, price, language, and stock changes.
- `clearAll()` for globally shared module-setting changes and upgrades.

The configured TTL remains the safety net for mutations not covered by hooks.

## Cache invalidation

Retain existing product, category, and shop invalidation.

Add invalidation for:

- `actionUpdateQuantity`, which PrestaShop fires after stock quantity updates.
  See the
  [PrestaShop hook documentation](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionupdatequantity/).
- Language updates through `actionUpdateLangAfter`.
- Relevant `StockAvailable` object updates.
- Currency add, update, and delete events.
- Global module-configuration changes.
- Module upgrade.

Translation overrides rely primarily on the translation fingerprint because
PrestaShop does not provide a sufficiently narrow module-translation update
hook.

Event invalidation should clear only affected shops when the hook supplies a
shop ID. Ambiguous global events clear all module caches.

## Administrative localization

Update the Symfony controller to use `PrestaShopAdminController::trans()` with
the standard PrestaShop 9 parameter order:

```php
$this->trans(
    'Wording',
    ['%placeholder%' => $value],
    'Modules.Llmscatalog.Admin'
);
```

Update Twig with:

```twig
{% trans_default_domain 'Modules.Llmscatalog.Admin' %}
```

Translate the alert, save button, and all additional static content through the
Twig `trans` filter.

Use canonical locale values, not only ISO codes, in title and summary field
labels.

Validation errors must also be translated.

## Service structure

Add or extract these responsibilities:

| Component | Responsibility |
| --- | --- |
| `LanguageResolver` | Resolve canonical active-shop locales and legacy redirects |
| `ResolvedLanguage` | Immutable language, locale, shop, and fallback context |
| `TranslationResolver` | Explicit requested → shop default → English wording fallback |
| `PublicCatalogContext` | Deterministic anonymous price, tax, currency, and stock context |
| `PriceFormatter` | Calculate once and format for the requested locale |
| Localized catalog DTOs | Carry field values and source-language metadata |
| `FallbackTracker` | Aggregate emitted content fallback and decide whether to show the notice |
| `CacheContext` | Build deterministic cache identity |
| `CacheManager` | Store index/product documents and clear shop/global registries |

Keep controllers thin: resolve, redirect or reject, generate, then construct
the response.

## Existing files to update

- `llmscatalog/llmscatalog.php`
- `llmscatalog/controllers/front/index.php`
- `llmscatalog/controllers/front/product.php`
- `llmscatalog/src/Controller/Admin/ConfigurationController.php`
- `llmscatalog/src/Service/CatalogRepository.php`
- `llmscatalog/src/Service/LlmsGenerator.php`
- `llmscatalog/src/Service/MarkdownRenderer.php`
- `llmscatalog/src/Service/Settings.php`
- `llmscatalog/src/Service/UrlBuilder.php`
- `llmscatalog/src/Service/CacheManager.php`
- `llmscatalog/views/templates/admin/configuration.html.twig`
- `llmscatalog/config/services.yml`
- `llmscatalog/config/admin/services.yml`
- `README.md`
- `docs/architecture.md`
- `docs/implementation-plan.md`
- `docs/testing-and-operations.md`
- Relevant ADRs describing localization, multistore, and caching.

## New files

At minimum:

- Language-resolution and translation services/value objects.
- Public catalog context and price formatter.
- Localized catalog DTOs and fallback tracker.
- Four reviewed XLIFF dictionaries.
- `llmscatalog/upgrade/upgrade-1.1.0.php`.
- Focused unit and integration tests.
- This specification.

Exact namespaces may follow the existing `LlmsCatalog\Service` layout unless a
separate `Localization` namespace improves cohesion.

## Upgrade from 1.0.0

Set:

```php
$this->version = '1.1.0';
$this->ps_versions_compliancy['min'] = '9.0.2';
```

The `upgrade-1.1.0.php` script shall:

1. Register every newly required invalidation hook.
2. Establish a global configuration value when no global value exists.
3. Use the default shop's existing value as the migration source when only
   scoped values exist.
4. Leave old scoped rows untouched for rollback, but make `1.1.0` read and
   write global settings explicitly.
5. Preserve existing title and summary maps.
6. Preserve merchant translation overrides.
7. Increment the global configuration version.
8. Clear all tracked module cache entries.
9. Return failure if a required hook or configuration migration fails.

Do not silently select a non-default shop's conflicting scoped configuration.

Legacy ISO routes remain available as redirects for at least the entire `1.x`
release line.

PrestaShop executes versioned scripts from the module's `upgrade/` folder during
module updates. See the
[PrestaShop module upgrade documentation](https://devdocs.prestashop-project.org/9/modules/creation/enabling-auto-update/).

## Unit tests

Add coverage for:

- Exact full-locale resolution.
- Locale casing normalization.
- Unique ISO redirect.
- Ambiguous ISO rejection.
- Inactive and shop-unassigned language rejection.
- Requested/default/English wording fallback.
- Merchant title and summary fallback.
- Field-level product and category fallback.
- Single document-level fallback disclosure.
- No disclosure without emitted fallback content.
- Localized in-stock and out-of-stock terms.
- Locale-sensitive price formatting through a formatter test double.
- Cache-key variation for every declared dimension.
- Cache-key stability for irrelevant cookies or customer state.
- Shop-scoped and global invalidation.
- UTF-8-safe truncation.
- Whole-link truncation.
- Arabic RTL text.
- CJK text.
- Combining characters and emoji.
- Translation placeholder integrity.
- Every required English source wording being represented in the translation
  catalogue.

## PrestaShop integration tests

Run against at least PrestaShop `9.0.2` with:

- Two shops using different domains, catalogs, currencies, and default
  languages.
- Overlapping and non-overlapping active language sets.
- `en-US`, `fr-FR`, an RTL locale such as `ar-SA`, and a non-Latin locale such
  as `ja-JP` or `zh-CN`.

Verify:

- Canonical locale routes and redirects.
- Ambiguous ISO rejection.
- Active-shop language isolation.
- Requested-language catalog selection.
- Field-level default-language fallback and notice.
- Reviewed English and French output.
- English fixed-wording fallback for an untranslated locale.
- Locale-specific price separators, symbol placement, and spacing.
- Explicit ISO currency code.
- Anonymous output remains byte-identical with logged-in users, carts, customer
  groups, currency cookies, and browser-language changes.
- Product, category, stock, currency, language, translation, and setting
  invalidation.
- Product-document caching.
- Correct `Content-Language` and cache headers.
- Admin UI in English and French.
- Hidden localized settings survive saves from another shop context.
- Upgrade from a populated `1.0.0` installation.
- Merchant translation overrides survive upgrade.
- ZIP installation and upgrade through Module Manager.

## Static validation

Required checks:

- PHP syntax for every PHP file.
- PHPUnit unit suite.
- PrestaShop integration suite.
- YAML parsing.
- Twig parsing.
- XLIFF schema/parsing.
- Translation source/target and placeholder consistency.
- UTF-8 validity.
- Documentation-link validation.
- ZIP integrity with one top-level `llmscatalog/` directory.
- No hard-coded user-facing English strings outside approved translation source
  calls or XLIFF files.

The current workspace previously lacked PHP, Composer, and a PrestaShop runtime.
Those checks must run in CI or a real PrestaShop `9.0.2+` test shop before
release.

## Rollout

### Phase 1: Localization infrastructure

- Add locale resolution, translation domains, explicit fallback, and reviewed
  dictionaries.
- Convert all existing fixed wordings.
- Add Unicode and translation unit tests.

### Phase 2: Deterministic public context

- Introduce canonical anonymous pricing context.
- Replace ambient currency and locale formatting.
- Add request-invariance integration tests.

### Phase 3: Multistore and cache hardening

- Make shared configuration explicitly global.
- Add shop-active language filtering.
- Expand cache identity and invalidation.
- Cache product documents.

### Phase 4: Compatibility and upgrade

- Add full-locale canonical routes.
- Add legacy ISO redirects.
- Add and exercise `upgrade-1.1.0.php`.
- Update operational and architecture documentation.

### Phase 5: Release validation

- Install `1.0.0`, populate settings, translations, and caches, then upgrade to
  `1.1.0`.
- Test at least two multistore shops.
- Crawl canonical and legacy URLs.
- Compare repeated output byte-for-byte.
- Inspect English, French, Arabic, and CJK documents.
- Rebuild and validate the distributable ZIP.

### Canary

Deploy first to a non-production or low-traffic multistore installation.

Observe:

- Route status codes.
- Redirect loops.
- Empty or mixed-language sections.
- Price discrepancies.
- Cache growth and hit behavior.
- Stale availability after stock changes.
- Translation override propagation.

Do not log generated catalog content or customer/session data.

### Rollback

Rollback consists of:

1. Reinstalling the prior module package.
2. Clearing module and PrestaShop caches.
3. Restoring the previous route cache.
4. Retaining global and old scoped configuration rows.
5. Retaining merchant translation database entries.

The upgrade deliberately avoids destructive removal of old scoped configuration
rows to keep rollback possible.

## Acceptance criteria

The work is complete when:

- No generated or administrative wording bypasses translation.
- Reviewed `en-US` and `fr-FR` dictionaries ship in the package.
- Canonical URLs use full normalized PrestaShop locales.
- Unambiguous legacy ISO routes redirect permanently.
- Unknown, inactive, unassigned, and ambiguous languages return `404`.
- Catalog fallback is field-level and disclosed once per affected document.
- Prices use requested-locale formatting and canonical shop-default currency.
- User, cart, cookie, and storefront currency state cannot alter output.
- Cache keys cover every output-affecting localization and pricing dimension.
- Translation changes cannot serve stale documents under an old key.
- Shared settings are explicit and do not depend on admin shop context.
- UTF-8, RTL, CJK, and byte-bound truncation tests pass.
- Upgrade from `1.0.0` preserves settings and merchant translations.
- Static, unit, PrestaShop integration, install, upgrade, and package checks
  pass.
