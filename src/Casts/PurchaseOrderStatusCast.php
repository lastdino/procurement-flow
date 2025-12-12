<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;

/**
 * Normalizes British/American spelling for "canceled/cancelled" and maps to enum.
 */
class PurchaseOrderStatusCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PurchaseOrderStatus
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->normalize((string) $value);

        return PurchaseOrderStatus::from($normalized);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof PurchaseOrderStatus) {
            return $value->value;
        }

        $normalized = $this->normalize((string) $value);

        return $normalized;
    }

    private function normalize(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === 'cancelled') {
            return 'canceled';
        }
        return $v;
    }
}
