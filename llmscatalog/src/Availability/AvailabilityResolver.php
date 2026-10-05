<?php

namespace LlmsCatalog\Availability;

/** Pure precedence and date logic. */
final class AvailabilityResolver
{
    public function resolve(AvailabilityFacts $facts, array $labels = []): AvailabilityResult
    {
        $reason = $facts->reason;
        if ($reason === 'missing_default_combination' || $reason === 'stock_resolution_failed') {
            $state = 'out_of_stock';
        } elseif (!$facts->availableForOrder || $facts->catalogMode || !$facts->showPrice) {
            $state = 'not_for_sale';
            $reason = $reason ?: (!$facts->availableForOrder ? 'available_for_order_disabled' : ($facts->catalogMode ? 'catalog_mode' : 'price_hidden'));
        } elseif (!$facts->stockManagement) {
            $state = 'in_stock';
            $reason = $reason ?: 'stock_management_disabled';
        } elseif ($facts->quantity >= max(1, $facts->minimumQuantity)) {
            $state = 'in_stock';
            $reason = $reason ?: 'minimum_quantity_available';
        } elseif ($facts->allowOutOfStock) {
            $state = 'backorder';
            $reason = $reason ?: 'backorders_allowed';
        } else {
            $state = 'out_of_stock';
            $reason = $reason ?: 'insufficient_stock';
        }

        $date = $state === 'backorder' ? $this->validFutureOrToday($facts->availableDate) : null;
        $fallback = [
            'in_stock' => 'In stock', 'backorder' => 'Available on backorder',
            'out_of_stock' => 'Out of stock', 'not_for_sale' => 'Not available for order',
        ];
        $label = trim((string) ($labels[$state] ?? '')) ?: $fallback[$state];
        return AvailabilityResult::create($state, $label, $date, $facts->combinationId, $facts->otherVariantsOrderable, $reason);
    }

    private function validFutureOrToday(?string $date): ?string
    {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $parsed < new \DateTimeImmutable('today')) return null;
        return $date;
    }
}
