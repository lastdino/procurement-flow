<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Illuminate\Support\Facades\Validator;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItem;

final class OptionSelectionService
{
    public function __construct(
        public OptionCatalogService $catalog,
        public OptionSelectionRuleBuilder $ruleBuilder,
        public PurchaseOrderOptionSyncService $sync,
    ) {
    }

    /**
     * @param array<int,int|string|null> $raw
     * @return array<int,int>
     */
    public function normalizeAndValidate(array $raw): array
    {
        $selected = [];
        $groups = $this->catalog->getActiveGroups();
        if ($groups->isEmpty()) {
            foreach ($raw as $gid => $oid) {
                if ($oid === null || $oid === '') {
                    continue;
                }
                $selected[(int) $gid] = (int) $oid;
            }
            return $selected;
        }

        $data = ['options' => (array) $raw];
        $rules = $this->ruleBuilder->build('options', $groups);

        // Optional messages matching UI tone
        $messages = [];
        foreach ($groups as $group) {
            $gid = (int) $group->getKey();
            $gname = (string) $group->getAttribute('name');
            $messages["options.$gid.required"] = "『{$gname}』の選択は必須です。";
            $messages["options.$gid.exists"] = "『{$gname}』の選択が不正です。有効なオプションを選択してください。";
        }

        $validator = Validator::make($data, $rules, $messages);
        try {
            $validated = $validator->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            abort(422, $validator->errors()->first());
        }
        foreach ((array) ($validated['options'] ?? []) as $gid => $oid) {
            if ($oid === null || $oid === '') {
                continue;
            }
            $selected[(int) $gid] = (int) $oid;
        }
        return $selected;
    }

    /**
     * @param array<int,int> $selected
     */
    public function syncToItem(PurchaseOrderItem $item, array $selected): void
    {
        $this->sync->syncItemOptions($item, $selected);
    }
}
