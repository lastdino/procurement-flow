<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('procflow::settings.display.title') }}</h1>
        <a href="{{ route('procurement.dashboard') }}" class="text-blue-600 hover:underline">{{ __('procflow::settings.display.back') }}</a>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::settings.display.decimals.heading') }}</flux:heading>
            <div class="grid md:grid-cols-2 gap-3">
                <flux:input type="number" min="0" max="6" wire:model="decimals.qty" label="{{ __('procflow::settings.display.decimals.qty') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.unit_price" label="{{ __('procflow::settings.display.decimals.unit_price') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.unit_price_materials" label="{{ __('procflow::settings.display.decimals.unit_price_materials') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.line_total" label="{{ __('procflow::settings.display.decimals.line_total') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.subtotal" label="{{ __('procflow::settings.display.decimals.subtotal') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.tax" label="{{ __('procflow::settings.display.decimals.tax') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.total" label="{{ __('procflow::settings.display.decimals.total') }}" />
                <flux:input type="number" min="0" max="6" wire:model="decimals.percent" label="{{ __('procflow::settings.display.decimals.percent') }}" />
            </div>
        </div>

        <div class="rounded border p-4 space-y-4">
            <flux:heading size="sm">{{ __('procflow::settings.display.currency.heading') }}</flux:heading>
            <div class="grid md:grid-cols-2 gap-3">
                <flux:input wire:model="currencySymbol" label="{{ __('procflow::settings.display.currency.symbol') }}" />
                <flux:select wire:model="currencyPosition" label="{{ __('procflow::settings.display.currency.position') }}">
                    <option value="prefix">{{ __('procflow::settings.display.currency.prefix') }}</option>
                    <option value="suffix">{{ __('procflow::settings.display.currency.suffix') }}</option>
                </flux:select>
                <div class="md:col-span-2">
                    <label class="text-sm">{{ __('procflow::settings.display.currency.space') }}</label>
                    <div class="mt-2">
                        <input id="cur_space" type="checkbox" class="size-4" wire:model="currencySpace" />
                        <label for="cur_space" class="ml-2 text-sm text-neutral-700 dark:text-neutral-300">{{ __('procflow::settings.display.currency.space_hint') }}</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2">
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">{{ __('procflow::settings.display.buttons.save') }}</flux:button>
    </div>
</div>
