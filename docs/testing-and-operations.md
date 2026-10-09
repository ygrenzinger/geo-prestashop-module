# Testing and operations

## Repeatable validation

Unit tests run standalone (no PrestaShop required):

```bash
composer install        # dev tooling only; the shipped module stays dependency-free
composer lint           # php -l over llmscatalog/ and tests/
composer test:unit      # vendor/bin/phpunit --testsuite unit
```

The integration tests (`tests/Integration`, plus the shop-dependent `CatalogPageGeneratorTest`) need a bootstrapped PrestaShop shop and database. `scripts/run-integration-tests.sh` starts a PrestaShop 9 test shop with Docker and runs the whole suite inside it:

```bash
scripts/run-integration-tests.sh                 # full suite in the Docker test shop
scripts/run-integration-tests.sh --testsuite unit
```

To run against an existing shop instead, point `PS_TEST_SHOP_ROOT` at the shop root (the directory containing `config/config.inc.php`) and run `vendor/bin/phpunit -c phpunit.xml.dist`; `tests/bootstrap.php` loads it. Without a shop, the integration tests self-skip.

The current workspace has not run PHP lint or PHPUnit. Runtime acceptance requires a clean PrestaShop 9.0.2 and supported 9.1.x shop matrix, including install/upgrade, multistore and locale isolation, public visitor-state parity, route/hook behavior, cache invalidation and validators, audit security/cancellation, JSON-LD conflict behavior, and representative large-catalog query/memory checks. Record actual command and shop results before calling acceptance complete.

For package validation, verify ZIP integrity, `llmscatalog/` archive root, absence of `.DS_Store`, expected current files, and source/archive byte parity. A successful ZIP check validates packaging only.

## Install and upgrade

Install the preserved `llmscatalog/` wrapper under `modules/`, install through Module Manager, and clear PrestaShop/Symfony route caches if needed. Source version is 1.3.1 and minimum PrestaShop is 9.0.2. Upgrade scripts add missing settings and schema idempotently while preserving stored values. Confirm installed version history before downgrade. Uninstall removes module settings and disposable cache/revision tables.

## Operations

If output is stale, clear PrestaShop cache and save configuration to bump its generation; normal mutation hooks and the configured TTL also bound freshness. If a route returns 404, verify the module is enabled, the locale is active/assigned, complete indexes are enabled for traversal routes, and friendly URLs/cache are current. Compare public bodies and ETags across anonymous, authenticated, VIP, alternate-currency, and alternate-country sessions during deployment canaries.

The module does not modify robots.txt or XML sitemaps. Check existing robots policy allows the desired routes, and add catalog URLs through the shop's existing sitemap process. Review discovery in both HTML metadata and HTTP `Link` headers.

The audit is read-only and scoped to current shop/language. It samples the homepage, up to 10 categories and 25 products, runs in batches up to five, and retains session report data for 15 minutes. XML/TSV feed snapshots are one-time inputs (maximum 2 MiB) and are not persisted. Augmentation is opt-in per shop and requires a complete audit without blockers plus preview confirmation.

## Rollback

Disable rich product Markdown or any augmentation switch in configuration to return to the minimal behavior. Disable complete indexes to remove cursor traversal routes; existing product Markdown routes remain. To roll back code, restore the prior package, clear PrestaShop/Symfony caches, and purge CDN entries for localized indexes and product Markdown. The 1.3.0 cache/revision schema is additive disposable state and can remain for older code to ignore. Uninstall removes it.

## Package and acceptance status

`outputs/llmscatalog.zip` is rebuilt from current source and validated for archive integrity, root structure, `.DS_Store` exclusion, and source/archive parity. PHP/PHPUnit and PrestaShop runtime validation remain pending; see the [remediation status matrix](specs/00-geo-remediation-master-plan.md).
