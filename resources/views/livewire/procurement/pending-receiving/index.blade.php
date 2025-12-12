<div class="p-6 space-y-6">
    <x-procflow::topmenu />
    <h1 class="text-xl font-semibold">{{ __('procflow::pending.title') }}</h1>

    <div class="flex items-end gap-3">
        <div class="grow max-w-96">
            <flux:input wire:model.live.debounce.300ms="q" placeholder="{{ __('procflow::pending.search_placeholder') }}" />
        </div>
    </div>

    <div class="rounded-lg border overflow-x-auto bg-white dark:bg-neutral-900 mt-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-neutral-500">
                    <th class="py-2 px-3">{{ __('procflow::pending.table.po_number') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::pending.table.supplier') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::pending.table.requester') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::pending.table.status') }}</th>
                    <th class="py-2 px-3">{{ __('procflow::pending.table.items') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->orders as $po)
                    <tr class="border-t hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="py-2 px-3">
                            <a href="{{ route('procurement.purchase-orders.show', ['po' => $po->id]) }}" class="text-blue-600 hover:underline">
                                {{ $po->po_number ?? __('procflow::po.labels.draft_with_id', ['id' => $po->id]) }}
                            </a>
                        </td>
                        <td class="py-2 px-3">{{ $po->supplier->name ?? '-' }}</td>
                        <td class="py-2 px-3">{{ $po->requester->name ?? '-' }}</td>
                        <td class="py-2 px-3">
                            @php $status = is_string($po->status) ? $po->status : ($po->status->value ?? 'draft'); @endphp
                            @php
                                $color = match ($status) {
                                    'closed' => 'green',
                                    'issued' => 'yellow',
                                    'receiving' => 'cyan',
                                    'canceled' => 'red',
                                    default  => 'zinc',
                                };
                            @endphp
                            <flux:badge color="{{ $color }}" size="sm">{{ __('procflow::po.status.' . $status) }}</flux:badge>
                            {{ __('procflow::po.status.' . $status) }}
                        </td>
                        <td class="py-2 px-3">{{ $po->items->count() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-neutral-500">{{ __('procflow::pending.table.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div>
            {{ $this->orders->links() }}
        </div>
    </div>
</div>
