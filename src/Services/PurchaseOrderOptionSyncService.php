<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Lastdino\ProcurementFlow\Models\{Option, PurchaseOrderItem, PurchaseOrderItemOptionValue};

class PurchaseOrderOptionSyncService
{
    /**
     * Sync selected options for a PO item. Invalid/inactive selections are ignored.
     *
     * @param  PurchaseOrderItem  $item
     * @param  array<int,int|string>  $selectedOptions  [group_id => option_id]
     */
    public function syncItemOptions(PurchaseOrderItem $item, array $selectedOptions): void
    {
        foreach ($selectedOptions as $groupId => $optionId) {
            if (empty($optionId)) {
                continue;
            }

            $gid = (int) $groupId;
            $oid = (int) $optionId;

            $exists = Option::query()
                ->active()
                ->where('group_id', $gid)
                ->whereKey($oid)
                ->exists();

            if (! $exists) {
                continue; // skip invalid
            }

            PurchaseOrderItemOptionValue::query()->updateOrCreate(
                [
                    'purchase_order_item_id' => (int) $item->getKey(),
                    'group_id' => $gid,
                ],
                [
                    'option_id' => $oid,
                ]
            );
        }
    }
}
