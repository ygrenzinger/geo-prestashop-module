# LLMs.txt for PrestaShop

`llmscatalog` is a dependency-free PrestaShop 9 module (minimum 9.0.2) that publishes localized catalog indexes and product Markdown, with optional complete catalog traversal, a read-only GEO audit, and opt-in structured-data augmentation. Product audits check JSON-LD and Microdata for public Offer price data; eligible pages can receive a Product/Offer or bounded variant AggregateOffer. It helps make public catalog information easier for crawlers and generative systems to interpret; it does not promise crawling, ranking, citation, or inclusion.

## Features

- Canonical full-locale routes, deterministic anonymous public prices and availability, and bounded cacheable responses.
- Curated root index plus ID-cursor product/category indexes for complete traversal.
- Optional rich product Markdown and optional HTML/HTTP discovery relations.
- Current-shop, current-language read-only audit and guarded Organization, Product, and Breadcrumb JSON-LD augmentation.

## Install

Copy the `llmscatalog/` directory into PrestaShop `modules/`, install **LLMs.txt for PrestaShop** in Module Manager, then open its configuration page. If friendly routes were previously cached, clear PrestaShop/Symfony caches after installation. The ZIP at `outputs/llmscatalog.zip` preserves the required `llmscatalog/` archive root.

The storefront homepage also negotiates Markdown: `Accept: text/markdown` returns the localized catalog as `text/markdown` with `Vary: Accept`, while `Accept: text/html` keeps the rendered HTML homepage. This applies to the shop homepage URL itself when the module is enabled.

## Documentation

- [User guide](docs/user-guide.md) — installation, settings, endpoints, audit, operations, rollback
- [Implementation plan and endpoint matrix](docs/implementation-plan.md)
- [Architecture](docs/architecture.md)
- [Testing and operations](docs/testing-and-operations.md)
- [Rich product Markdown](docs/rich-product-markdown.md)
- [Remediation status matrix](docs/specs/00-geo-remediation-master-plan.md)
- [Detailed specifications](docs/specs/)
- [Architecture decision records](docs/adr/)

## Validation status

Source implementation and focused test files are present, and the ZIP is a regenerated source archive. PHP lint and standalone unit tests passed locally for this change; full PrestaShop installation/upgrade, security, and runtime behavior remain to be validated; release acceptance remains pending PHP-enabled CI and a PrestaShop 9.0.2/supported 9.1.x test-shop matrix. See the [status matrix](docs/specs/00-geo-remediation-master-plan.md).
