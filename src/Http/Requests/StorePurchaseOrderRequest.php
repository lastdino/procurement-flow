<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Lastdino\ProcurementFlow\Support\Tables;
use Lastdino\ProcurementFlow\Models\Material;
use Lastdino\ProcurementFlow\Models\AppSetting;
use Lastdino\ApprovalFlow\Models\ApprovalFlow;
use Illuminate\Validation\Rule;
use Lastdino\ProcurementFlow\Models\{OptionGroup, Option};
use Lastdino\ProcurementFlow\Services\OptionSelectionRuleBuilder;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Use prefixed table names for exists rules
        $rules = [
            // New flow (materials-based) allows supplier_id to be omitted. Ad-hoc flow still requires it (enforced in withValidator).
            'supplier_id' => ['nullable', 'exists:' . Tables::name('suppliers') . ',id'],
            'expected_date' => ['nullable', 'date'],
            // 発注ごとの納品先（未指定の場合はPDF設定の値を用いてバックエンドで補完）
            'delivery_location' => ['nullable','string'],
            'items' => ['required','array','min:1'],
            // Allow ordering without registering material
            // Require either material_id or description
            // 資材は「有効（is_active = true）」なものに限定する
            'items.*.material_id' => [
                'nullable',
                Rule::exists(Tables::name('materials'), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)),
                'required_without:items.*.description',
            ],
            'items.*.unit_purchase' => ['required','string','max:32'],
            'items.*.qty_ordered' => ['required','numeric','gt:0'],
            'items.*.price_unit' => ['required','numeric','gte:0'],
            'items.*.tax_rate' => ['nullable','numeric','between:0,1'],
            'items.*.description' => ['nullable','string','required_without:items.*.material_id'],
            'items.*.manufacturer' => ['nullable','string','max:255'],
            'items.*.desired_date' => ['nullable', 'date'],
            'items.*.expected_date' => ['nullable', 'date'],
            'items.*.note' => ['nullable','string','max:1000'],
        ];

        // オプショングループ: 有効なグループごとに必須にする（共通ビルダーを利用）
        $activeGroups = OptionGroup::query()->active()->ordered()->get(['id','name']);
        if ($activeGroups->isNotEmpty()) {
            /** @var OptionSelectionRuleBuilder $builder */
            $builder = app(OptionSelectionRuleBuilder::class);
            $rules = $rules + $builder->build('items.*.options', $activeGroups);
        }

        return $rules;
    }

    /**
     * バリデーション開始前に承認フロー設定の存在を確認し、未設定なら直ちに中断します。
     */
    protected function prepareForValidation(): void
    {
        try {
            $flowIdStr = AppSetting::get('approval_flow.purchase_order_flow_id');
            $flowId = (int) ($flowIdStr ?? 0);
            $exists = $flowId > 0 && ApprovalFlow::query()->whereKey($flowId)->exists();
            if (! $exists) {
                throw new HttpResponseException(response()->json([
                    'message' => '承認フローが未設定のため発注できません。管理者に連絡してください。',
                    'errors' => [
                        'approval_flow' => ['承認フローが未設定のため発注できません。管理者に連絡してください。'],
                    ],
                ], 422));
            }
        } catch (\Throwable $e) {
            throw new HttpResponseException(response()->json([
                'message' => '承認フローが未設定のため発注できません。管理者に連絡してください。',
                'errors' => [
                    'approval_flow' => ['承認フローが未設定のため発注できません。管理者に連絡してください。'],
                ],
            ], 422));
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $supplierId = $this->filled('supplier_id') ? (int) $this->input('supplier_id') : null;
            $items = (array) $this->input('items', []);

            $hasAdhoc = false;
            foreach ($items as $index => $item) {
                $materialId = $item['material_id'] ?? null;
                if (empty($materialId)) {
                    // Ad-hoc line
                    $hasAdhoc = true;
                    continue;
                }

                /** @var Material|null $material */
                $material = Material::query()->find($materialId);
                if (! $material) {
                    continue;
                }

                $preferred = $material->getAttribute('preferred_supplier_id');
                // If supplier is explicitly chosen (adhoc flow or legacy single-supplier flow), enforce match
                if (! is_null($supplierId)) {
                    if (! is_null($preferred) && (int) $preferred !== (int) $supplierId) {
                        $validator->errors()->add(
                            "items.$index.material_id",
                            'この資材は特定のサプライヤーからのみ購入できます。発注先サプライヤーを資材の指定サプライヤーに合わせてください。'
                        );
                    }
                } else {
                    // Materials-based flow: every material must have preferred supplier
                    if (is_null($preferred)) {
                        $validator->errors()->add(
                            "items.$index.material_id",
                            'この資材に紐づくサプライヤーが未設定のため、自動発注できません。資材に指定サプライヤーを設定してください。'
                        );
                    }
                }
            }

            // If there are any ad-hoc lines, supplier_id is required
            if ($hasAdhoc && is_null($supplierId)) {
                $validator->errors()->add('supplier_id', 'アドホック行が含まれるため、サプライヤーの選択が必要です。');
            }
        });
    }

    public function messages(): array
    {
        // Provide friendly messages for required options per active group
        $messages = [];
        $activeGroups = OptionGroup::query()->active()->ordered()->get(['id','name']);
        foreach ($activeGroups as $group) {
            $gid = (int) $group->getKey();
            $gname = (string) $group->getAttribute('name');
            $messages["items.*.options.$gid.required"] = "『{$gname}』の選択は必須です。";
            $messages["items.*.options.$gid.exists"] = "『{$gname}』の選択が不正です。有効なオプションを選択してください。";
        }
        // Generic fallbacks
        return $messages + [
            'items.*.options.required' => 'オプションの選択は必須です。',
            'items.*.options.array' => 'オプションの形式が不正です。',
        ];
    }
}
