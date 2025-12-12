<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Actions\Ordering;

use Illuminate\Support\Facades\DB;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Models\{Material, OrderingToken, PurchaseOrder, PurchaseOrderItem, AppSetting, Supplier, OptionGroup, Option, PurchaseOrderItemOptionValue};
use Lastdino\ProcurementFlow\Support\Settings;

class CreateDraftPurchaseOrderFromScanAction
{
    /**
     * @param array{token:string, qty:float|int, note?:string|null, options?:array<int,int|string|null>} $input
     */
    public function handle(array $input): PurchaseOrder
    {
        $tokenStr = trim((string) ($input['token'] ?? ''));
        $qty = (float) ($input['qty'] ?? 0);
        abort_if($tokenStr === '' || $qty <= 0, 422, 'Invalid token or quantity.');

        /** @var OrderingToken|null $token */
        $token = OrderingToken::query()->where('token', $tokenStr)->first();
        abort_if(! $token, 404, 'Token not found.');
        abort_if(! (bool) $token->enabled, 422, 'Token is disabled.');
        if ($token->expires_at) {
            abort_if(now()->greaterThan($token->expires_at), 422, 'Token expired.');
        }

        /** @var Material $material */
        $material = $token->material()->firstOrFail();
        abort_if(! (bool) ($material->is_active ?? true), 422, 'Material is inactive.');
        $supplierId = (int) ($material->preferred_supplier_id ?? 0);
        abort_if($supplierId <= 0, 422, 'Preferred supplier is not set for this material.');

        // MOQ / pack size validation (purchase unit基準とする)
        $moq = (float) ($material->getAttribute('moq') ?? 0);
        $pack = (float) ($material->getAttribute('pack_size') ?? 0);
        if ($moq > 0 && $qty < $moq) {
            abort(422, 'Quantity is below MOQ (min: '.$moq.').');
        }
        if ($pack > 0) {
            $multiple = fmod($qty, $pack);
            // 許容する誤差
            if ($multiple > 1e-9 && ($pack - $multiple) > 1e-9) {
                abort(422, 'Quantity must be a multiple of pack size (pack: '.$pack.').');
            }
        }

        // 税率の決定（税コードが一致すれば上書き、なければ既定）
        $itemTax = Settings::itemTax();
        $taxRate = (float) ($itemTax['default_rate'] ?? 0.10);
        $code = (string) ($material->getAttribute('tax_code') ?? '');
        if ($code !== '' && isset($itemTax['rates'][$code])) {
            $taxRate = (float) $itemTax['rates'][$code];
        }

        $unitPrice = (float) ($material->getAttribute('unit_price') ?? 0);
        $unitPurchase = (string) ($token->getAttribute('unit_purchase') ?? $material->getAttribute('unit_purchase_default') ?? '');

        // Normalize and validate selected options against active groups
        /** @var array<int,int> $selectedOpts */
        $selectedOpts = [];
        $activeGroups = OptionGroup::query()->active()->ordered()->get(['id','name']);
        if ($activeGroups->isNotEmpty()) {
            $optionsInput = isset($input['options']) && is_array($input['options']) ? $input['options'] : [];
            // Require each active group to be present
            foreach ($activeGroups as $group) {
                $gid = (int) $group->getKey();
                $gname = (string) $group->getAttribute('name');
                if (! array_key_exists($gid, $optionsInput) || $optionsInput[$gid] === null || $optionsInput[$gid] === '') {
                    abort(422, "『{$gname}』の選択は必須です。");
                }
                $oid = (int) $optionsInput[$gid];
                $exists = Option::query()->active()->where('group_id', $gid)->whereKey($oid)->exists();
                abort_if(! $exists, 422, 'Invalid option selection.');
                $selectedOpts[$gid] = $oid;
            }
        } else {
            // No active groups → still validate any provided options (must belong to group and be active)
            if (isset($input['options']) && is_array($input['options'])) {
                foreach ($input['options'] as $gid => $oid) {
                    if ($oid === null || $oid === '') {
                        continue;
                    }
                    $gid = (int) $gid;
                    $oid = (int) $oid;
                    $exists = Option::query()->active()->where('group_id', $gid)->whereKey($oid)->exists();
                    abort_if(! $exists, 422, 'Invalid option selection.');
                    $selectedOpts[$gid] = $oid;
                }
            }
        }

        /** @var PurchaseOrder $po */
        $po = DB::transaction(function () use ($supplierId, $qty, $material, $unitPrice, $taxRate, $unitPurchase, $selectedOpts) {
            $po = PurchaseOrder::create([
                'supplier_id' => $supplierId,
                'status' => PurchaseOrderStatus::Draft,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                'delivery_location' => (string) (Settings::pdf()['delivery_location'] ?? ''),
                'created_by' => auth()->id() ?: null,
            ]);

            $lineTotal = $qty * $unitPrice;
            $lineTax = $lineTotal * $taxRate;

            /** @var PurchaseOrderItem $item */
            $item = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'material_id' => $material->getKey(),
                'description' => null,
                'unit_purchase' => $unitPurchase ?: 'each',
                'qty_ordered' => $qty,
                'price_unit' => $unitPrice,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'desired_date' => null,
            ]);

            // Persist option selections (no price impact)
            if (! empty($selectedOpts)) {
                foreach ($selectedOpts as $groupId => $optionId) {
                    PurchaseOrderItemOptionValue::query()->updateOrCreate(
                        [
                            'purchase_order_item_id' => (int) $item->getKey(),
                            'group_id' => (int) $groupId,
                        ],
                        [
                            'option_id' => (int) $optionId,
                        ]
                    );
                }
            }

            $po->update([
                'subtotal' => $lineTotal,
                'tax' => $lineTax,
                'total' => $lineTotal + $lineTax,
            ]);

            return $po;
        });

        // 承認フロー登録（設定値にflowIdがあれば）
        try {
            $poModel = $po->fresh();
            $authorId = (int) (auth()->id() ?? $poModel->created_by ?? 0);
            $link = null;
            if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
                $link = route('procurement.purchase-orders.show', ['po' => $poModel->id]);
            }
            $flowId = (int) ((AppSetting::get('approval_flow.purchase_order_flow_id')) ?? 0);
            if ($authorId > 0 && $flowId > 0) {
                $poModel->registerApprovalFlowTask($flowId, $authorId, null, null, $link);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to register approval flow for PO (scan ordering): '.$e->getMessage(), ['po_id' => $po->id]);
        }

        return $po;
    }
}
