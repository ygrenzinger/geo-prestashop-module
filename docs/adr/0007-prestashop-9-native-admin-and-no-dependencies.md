# ADR 0007: Use a native PrestaShop 9 admin page and no external dependencies

## Context

PrestaShop 9 uses Symfony services and modern back-office controllers. The
module must remain installable as a self-contained ZIP.

## Decision

Use a Symfony controller/form for configuration and rely only on PrestaShop
core APIs plus module services. Do not require Composer packages or hosted
services at runtime.

## Consequences

The module follows the current PrestaShop 9 extension model and minimizes
supply-chain and installation risk. Full runtime validation must still occur
against a real PrestaShop 9 environment.
