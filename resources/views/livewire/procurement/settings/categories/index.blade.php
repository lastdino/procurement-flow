<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('procflow::settings.categories.title', [], null) ?: '資材カテゴリの設定' }}</h1>
        <a href="{{ route('procurement.dashboard') }}" class="text-blue-600 hover:underline">{{ __('procflow::common.back', [], null) ?: '戻る' }}</a>
    </div>

    <div class="flex justify-end">
        <flux:button variant="primary" wire:click="openCreate">{{ __('procflow::settings.categories.new', [], null) ?: 'カテゴリを追加' }}</flux:button>
    </div>

    <div class="rounded border divide-y">
        <div class="grid grid-cols-12 gap-2 p-3 text-sm text-neutral-600">
            <div class="col-span-6">{{ __('procflow::settings.categories.fields.name', [], null) ?: '名称' }}</div>
            <div class="col-span-4">{{ __('procflow::settings.categories.fields.code', [], null) ?: 'コード' }}</div>
            <div class="col-span-2 text-right">{{ __('procflow::common.actions', [], null) ?: '操作' }}</div>
        </div>
        @forelse ($this->categories as $cat)
            <div class="grid grid-cols-12 gap-2 p-3 items-center">
                <div class="col-span-6">
                    <div class="font-medium">{{ $cat['name'] }}</div>
                </div>
                <div class="col-span-4">
                    <div class="text-neutral-600 dark:text-neutral-400">{{ $cat['code'] }}</div>
                </div>
                <div class="col-span-2 flex justify-end gap-2">
                    <flux:button size="xs" variant="outline" wire:click="openEdit({{ $cat['id'] }})">{{ __('procflow::common.edit', [], null) ?: '編集' }}</flux:button>
                    <flux:button size="xs" variant="danger" wire:click="delete({{ $cat['id'] }})" wire:confirm="{{ __('procflow::common.confirm_delete', [], null) ?: '削除してよいですか？' }}">{{ __('procflow::common.delete', [], null) ?: '削除' }}</flux:button>
                </div>
            </div>
        @empty
            <div class="p-6 text-center text-neutral-500">{{ __('procflow::settings.categories.empty', [], null) ?: 'カテゴリがありません' }}</div>
        @endforelse
    </div>

    <flux:modal wire:model="openModal">
        <div class="p-4 space-y-4">
            <flux:heading size="sm">{{ $editingId ? (__('procflow::settings.categories.edit_title', [], null) ?: 'カテゴリを編集') : (__('procflow::settings.categories.create_title', [], null) ?: 'カテゴリを作成') }}</flux:heading>

            <flux:input wire:model="name" label="{{ __('procflow::settings.categories.fields.name', [], null) ?: '名称' }}" />
            @error('name')<div class="text-red-600 text-xs">{{ $message }}</div>@enderror

            <flux:input wire:model="code" placeholder="CHEMICALS" label="{{ __('procflow::settings.categories.fields.code', [], null) ?: 'コード (大文字・数字・-_ )' }}" />
            @error('code')<div class="text-red-600 text-xs">{{ $message }}</div>@enderror

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeModal">{{ __('procflow::common.cancel', [], null) ?: 'キャンセル' }}</flux:button>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">{{ __('procflow::common.save', [], null) ?: '保存' }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
