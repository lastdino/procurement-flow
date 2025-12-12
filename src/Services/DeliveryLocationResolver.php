<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Lastdino\ProcurementFlow\Support\Settings;

final class DeliveryLocationResolver
{
    public function resolve(?string $input): string
    {
        $value = (string) ($input ?? '');
        if ($value !== '') {
            return $value;
        }
        return (string) (Settings::pdf()['delivery_location'] ?? '');
    }
}
