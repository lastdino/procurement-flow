<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement\Materials;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lastdino\ProcurementFlow\Models\Material;
use Lastdino\ProcurementFlow\Models\MaterialCategory;
use Illuminate\Validation\Rule;
use Lastdino\ProcurementFlow\Support\Tables;
use Lastdino\ProcurementFlow\Models\UnitConversion;
use Lastdino\ProcurementFlow\Support\Settings;
use Illuminate\Support\Str;
use Lastdino\ProcurementFlow\Models\OrderingToken;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;
    public string $q = '';
    public ?int $category_id = null;

    // Inline MOQ/Pack management has been removed. Use modals for create/edit instead.

    // Modal state for create/edit Material
    public bool $showMaterialModal = false;
    public ?int $editingMaterialId = null;
    /**
     * @var array{
     *   sku:?string,name:?string,unit_stock:?string,unit_purchase_default:?string|null,safety_stock:?string|float|int|null,
     *   category_id:?int,current_stock:?string|float|int|null,
     *   manufacturer_name:?string,storage_location:?string,applicable_regulation:?string,
     *   ghs_mark:?string,ghs_mark_options?:array<int,string>,protective_equipment:?string,unit_price:?string|float|int|null,
     *   conversion_factor_purchase_to_stock:?string|float|int|null,
     *   preferred_supplier_id:?int|null,
     *   tax_code:?string|null,
     *   manage_by_lot:bool,
     *   moq:?string|float|int|null,
     *   pack_size:?string|float|int|null
     * }
     */
    public array $materialForm = [
        'sku' => null,
        'name' => null,
        'tax_code' => 'standard',
        'unit_stock' => null,
        'unit_purchase_default' => null,
        'safety_stock' => 0,
        'category_id' => null,
        'current_stock' => null,
        'manufacturer_name' => null,
        'storage_location' => null,
        'applicable_regulation' => null,
        'ghs_mark' => null,
        // UI 用: GHS キーの複数選択（保存時に ghs_mark に連結保存）
        'ghs_mark_options' => [],
        'protective_equipment' => null,
        'unit_price' => null,
        // Optional: define conversion factor from purchase unit to stock unit
        'conversion_factor_purchase_to_stock' => null,
        'preferred_supplier_id' => null,
        // Shipping fields
        'separate_shipping' => false,
        'shipping_fee_per_order' => null,
        // Lot management
        'manage_by_lot' => false,
        // Ordering constraints
        'moq' => null,
        'pack_size' => null,
    ];

    public function getCategoriesProperty()
    {
        return MaterialCategory::query()->orderBy('name')->get();
    }

    public function getSuppliersProperty()
    {
        return \Lastdino\ProcurementFlow\Models\Supplier::query()->orderBy('name')->get();
    }

    // Ordering Token issuance modal state
    public bool $showTokenModal = false;
    public ?int $tokenMaterialId = null;
    /**
     * @var array{token:?string, material_id:?int, unit_purchase:?string|null, default_qty:?float|int|null, enabled:bool, expires_at:?string|null}
     */
    public array $tokenForm = [
        'token' => null,
        'material_id' => null,
        'unit_purchase' => null,
        'default_qty' => 1,
        'enabled' => true,
        'expires_at' => null,
    ];

    // SDS 管理モーダル
    public bool $showSdsModal = false;
    public ?int $sdsMaterialId = null;
    #[Validate('nullable|file|mimetypes:application/pdf|max:20480')] // 20MB
    public ?TemporaryUploadedFile $sdsUpload = null;

    public function getMaterialsProperty()
    {
        $q = (string) $this->q;
        $cat = $this->category_id;
        return Material::query()
            ->when(\Illuminate\Support\Facades\Schema::hasTable('media'), fn ($qrb) => $qrb->with('media'))
            ->withSum('lots', 'qty_on_hand')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('sku', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($cat, fn ($qrb) => $qrb->where('category_id', $cat))
            ->orderBy('sku')
            ->limit(100)
            ->get();
    }

    // Removed inline rules/fillEdit/saveEdits methods

    public function openCreateMaterial(): void
    {
        $this->resetMaterialForm();
        $this->editingMaterialId = null;
        $this->showMaterialModal = true;
    }

    public function openEditMaterial(int $id): void
    {
        /** @var Material $m */
        $m = Material::query()->findOrFail($id);
        $this->editingMaterialId = $m->id;
        $conversionFactor = null;
        if (!empty($m->unit_purchase_default) && !empty($m->unit_stock)) {
            /** @var UnitConversion|null $conv */
            $conv = UnitConversion::query()
                ->where('material_id', $m->id)
                ->where('from_unit', $m->unit_purchase_default)
                ->where('to_unit', $m->unit_stock)
                ->first();
            if ($conv !== null) {
                $conversionFactor = (float) $conv->factor;
            }
        }
        $this->materialForm = [
            'sku' => $m->sku,
            'name' => $m->name,
            'tax_code' => $m->tax_code ?? 'standard',
            'unit_stock' => $m->unit_stock,
            'unit_purchase_default' => $m->unit_purchase_default,
            'safety_stock' => $m->safety_stock,
            'category_id' => $m->category_id,
            'current_stock' => $m->current_stock,
            'manufacturer_name' => $m->manufacturer_name,
            'storage_location' => $m->storage_location,
            'applicable_regulation' => $m->applicable_regulation,
            'ghs_mark' => $m->ghs_mark,
            'ghs_mark_options' => method_exists($m, 'ghsMarkList') ? $m->ghsMarkList() : [],
            'protective_equipment' => $m->protective_equipment,
            'unit_price' => $m->unit_price,
            'conversion_factor_purchase_to_stock' => $conversionFactor,
            'preferred_supplier_id' => $m->preferred_supplier_id,
            'separate_shipping' => (bool) $m->separate_shipping,
            'shipping_fee_per_order' => $m->shipping_fee_per_order,
            'manage_by_lot' => (bool) ($m->manage_by_lot ?? false),
            // Ordering constraints
            'moq' => $m->moq,
            'pack_size' => $m->pack_size,
        ];
        $this->showMaterialModal = true;
    }

    public function openTokenModal(int $materialId): void
    {
        /** @var Material $m */
        $m = Material::query()->findOrFail($materialId);
        $this->tokenMaterialId = $m->id;
        $this->tokenForm = [
            'token' => (string) Str::uuid(),
            'material_id' => $m->id,
            'unit_purchase' => $m->unit_purchase_default,
            'default_qty' => 1,
            'enabled' => true,
            'expires_at' => null,
        ];
        $this->showTokenModal = true;
    }

    public function toggleActive(int $materialId): void
    {
        /** @var Material $m */
        $m = Material::query()->findOrFail($materialId);
        $m->is_active = ! (bool) ($m->is_active ?? true);
        $m->save();

        $this->dispatch('notify', text: $m->is_active
            ? __('procflow::materials.flash.material_enabled')
            : __('procflow::materials.flash.material_disabled'));
    }

    protected function tokenRules(): array
    {
        return [
            'tokenForm.token' => ['required', 'string', 'max:255', 'unique:' . Tables::name('ordering_tokens') . ',token'],
            'tokenForm.material_id' => ['required', 'integer', 'exists:' . Tables::name('materials') . ',id'],
            'tokenForm.unit_purchase' => ['nullable', 'string', 'max:32'],
            'tokenForm.default_qty' => ['nullable', 'numeric', 'gt:0'],
            'tokenForm.enabled' => ['boolean'],
            'tokenForm.expires_at' => ['nullable', 'date'],
        ];
    }

    public function saveToken(): void
    {
        $data = $this->validate($this->tokenRules());
        $payload = $data['tokenForm'];

        // normalize defaults
        $payload['enabled'] = (bool) ($payload['enabled'] ?? true);
        if (array_key_exists('default_qty', $payload) && ($payload['default_qty'] === '' || is_null($payload['default_qty']))) {
            $payload['default_qty'] = null;
        }

        OrderingToken::query()->create([
            'token' => (string) $payload['token'],
            'material_id' => (int) $payload['material_id'],
            'unit_purchase' => $payload['unit_purchase'] ?: null,
            'default_qty' => $payload['default_qty'],
            'enabled' => (bool) $payload['enabled'],
            'expires_at' => $payload['expires_at'] ?: null,
        ]);

        $this->showTokenModal = false;
        $this->dispatch('toast', type: 'success', message: 'Ordering token issued');
        $this->dispatch('issued-token');
    }

    public function closeMaterialModal(): void
    {
        $this->showMaterialModal = false;
    }

    protected function materialRules(): array
    {
        $materialsTable = Tables::name('materials');
        $cfg = (array) (config('procurement-flow.ghs') ?? config('procurement_flow.ghs') ?? []);
        /** @var array<int,string> $ghsKeys */
        $ghsKeys = array_values(array_filter(array_keys((array) ($cfg['map'] ?? []))));

        return [
            'materialForm.sku' => [
                'required', 'string', 'max:255',
                Rule::unique($materialsTable, 'sku')->ignore($this->editingMaterialId),
            ],
            'materialForm.name' => ['required', 'string', 'max:255'],
            'materialForm.tax_code' => ['nullable', 'string', Rule::in($this->taxCodeOptions())],
            'materialForm.unit_stock' => ['required', 'string', 'max:32'],
            'materialForm.unit_purchase_default' => ['nullable', 'string', 'max:32'],
            'materialForm.safety_stock' => ['nullable', 'numeric', 'min:0'],
            'materialForm.category_id' => ['nullable', 'integer', 'exists:' . Tables::name('material_categories') . ',id'],
            'materialForm.current_stock' => ['nullable', 'numeric', 'min:0'],
            'materialForm.manufacturer_name' => ['nullable', 'string', 'max:255'],
            'materialForm.storage_location' => ['nullable', 'string', 'max:255'],
            'materialForm.applicable_regulation' => ['nullable', 'string', 'max:255'],
            'materialForm.ghs_mark' => ['nullable', 'string', 'max:255'],
            // 複数選択チェックボックス
            'materialForm.ghs_mark_options' => ['nullable', 'array'],
            'materialForm.ghs_mark_options.*' => empty($ghsKeys) ? ['string'] : ['string', Rule::in($ghsKeys)],
            'materialForm.protective_equipment' => ['nullable', 'string', 'max:255'],
            'materialForm.unit_price' => ['nullable', 'numeric', 'min:0'],
            'materialForm.conversion_factor_purchase_to_stock' => ['nullable', 'numeric', 'gt:0'],
            'materialForm.preferred_supplier_id' => ['nullable', 'integer', 'exists:' . Tables::name('suppliers') . ',id'],
            // Shipping fields
            'materialForm.separate_shipping' => ['boolean'],
            'materialForm.shipping_fee_per_order' => ['nullable', 'numeric', 'min:0'],
            // Lot management
            'materialForm.manage_by_lot' => ['boolean'],
            // Ordering constraints
            'materialForm.moq' => ['nullable', 'numeric', 'gt:0'],
            'materialForm.pack_size' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * 設定ファイルから利用可能な税コード候補を取得する（常に 'standard' を含む）。
     * 例: ['standard','reduced','zero']
     *
     * @return array<int,string>
     */
    protected function taxCodeOptions(): array
    {
        $tax = Settings::itemTax(null);
        $rates = (array) ($tax['rates'] ?? []);
        $keys = array_keys($rates);
        array_unshift($keys, 'standard');
        // unique & preserve order
        $keys = array_values(array_unique($keys));
        return $keys;
    }

    /**
     * Blade から参照しやすいように公開のコンピューテッドとしても提供。
     * @return array<int,string>
     */
    public function getTaxCodesProperty(): array
    {
        return $this->taxCodeOptions();
    }

    public function saveMaterial(): void
    {
        $data = $this->validate($this->materialRules());
        $payload = $data['materialForm'];

        // Map checkbox selection to persisted string column
        if (array_key_exists('ghs_mark_options', $payload) && is_array($payload['ghs_mark_options'])) {
            $keys = array_values(array_filter(array_map('strval', $payload['ghs_mark_options']), static fn ($v) => $v !== ''));
            $payload['ghs_mark'] = empty($keys) ? null : implode(',', $keys);
            unset($payload['ghs_mark_options']);
        }

        // Default tax_code to 'standard' when not provided
        if (! array_key_exists('tax_code', $payload) || $payload['tax_code'] === null || $payload['tax_code'] === '') {
            $payload['tax_code'] = 'standard';
        }

        // Normalize shipping fields: ensure non-null numeric value
        $payload['separate_shipping'] = (bool) ($payload['separate_shipping'] ?? false);
        if (! array_key_exists('shipping_fee_per_order', $payload) || $payload['shipping_fee_per_order'] === null || $payload['shipping_fee_per_order'] === '') {
            $payload['shipping_fee_per_order'] = 0.0;
        }

        // Normalize lot management toggle
        $payload['manage_by_lot'] = (bool) ($payload['manage_by_lot'] ?? false);

        // Normalize ordering constraints
        if (array_key_exists('moq', $payload) && ($payload['moq'] === '' || is_null($payload['moq']))) {
            $payload['moq'] = null;
        }
        if (array_key_exists('pack_size', $payload) && ($payload['pack_size'] === '' || is_null($payload['pack_size']))) {
            $payload['pack_size'] = null;
        }

        if ($this->editingMaterialId) {
            /** @var Material $m */
            $m = Material::query()->findOrFail($this->editingMaterialId);
            $m->update($payload);
            $this->upsertConversion($m, $payload);
        } else {
            /** @var Material $m */
            $m = Material::query()->create($payload);
            $this->upsertConversion($m, $payload);
        }

        $this->showMaterialModal = false;
        $this->dispatch('toast', type: 'success', message: 'Material saved');
    }

    protected function resetMaterialForm(): void
    {
        $this->materialForm = [
            'sku' => null,
            'name' => null,
            'tax_code' => 'standard',
            'unit_stock' => null,
            'unit_purchase_default' => null,
            'safety_stock' => 0,
            'category_id' => null,
            'current_stock' => null,
            'manufacturer_name' => null,
            'storage_location' => null,
            'applicable_regulation' => null,
            'ghs_mark' => null,
            'ghs_mark_options' => [],
            'protective_equipment' => null,
            'unit_price' => null,
            'conversion_factor_purchase_to_stock' => null,
            'preferred_supplier_id' => null,
            'separate_shipping' => false,
            'shipping_fee_per_order' => null,
            'manage_by_lot' => false,
            // Ordering constraints
            'moq' => null,
            'pack_size' => null,
        ];
    }

    protected function upsertConversion(Material $material, array $payload): void
    {
        $from = $payload['unit_purchase_default'] ?? null;
        $to = $payload['unit_stock'] ?? null;
        $factor = $payload['conversion_factor_purchase_to_stock'] ?? null;
        if (! empty($from) && ! empty($to) && ! is_null($factor)) {
            // Create or update conversion for this material
            \Lastdino\ProcurementFlow\Models\UnitConversion::query()->updateOrCreate(
                [
                    'material_id' => $material->id,
                    'from_unit' => (string) $from,
                    'to_unit' => (string) $to,
                ],
                [
                    'factor' => (float) $factor,
                ]
            );
        }
    }

    public function render(): View
    {
        return view('procflow::livewire.procurement.materials.index');
    }

    // --- SDS 管理 ---
    public function openSdsModal(int $materialId): void
    {
        /** @var Material $m */
        $m = Material::query()->findOrFail($materialId);
        $this->sdsMaterialId = $m->id;
        $this->reset('sdsUpload');
        $this->showSdsModal = true;
    }

    public function uploadSds(): void
    {
        $this->validateOnly('sdsUpload');
        if (! $this->sdsMaterialId || ! $this->sdsUpload) {
            return;
        }

        /** @var Material $m */
        $m = Material::query()->findOrFail($this->sdsMaterialId);

        $m
            ->addMedia($this->sdsUpload->getRealPath())
            ->usingFileName('sds.pdf')
            ->usingName('SDS')
            ->toMediaCollection('sds');

        $this->reset('sdsUpload');
        $this->dispatch('toast', type: 'success', message: __('procflow::materials.sds.saved'));
    }

    public function deleteSds(): void
    {
        if (! $this->sdsMaterialId) {
            return;
        }
        /** @var Material $m */
        $m = Material::query()->findOrFail($this->sdsMaterialId);
        $media = $m->getFirstMedia('sds');
        if ($media) {
            $media->delete();
            $this->dispatch('toast', type: 'success', message: __('procflow::materials.sds.deleted'));
        }
    }
}
