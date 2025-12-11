<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('procflow::settings.taxes.title') }}</h1>
        <a href="{{ route('procurement.dashboard') }}" class="text-blue-600 hover:underline">{{ __('procflow::settings.taxes.back') }}</a>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::settings.taxes.items.heading') }}</flux:heading>

            <flux:input type="number" step="0.001" wire:model="itemDefaultRate" label="{{ __('procflow::settings.taxes.items.default_rate') }}"/>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <flux:heading size="xs">{{ __('procflow::settings.taxes.items.additional_rates.heading') }}</flux:heading>
                    <flux:button size="xs" variant="outline" wire:click="addRateRow">{{ __('procflow::settings.taxes.items.additional_rates.add') }}</flux:button>
                </div>
                <div class="space-y-2">
                    @foreach ($itemRates as $i => $row)
                        <div class="grid grid-cols-12 gap-2 items-end">
                            <div class="col-span-5">
                                <flux:input class="col-span-5" wire:model="itemRates.{{ $i }}.key" placeholder="reduced" label="{{ __('procflow::settings.taxes.items.additional_rates.key') }}"/>
                            </div>
                            <div class="col-span-5">
                                <flux:input class="col-span-5" type="number" step="0.001" wire:model="itemRates.{{ $i }}.rate" label="{{ __('procflow::settings.taxes.items.additional_rates.rate') }}"/>
                            </div>
                            <div class="col-span-2 flex justify-end">
                                <flux:button size="xs" variant="danger" wire:click="removeRateRow({{ $i }})">{{ __('procflow::settings.taxes.items.additional_rates.remove') }}</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <flux:textarea rows="6" wire:model="itemScheduleJson" placeholder="{
  'effective_from':'2027-10-01',
            'default_rate':0.12,
            'rates': {reduced:0.10}
            }" label="{{ __('procflow::settings.taxes.items.schedule.label') }}"></flux:textarea>
        </div>

        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::settings.taxes.shipping.heading') }}</flux:heading>

            <div class="flex items-center justify-between">
                <label class="text-sm">{{ __('procflow::settings.taxes.shipping.taxable') }}</label>
                <flux:switch wire:model="shippingTaxable" />
            </div>

            <flux:field label="{{ __('procflow::settings.taxes.shipping.tax_rate') }}">
                <flux:input type="number" step="0.001" wire:model="shippingTaxRate" />
                @error('shippingTaxRate')<div class="text-red-600 text-xs mt-1">{{ $message }}</div>@enderror
            </flux:field>
        </div>
    </div>

    <div class="flex justify-end">
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">{{ __('procflow::settings.taxes.buttons.save') }}</flux:button>
    </div>
</div>
