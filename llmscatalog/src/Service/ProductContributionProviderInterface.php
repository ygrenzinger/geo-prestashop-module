<?php

namespace LlmsCatalog\Service;

/** Optional providers return structured public facts, never Markdown or HTML. */
interface ProductContributionProviderInterface
{
    public function technicalId(): string;
    public function contribute(int $shopId, int $languageId, int $productId): array;
}
