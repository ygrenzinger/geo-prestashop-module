# LLMs.txt for PrestaShop

`llmscatalog` is a dependency-free PrestaShop 9 module (minimum 9.0.2) that publishes localized catalog indexes and product Markdown, with optional complete catalog traversal, a read-only GEO audit, and opt-in structured-data augmentation. Product audits check JSON-LD and Microdata for public Offer price data; eligible pages can receive a Product/Offer or bounded variant AggregateOffer. It helps make public catalog information easier for crawlers and generative systems to interpret; it does not promise crawling, ranking, citation, or inclusion.

## Features

- Canonical full-locale routes, deterministic anonymous public prices and availability, and bounded cacheable responses.
- Curated root index plus ID-cursor product/category indexes for complete traversal.
- Optional rich product Markdown and optional HTML/HTTP discovery relations.
- Current-shop, current-language read-only audit and guarded Organization, Product, and Breadcrumb JSON-LD augmentation.

## Install

Copy the `llmscatalog/` directory into PrestaShop `modules/`, install **LLMs.txt for PrestaShop** in Module Manager, then open its configuration page. If friendly routes were previously cached, clear PrestaShop/Symfony caches after installation. The ZIP at `outputs/llmscatalog.zip` preserves the required `llmscatalog/` archive root.

On a matched shop-homepage GET request, `Accept: text/markdown` returns the localized catalog as `text/markdown` with `Vary: Accept` and `Cache-Control: private, no-store`. HTML remains the response when Markdown is not explicitly preferred. Verify the deployed root and language-prefixed homepage URLs; the recorded smoke test has not confirmed those routes through the test container's redirect behavior.

## Documentation

- [User guide](docs/user-guide.md) — installation, settings, endpoints, audit, operations, rollback
- [Implementation plan and endpoint matrix](docs/implementation-plan.md)
- [Architecture](docs/architecture.md)
- [Testing and operations](docs/testing-and-operations.md)
- [Changelog](CHANGELOG.md)
- [Rich product Markdown](docs/rich-product-markdown.md)
- [Remediation status matrix](docs/specs/00-geo-remediation-master-plan.md)
- [Detailed specifications](docs/specs/)
- [Architecture decision records](docs/adr/)

## Validation status

For version 1.5.0, `composer lint` passed, `composer test` passed (106 tests, 10,735 assertions, 6 shop-dependent skips), and the Docker PrestaShop 9.2.0 suite passed (106 tests, 10,761 assertions). Supported-version installation and upgrade, multistore, security, theme-hook, and large-catalog acceptance remain pending. The homepage Markdown smoke test verified `/index.php`; the deployed root and language-prefixed homepage routes still need verification. See the [status matrix](docs/specs/00-geo-remediation-master-plan.md).
