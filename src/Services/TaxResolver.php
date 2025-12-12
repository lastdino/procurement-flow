<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Carbon\CarbonInterface;
use Lastdino\ProcurementFlow\Models\Material;
use Lastdino\ProcurementFlow\Support\Settings;

final class TaxResolver
{
    public function resolveRate(?Material $material, ?CarbonInterface $at = null): float
    {
        $itemTax = Settings::itemTax($at);
        $rate = (float) ($itemTax['default_rate'] ?? 0.10);
        if ($material) {
            $code = (string) ($material->getAttribute('tax_code') ?? '');
            if ($code !== '' && isset($itemTax['rates'][$code])) {
                $rate = (float) $itemTax['rates'][$code];
            }
        }
        return $rate;
    }
}
