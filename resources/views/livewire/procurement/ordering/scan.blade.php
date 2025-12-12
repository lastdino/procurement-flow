<div class="p-6 space-y-4" x-data @focus-token.window="$refs.token?.focus(); $refs.token?.select()">
    <x-procflow::topmenu />
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('procflow::ordering.title') }}</h1>
        <a href="{{ route('procurement.purchase-orders.index') }}" class="text-blue-600 hover:underline">{{ __('procflow::ordering.back') }}</a>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::ordering.token.title') }}</flux:heading>
            <flux:input
                id="token"
                x-ref="token"
                wire:model.live.debounce.300ms="form.token"
                placeholder="{{ __('procflow::ordering.token.placeholder') }}"
            />
            <div class="flex gap-2">
                <flux:button
                    variant="outline"
                    wire:click="lookup"
                    wire:loading.attr="disabled"
                    wire:target="lookup"
                >{{ __('procflow::ordering.token.lookup') }}</flux:button>
            </div>

            @if ($message)
                @if ($ok)
                    <flux:callout variant="success" class="mt-2">{{ $message }}</flux:callout>
                @else
                    <flux:callout variant="danger" class="mt-2">{{ $message }}</flux:callout>
                @endif
            @endif
        </div>

        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::ordering.info.title') }}</flux:heading>

            @if ($this->hasInfo)
                <div class="text-sm text-gray-700 space-y-1">
                    <div>{{ __('procflow::ordering.info.material') }}: <span class="font-medium">{{ $info['material_name'] }}</span> [<span>{{ $info['material_sku'] }}</span>]</div>
                    <div>{{ __('procflow::ordering.info.supplier') }}: <span class="font-medium">{{ $info['preferred_supplier'] ?? __('procflow::ordering.common.not_set') }}</span></div>
                    <div>{{ __('procflow::ordering.info.unit_purchase') }}: <span class="font-medium">{{ $info['unit_purchase'] ?? '-' }}</span></div>
                    @if($info['moq'])
                        <div>{{ __('procflow::ordering.info.moq') }}: <span class="font-medium">{{ $info['moq'] }}</span></div>
                    @endif
                    @if($info['pack_size'])
                        <div>{{ __('procflow::ordering.info.pack_size') }}: <span class="font-medium">{{ $info['pack_size'] }}</span></div>
                    @endif
                </div>

                {{-- Options (same style as PO issuance; price impact: none) --}}
                @if (!empty($optionGroups))
                    <div class="mt-4 space-y-3">
                        <flux:heading size="xs">{{ __('procflow::ordering.options.title') }}</flux:heading>
                        <div class="grid grid-cols-2 gap-4">
                            @foreach($optionGroups as $g)
                                @php $gid = $g['id']; $opts = $optionsByGroup[$gid] ?? []; @endphp
                                <flux:field>
                                    <flux:label>{{ $g['name'] }}</flux:label>
                                    <select class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.defer="form.options.{{ $gid }}">
                                        <option value="">-</option>
                                        @foreach($opts as $o)
                                            <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                        @endforeach
                                    </select>
                                    <flux:error name="form.options.{{ $gid }}" />
                                </flux:field>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

            <div class="grid gap-3 md:grid-cols-3 items-end">
                <div class="md:col-span-2">
                    <flux:input type="number" step="0.000001" min="0" wire:model.number="form.qty" label="{{ __('procflow::ordering.qty.label') }}"/>
                </div>
                <div class="flex gap-2">
                    <flux:button variant="outline" wire:click="decrementQty" title="-1">-</flux:button>
                    <flux:button variant="outline" wire:click="incrementQty" title="+1">+</flux:button>
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    wire:click="order"
                    wire:loading.attr="disabled"
                    wire:target="order"
                >{{ __('procflow::ordering.create_draft') }}</flux:button>
            </div>
        </div>
    </div>
</div>
