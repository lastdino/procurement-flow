<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Models\Material;
use Lastdino\ProcurementFlow\Models\PurchaseOrder;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItem;
use Lastdino\ProcurementFlow\Support\Settings;

final class PurchaseOrderFactory
{
    public function __construct(
        public TaxResolver $taxResolver,
        public OptionSelectionService $optionService,
        public DeliveryLocationResolver $deliveryLocationResolver,
    ) {
    }

    /**
     * @param array{
     *   supplier_id:int,
     *   expected_date?:string|null,
     *   delivery_location?:string|null,
     *   items:list<array{
     *     material_id:int|null,
     *     description?:string|null,
     *     manufacturer?:string|null,
     *     unit_purchase:string,
     *     qty_ordered:float|int,
     *     price_unit:float|int,
     *     tax_rate?:float|int|null,
     *     desired_date?:string|null,
     *     expected_date?:string|null,
     *     note?:string|null,
     *     options?:array<int,int|string|null>,
     *   }>,
     * } $input
     */
    public function create(array $input, bool $generateShippingPerLine = false): PurchaseOrder
    {
        return DB::transaction(function () use ($input, $generateShippingPerLine) {
            $po = PurchaseOrder::create([
                'supplier_id' => (int) $input['supplier_id'],
                'status' => PurchaseOrderStatus::Draft,
                'expected_date' => $input['expected_date'] ?? null,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                'delivery_location' => $this->deliveryLocationResolver->resolve($input['delivery_location'] ?? null),
                'created_by' => auth()->id() ?: null,
            ]);

            $subtotal = 0.0;
            $tax = 0.0;
            $at = ! empty($input['expected_date']) ? Carbon::parse((string) $input['expected_date']) : null;

            foreach ($input['items'] as $line) {
                $material = null;
                if (! is_null($line['material_id'])) {
                    $material = Material::find((int) $line['material_id']);
                }

                $qty = (float) $line['qty_ordered'];
                $priceUnit = (float) $line['price_unit'];
                $lineSubtotal = $qty * $priceUnit;

                $rate = isset($line['tax_rate']) && $line['tax_rate'] !== '' && $line['tax_rate'] !== null
                    ? (float) $line['tax_rate']
                    : $this->taxResolver->resolveRate($material, $at);

                $item = PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'material_id' => $line['material_id'],
                    'description' => $line['description'] ?? null,
                    'manufacturer' => $line['manufacturer'] ?? null,
                    'unit_purchase' => (string) $line['unit_purchase'],
                    'qty_ordered' => $qty,
                    'price_unit' => $priceUnit,
                    'tax_rate' => $rate,
                    'line_total' => $lineSubtotal,
                    'desired_date' => $line['desired_date'] ?? null,
                    'expected_date' => $line['expected_date'] ?? null,
                    'note' => $line['note'] ?? null,
                ]);

                $selected = $this->optionService->normalizeAndValidate((array) ($line['options'] ?? []));
                if (! empty($selected)) {
                    $this->optionService->syncToItem($item, $selected);
                }

                $subtotal += $lineSubtotal;
                $tax += $lineSubtotal * $rate;

                if ($generateShippingPerLine && $material) {
                    $separate = (bool) ($material->getAttribute('separate_shipping') ?? false);
                    $fee = (float) ($material->getAttribute('shipping_fee_per_order') ?? 0);
                    if ($separate && $fee > 0) {
                        $shipping = Settings::shipping();
                        $shippingTaxable = (bool) ($shipping['taxable'] ?? true);
                        $shippingRate = $shippingTaxable ? (float) ($shipping['tax_rate'] ?? 0.10) : 0.0;

                        $desc = '送料（' . (string) ($material->getAttribute('name') ?? $material->getAttribute('sku') ?? '対象資材') . '）';
                        PurchaseOrderItem::create([
                            'purchase_order_id' => $po->id,
                            'material_id' => null,
                            'description' => $desc,
                            'unit_purchase' => 'shipping',
                            'qty_ordered' => 1,
                            'price_unit' => $fee,
                            'tax_rate' => $shippingRate,
                            'line_total' => $fee,
                            'desired_date' => null,
                            'expected_date' => null,
                            'shipping_for_item_id' => $item->getKey(),
                        ]);

                        $subtotal += $fee;
                        $tax += $fee * $shippingRate;
                    }
                }
            }

            $po->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax,
            ]);

            return $po;
        });
    }
}
