<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement\Suppliers;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lastdino\ProcurementFlow\Models\Supplier;

class Index extends Component
{
    public string $q = '';

    // Modal state for create/edit supplier
    public bool $showSupplierModal = false;
    public ?int $editingSupplierId = null;
    /** @var array{name:?string,code:?string,email:?string,email_cc:?string,phone:?string,address:?string,contact_person_name:?string,is_active:bool,auto_send_po:bool} */
    public array $supplierForm = [
        'name' => null,
        'code' => null,
        'email' => null,
        'email_cc' => null,
        'phone' => null,
        'address' => null,
        'contact_person_name' => null,
        'is_active' => true,
        'auto_send_po' => false,
    ];

    // Detail modal state
    public bool $showSupplierDetailModal = false;
    public ?int $selectedSupplierId = null;
    public ?array $supplierDetail = null;

    // Delete confirmation state
    public bool $showDeleteConfirm = false;
    public ?int $deletingSupplierId = null;

    public function getSuppliersProperty()
    {
        $q = (string) $this->q;
        return Supplier::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    public function render(): View
    {
        return view('procflow::livewire.procurement.suppliers.index');
    }

    public function openCreateSupplier(): void
    {
        $this->resetSupplierForm();
        $this->editingSupplierId = null;
        $this->showSupplierModal = true;
    }

    public function openEditSupplier(int $id): void
    {
        /** @var Supplier $s */
        $s = Supplier::query()->findOrFail($id);
        $this->editingSupplierId = $s->id;
        $this->supplierForm = [
            'name' => $s->name,
            'code' => $s->code,
            'email' => $s->email,
            'email_cc' => $s->email_cc,
            'phone' => $s->phone,
            'address' => $s->address,
            'contact_person_name' => $s->contact_person_name,
            'is_active' => (bool) $s->is_active,
            'auto_send_po' => (bool) $s->auto_send_po,
        ];
        $this->showSupplierModal = true;
    }

    public function closeSupplierModal(): void
    {
        $this->showSupplierModal = false;
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingSupplierId = $id;
        $this->showDeleteConfirm = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteConfirm = false;
        $this->deletingSupplierId = null;
    }

    protected function supplierRules(): array
    {
        return [
            'supplierForm.name' => ['required', 'string', 'max:255'],
            'supplierForm.code' => ['nullable', 'string', 'max:255'],
            'supplierForm.email' => ['nullable', 'email', 'max:255'],
            'supplierForm.email_cc' => ['nullable', 'string', 'max:1000'],
            'supplierForm.phone' => ['nullable', 'string', 'max:255'],
            'supplierForm.address' => ['nullable', 'string'],
            'supplierForm.contact_person_name' => ['nullable', 'string', 'max:255'],
            'supplierForm.is_active' => ['boolean'],
            'supplierForm.auto_send_po' => ['boolean'],
        ];
    }

    public function saveSupplier(): void
    {
        $data = $this->validate($this->supplierRules());
        $payload = $data['supplierForm'];

        if ($this->editingSupplierId) {
            /** @var Supplier $s */
            $s = Supplier::query()->findOrFail($this->editingSupplierId);
            $s->update($payload);
        } else {
            Supplier::query()->create($payload);
        }

        $this->showSupplierModal = false;
        // refresh list by touching q (or rely on computed getter on next render)
        $this->dispatch('toast', type: 'success', message: 'Supplier saved');
    }

    public function deleteSupplier(): void
    {
        if (! $this->deletingSupplierId) {
            return;
        }

        /** @var Supplier $s */
        $s = Supplier::query()->findOrFail($this->deletingSupplierId);

        // Prevent deletion if related purchase orders exist
        if ($s->purchaseOrders()->exists()) {
            $this->dispatch('toast', type: 'error', message: __('procflow::suppliers.delete.has_pos_error'));
            $this->cancelDelete();
            return;
        }

        $s->delete();
        $this->dispatch('toast', type: 'success', message: __('procflow::suppliers.delete.deleted'));
        $this->cancelDelete();
    }

    // Detail modal helpers
    public function openSupplierDetail(int $id): void
    {
        $this->selectedSupplierId = $id;
        $this->loadSupplierDetail();
        $this->showSupplierDetailModal = true;
    }

    public function closeSupplierDetail(): void
    {
        $this->showSupplierDetailModal = false;
        $this->selectedSupplierId = null;
        $this->supplierDetail = null;
    }

    public function loadSupplierDetail(): void
    {
        if (! $this->selectedSupplierId) {
            $this->supplierDetail = null;
            return;
        }

        /** @var Supplier $model */
        $model = Supplier::query()->with(['purchaseOrders' => function ($q) {
            $q->latest('id');
        }])->findOrFail($this->selectedSupplierId);

        $this->supplierDetail = $model->toArray();
    }

    protected function resetSupplierForm(): void
    {
        $this->supplierForm = [
            'name' => null,
            'code' => null,
            'email' => null,
            'email_cc' => null,
            'phone' => null,
            'address' => null,
            'contact_person_name' => null,
            'is_active' => true,
            'auto_send_po' => false,
        ];
    }
}
