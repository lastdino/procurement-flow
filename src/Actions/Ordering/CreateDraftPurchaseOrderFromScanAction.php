<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Actions\Ordering;

use Illuminate\Support\Facades\Validator;
use Lastdino\ProcurementFlow\Models\{Material, OrderingToken, PurchaseOrder};
use Lastdino\ProcurementFlow\Services\{OptionCatalogService, OptionSelectionRuleBuilder, PurchaseOrderOptionSyncService, PurchaseOrderFactory, ApprovalFlowRegistrar, OptionSelectionService};

class CreateDraftPurchaseOrderFromScanAction
{
    public function __construct(
        public OptionCatalogService $optionCatalog,
        public OptionSelectionRuleBuilder $ruleBuilder,
        public PurchaseOrderOptionSyncService $optionSync,
        public PurchaseOrderFactory $poFactory,
        public ApprovalFlowRegistrar $approvalRegistrar,
        public OptionSelectionService $optionService,
    ) {
    }

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

        $unitPrice = (float) ($material->getAttribute('unit_price') ?? 0);
        $unitPurchase = (string) ($token->getAttribute('unit_purchase') ?? $material->getAttribute('unit_purchase_default') ?? '');

        // Normalize and validate selected options via shared service (throws 422 on failure)
        /** @var array<int,int> $selectedOpts */
        $selectedOpts = $this->optionService->normalizeAndValidate((array) ($input['options'] ?? []));

        // Create via shared PO factory (with shipping generation enabled)
        $poInput = [
            'supplier_id' => $supplierId,
            'expected_date' => null,
            'delivery_location' => null,
            'items' => [
                [
                    'material_id' => $material->getKey(),
                    'description' => null,
                    'unit_purchase' => $unitPurchase ?: 'each',
                    'qty_ordered' => $qty,
                    'price_unit' => $unitPrice,
                    // Let factory resolve tax rate from material and current settings
                    'tax_rate' => null,
                    'desired_date' => null,
                    'expected_date' => null,
                    'note' => (string) ($input['note'] ?? ''),
                    'options' => $selectedOpts,
                ],
            ],
        ];

        /** @var PurchaseOrder $po */
        $po = $this->poFactory->create($poInput, true);

        // 承認フロー登録（設定値にflowIdがあれば）
        $this->approvalRegistrar->registerForPo($po);

        return $po;
    }
}
