<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement\Ordering;

use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Component;
use Lastdino\ProcurementFlow\Actions\Ordering\CreateDraftPurchaseOrderFromScanAction;
use Lastdino\ProcurementFlow\Models\OrderingToken;
use Illuminate\Http\Request;
use Lastdino\ProcurementFlow\Models\{OptionGroup, Option};
use Lastdino\ProcurementFlow\Services\{OptionCatalogService, OptionSelectionRuleBuilder};

class Scan extends Component
{
    /**
     * @var array{token:string, qty:float|int|null}
     */
    public array $form = [
        'token' => '',
        'qty' => null,
        // オプション選択（group_id => option_id）
        'options' => [],
    ];

    /**
     * @var array{material_name:string,material_sku:string,preferred_supplier:string|null,unit_purchase:string|null,moq:string|float|int|null,pack_size:string|float|int|null,default_qty:string|float|int|null}
     */
    public array $info = [
        'material_name' => '',
        'material_sku' => '',
        'preferred_supplier' => null,
        'unit_purchase' => null,
        'moq' => null,
        'pack_size' => null,
        'default_qty' => null,
    ];

    /**
     * @var array<int,array{id:int,name:string}>
     */
    public array $optionGroups = [];

    /**
     * @var array<int,array<int,array{id:int,name:string}>>
     */
    public array $optionsByGroup = [];

    public string $message = '';
    public bool $ok = false;

    protected function rules(): array
    {
        return [
            'form.token' => ['required', 'string'],
            'form.qty' => ['nullable', 'numeric', 'gt:0'],
            'form.options' => ['array'],
        ];
    }

    public function getHasInfoProperty(): bool
    {
        return (bool) ($this->info['material_name'] ?? false);
    }

    public function setMessage(string $text, bool $ok = false): void
    {
        $this->message = $text;
        $this->ok = $ok;
    }

    public function updatedFormToken(string $value): void
    {
        $token = trim((string) $value);
        if ($token === '') {
            $this->resetInfo();
            $this->message = '';
            $this->ok = false;
            return;
        }

        $this->lookup();
    }

    public function mount(Request $request): void
    {
        // If QR payload used URL with ?token=..., prefill and auto-lookup
        $qToken = trim((string) $request->query('token', ''));
        if ($qToken !== '') {
            $this->form['token'] = $qToken;
            // Defer lookup to next tick to allow hydration
            $this->dispatch('focus-token');
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->validateOnly('form.token');

        /** @var OrderingToken|null $ot */
        $ot = OrderingToken::query()->where('token', (string) $this->form['token'])->with('material.preferredSupplier')->first();
        if (! $ot || ! $ot->enabled || ($ot->expires_at && now()->greaterThan($ot->expires_at))) {
            $this->resetInfo();
            $this->setMessage(__('procflow::ordering.messages.invalid_or_expired_token'), false);
            return;
        }

        $mat = $ot->material;
        if (! $mat) {
            $this->resetInfo();
            $this->setMessage(__('procflow::ordering.messages.material_not_found'), false);
            return;
        }

        if (! (bool) ($mat->is_active ?? true)) {
            $this->resetInfo();
            $this->setMessage(__('procflow::ordering.messages.material_not_found'), false);
            return;
        }

        $this->info = [
            'material_name' => (string) ($mat->name ?? ''),
            'material_sku' => (string) ($mat->sku ?? ''),
            'preferred_supplier' => $mat->preferredSupplier?->name,
            'unit_purchase' => $ot->unit_purchase ?? $mat->unit_purchase_default,
            'moq' => $mat->moq,
            'pack_size' => $mat->pack_size,
            'default_qty' => $ot->default_qty,
        ];

        // オプショングループ/選択肢をロード（既存PO作成と同等：Activeのみ、並び順あり）
        $this->loadActiveOptions();
        // 既存の選択をリセット
        $this->form['options'] = [];

        // 既定数量がある場合はフォームに反映
        if (empty($this->form['qty']) && $this->info['default_qty']) {
            $this->form['qty'] = (float) $this->info['default_qty'];
        }

        $this->setMessage(__('procflow::ordering.messages.recognized_enter_qty'), true);
    }

    public function incrementQty(float $step = 1): void
    {
        $current = (float) ($this->form['qty'] ?? 0);
        $this->form['qty'] = $current + $step;
    }

    public function decrementQty(float $step = 1): void
    {
        $current = (float) ($this->form['qty'] ?? 0);
        $next = $current - $step;
        $this->form['qty'] = $next > 0 ? $next : null;
    }

    public function order(CreateDraftPurchaseOrderFromScanAction $action): void
    {
        // Build dynamic rules to require options for all active groups
        $rules = [
            'form.token' => ['required', 'string'],
            'form.qty' => ['required', 'numeric', 'gt:0'],
        ];

        $activeGroups = app(OptionCatalogService::class)->getActiveGroups();
        $optionRules = app(OptionSelectionRuleBuilder::class)->build('form.options', $activeGroups);
        $rules = $rules + $optionRules;

        $this->validate($rules);

        try {
            $po = $action->handle([
                'token' => (string) $this->form['token'],
                'qty' => (float) $this->form['qty'],
                'options' => (array) ($this->form['options'] ?? []),
            ]);
            $this->resetAfterOrder();
            $this->setMessage(__('procflow::ordering.messages.draft_created'), true);
            // 作成したPOへ遷移する場合は以下を有効化
            // $this->redirectRoute('procurement.purchase-orders.show', ['po' => $po->id]);
            $this->dispatch('focus-token');
        } catch (\Throwable $e) {
            $this->setMessage(__('procflow::ordering.messages.order_failed', ['message' => $e->getMessage()]), false);
        }
    }

    public function resetInfo(): void
    {
        $this->info = [
            'material_name' => '',
            'material_sku' => '',
            'preferred_supplier' => null,
            'unit_purchase' => null,
            'moq' => null,
            'pack_size' => null,
            'default_qty' => null,
        ];
    }

    public function resetAfterOrder(): void
    {
        $this->resetInfo();
        $this->form['token'] = '';
        $this->form['qty'] = null;
        $this->form['options'] = [];
        $this->optionGroups = [];
        $this->optionsByGroup = [];
    }

    public function render(): ViewContract
    {
        return view('procflow::livewire.procurement.ordering.scan');
    }

    protected function loadActiveOptions(): void
    {
        $catalog = app(OptionCatalogService::class);
        $groups = $catalog->getActiveGroups();
        $this->optionGroups = [];
        foreach ($groups as $g) {
            $this->optionGroups[] = [
                'id' => (int) $g->getKey(),
                'name' => (string) $g->getAttribute('name'),
            ];
        }

        $this->optionsByGroup = $catalog->getActiveOptionsByGroup();
    }
}
