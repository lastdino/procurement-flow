<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Lastdino\ProcurementFlow\Models\Option;

class OptionSelectionRuleBuilder
{
    /**
     * Build validation rules that require an option selection for each active group.
     *
     * @param  string  $optionsKey  e.g. 'form.options' or 'poForm.items.*.options'
     * @param  Collection<int, \Lastdino\ProcurementFlow\Models\OptionGroup>  $activeGroups
     * @return array<string, array<int, string|\Illuminate\Validation\Rules\Exists|\Illuminate\Validation\Rule>>
     */
    public function build(string $optionsKey, Collection $activeGroups): array
    {
        $rules = [];

        if ($activeGroups->isEmpty()) {
            return $rules;
        }

        $groupIds = $activeGroups->pluck('id')->map(fn ($v) => (int) $v)->all();
        $rules[$optionsKey] = ['required', 'array', 'required_array_keys:'.implode(',', $groupIds)];

        foreach ($activeGroups as $group) {
            $gid = (int) $group->getKey();
            $rules["{$optionsKey}.{$gid}"] = [
                'required',
                Rule::exists((new Option())->getTable(), 'id')
                    ->where(fn ($q) => $q->where('group_id', $gid)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                    ),
            ];
        }

        return $rules;
    }
}
