<div class="p-6 space-y-6">
    <x-procflow::topmenu />
    <h1 class="text-xl font-semibold">{{ __('procflow::suppliers.title') }}</h1>

    <div class="flex items-end gap-3">
        <div class="grow max-w-96">
            <flux:input wire:model.live.debounce.300ms="q" placeholder="{{ __('procflow::suppliers.search_placeholder') }}" />
        </div>
        <div>
            <flux:button variant="primary" wire:click="openCreateSupplier">{{ __('procflow::suppliers.buttons.new_supplier') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border overflow-x-auto bg-white dark:bg-neutral-900 mt-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-neutral-500">
                    <th class="py-2 px-3">{{ __('procflow::suppliers.table.name') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::suppliers.table.code') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::suppliers.table.email') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::suppliers.table.phone') }}</th>
                    <th class="py-2 px-3 text-right">{{ __('procflow::suppliers.table.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($this->suppliers as $s)
                <tr class="border-t hover:bg-neutral-50 dark:hover:bg-neutral-800">
                    <td class="py-2 px-3">
                        <a class="text-blue-600 hover:underline"
                           wire:click.prevent="openSupplierDetail({{ $s->id }})">
                            {{ $s->name }}
                        </a>
                    </td>
                    <td class="py-2 px-3">{{ $s->code }}</td>
                    <td class="py-2 px-3">{{ $s->email }}</td>
                    <td class="py-2 px-3">{{ $s->phone }}</td>
                    <td class="py-2 px-3 text-right">
                        <flux:button size="xs" variant="outline" wire:click="openEditSupplier({{ $s->id }})">{{ __('procflow::suppliers.buttons.edit') }}</flux:button>
                        <flux:button size="xs" variant="danger" class="ml-2" wire:click="confirmDelete({{ $s->id }})">{{ __('procflow::suppliers.buttons.delete') }}</flux:button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-neutral-500">{{ __('procflow::suppliers.table.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal for create/edit supplier (Flux UI) --}}
    <flux:modal wire:model.self="showSupplierModal" name="supplier-form">
        <div class="w-full md:w-[] max-w-lg">
            <h3 class="text-lg font-semibold mb-3">{{ $editingSupplierId ? __('procflow::suppliers.modal.edit_title') : __('procflow::suppliers.modal.new_title') }}</h3>

            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.name') }}</label>
                    <input class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.name" />
                    @error('supplierForm.name') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.code') }}</label>
                        <input class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.code" />
                    </div>
                    <div>
                        <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.email') }}</label>
                        <input type="email" class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.email" />
                        @error('supplierForm.email') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.email_cc') }}</label>
                    <input type="text" class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.email_cc" placeholder="{{ __('procflow::suppliers.form.email_cc_placeholder') }}" />
                    @error('supplierForm.email_cc') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.contact_person') }}</label>
                        <input class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.contact_person_name" />
                        @error('supplierForm.contact_person_name') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.phone') }}</label>
                        <input class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.phone" />
                    </div>
                    <div>
                        <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.active') }}</label>
                        <select class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.is_active">
                            <option value="1">{{ __('procflow::suppliers.form.active_yes') }}</option>
                            <option value="0">{{ __('procflow::suppliers.form.active_no') }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2 pt-1">
                    <input id="auto_send_po" type="checkbox" class="size-4" wire:model.live="supplierForm.auto_send_po" />
                    <label for="auto_send_po" class="text-sm text-neutral-700 dark:text-neutral-300">{{ __('procflow::suppliers.form.auto_send_po') }}</label>
                </div>
                <div>
                    <label class="block text-sm text-neutral-600 mb-1">{{ __('procflow::suppliers.form.address') }}</label>
                    <textarea rows="3" class="w-full border rounded p-2 bg-white dark:bg-neutral-900" wire:model.live="supplierForm.address"></textarea>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3">
                <flux:button variant="outline" x-on:click="$flux.modal('supplier-form').close()">{{ __('procflow::suppliers.buttons.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveSupplier" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('procflow::suppliers.buttons.save') }}</span>
                    <span wire:loading>{{ __('procflow::suppliers.buttons.saving') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Supplier Detail Modal (Flux UI) --}}
    <flux:modal wire:model.self="showSupplierDetailModal" name="supplier-detail">
        <div class="w-full md:w-[64rem] max-w-full">
            @if($supplierDetail)
                <h3 class="text-lg font-semibold mb-3">{{ __('procflow::suppliers.detail.title') }}</h3>

                <div class="rounded-lg border p-4 bg-white dark:bg-neutral-900 mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.name') }}</div>
                            <div class="font-medium">{{ $supplierDetail['name'] ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.code') }}</div>
                            <div class="font-medium">{{ $supplierDetail['code'] ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.email') }}</div>
                            <div class="font-medium">{{ $supplierDetail['email'] ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.email_cc') }}</div>
                            <div class="font-medium">{{ $supplierDetail['email_cc'] ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.auto_send_po') }}</div>
                            <div class="font-medium">{{ !empty($supplierDetail['auto_send_po']) ? __('procflow::suppliers.form.active_yes') : __('procflow::suppliers.form.active_no') }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.phone') }}</div>
                            <div class="font-medium">{{ $supplierDetail['phone'] ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-neutral-500 text-sm">{{ __('procflow::suppliers.form.contact_person') }}</div>
                            <div class="font-medium">{{ $supplierDetail['contact_person_name'] ?? '-' }}</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border p-4 bg-white dark:bg-neutral-900">
                    <h4 class="text-md font-semibold mb-3">{{ __('procflow::suppliers.detail.purchase_orders') }}</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-neutral-500">
                                    <th class="py-2 px-3">{{ __('procflow::po.table.po_number') }}</th>
                                    <th class="py-2 px-3">{{ __('procflow::po.table.status') }}</th>
                                    <th class="py-2 px-3">{{ __('procflow::po.table.total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($supplierDetail['purchase_orders'] ?? []) as $po)
                                    <tr class="border-t">
                                        <td class="py-2 px-3">
                                            @php($poShowHref = \Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show') ? route('procurement.purchase-orders.show', ['po' => $po['id']]) : '#')
                                            <a href="{{ $poShowHref }}"
                                               class="text-blue-600 hover:underline"
                                               wire:click.prevent="$dispatch('open-po-from-supplier', { id: {{ $po['id'] }} })">
                                                {{ $po['po_number'] ?? __('procflow::po.labels.draft_with_id', ['id' => $po['id']]) }}
                                            </a>
                                        </td>
                                            <td class="py-2 px-3">{{ __('procflow::po.status.' . ($po['status'] ?? 'draft')) }}</td>
                                            <td class="py-2 px-3">{{ \Lastdino\ProcurementFlow\Support\Format::moneyTotal($po['total'] ?? 0) }}</td>
                                        </tr>
                                @empty
                                    <tr><td class="py-4 text-center text-neutral-500" colspan="3">{{ __('procflow::suppliers.detail.empty_pos') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="text-neutral-500">{{ __('procflow::suppliers.detail.loading') }}</div>
            @endif

            <div class="mt-4 flex items-center justify-end gap-3">
                <flux:button variant="outline" x-on:click="$flux.modal('supplier-detail').close()">{{ __('procflow::suppliers.buttons.close') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model.self="showDeleteConfirm" name="supplier-delete">
        <div class="w-full md:w-[] max-w-md">
            <h3 class="text-lg font-semibold mb-3">{{ __('procflow::suppliers.delete.confirm_title') }}</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('procflow::suppliers.delete.confirm_text') }}</p>

            <div class="mt-4 flex items-center justify-end gap-3">
                <flux:button variant="outline" wire:click="cancelDelete">{{ __('procflow::suppliers.buttons.cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteSupplier" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('procflow::suppliers.delete.confirm_button') }}</span>
                    <span wire:loading>{{ __('procflow::suppliers.delete.deleting') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
