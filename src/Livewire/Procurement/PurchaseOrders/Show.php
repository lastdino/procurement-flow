<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders;

use Illuminate\Contracts\View\View;
use Lastdino\ProcurementFlow\Models\PurchaseOrder;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItem;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Services\UnitConversionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public PurchaseOrder $po;

    // Modal state for editing expected date (per line)
    public bool $showExpectedModal = false;
    public ?int $editingItemId = null;
    public ?string $editingExpectedDate = null; // Y-m-d

    public function mount(PurchaseOrder $po): void
    {
        // Load all relations required for detail view and receiving history
        $this->po = $po->load([
            'supplier',
            'requester',
            'items.material',
            'receivings.items',
            'receivings.items.material',
            'receivings.items.purchaseOrderItem',
        ]);
    }

    public function render(): View
    {
        return view('procflow::livewire.procurement.purchase-orders.show', [
            'po' => $this->po,
        ]);
    }

    /**
     * Cancel a draft Purchase Order. Only allowed when current status is Draft.
     */
    public function cancelPo(): void
    {
        $po = $this->po->fresh();

        if (! $po) {
            $this->dispatch('toast', type: 'error', message: 'Purchase order not found');
            return;
        }

        // Normalize status value and check
        $statusValue = is_string($po->status) ? $po->status : ($po->status->value ?? '');
        if ($statusValue !== PurchaseOrderStatus::Draft->value) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.cancel_not_allowed'));
            return;
        }

        $po->status = PurchaseOrderStatus::Canceled;
        $po->save();

        $this->po = $po->load([
            'supplier',
            'requester',
            'items.material',
            'receivings.items',
            'receivings.items.material',
            'receivings.items.purchaseOrderItem',
        ]);

        $po->cancelApprovalFlowTask(
            userId: Auth::id(),      // キャンセルを実行するユーザーのID（通常は申請者自身）
            comment: '' // キャンセル理由（オプション）
        );

        $this->dispatch('toast', type: 'success', message: __('procflow::po.detail.canceled_toast'));
    }

    /**
     * Cancel a single Purchase Order Item (entire remaining quantity).
     * Rules:
     * - Allowed only when PO status is Issued or Receiving.
     * - Shipping lines cannot be canceled by this action (kept as-is).
     * - If partially received, cancel the unreceived remainder only.
     */
    public function cancelItem(int $itemId, ?string $reason = null): void
    {
        /** @var PurchaseOrderItem|null $item */
        $item = PurchaseOrderItem::query()->with(['purchaseOrder', 'material'])->find($itemId);
        if (! $item) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_not_found'));
            return;
        }

        $po = $item->purchaseOrder;
        if (! in_array($po->status, [PurchaseOrderStatus::Issued, PurchaseOrderStatus::Receiving], true)) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_cancel_not_allowed'));
            return;
        }

        // Do not cancel shipping lines via this action
        if ($item->unit_purchase === 'shipping') {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_cancel_shipping_not_allowed'));
            return;
        }

        // Already fully canceled?
        $ordered = (float) ($item->qty_ordered ?? 0);
        $canceled = (float) ($item->qty_canceled ?? 0);
        if ($canceled >= $ordered - 1e-9) {
            $this->dispatch('toast', type: 'info', message: __('procflow::po.detail.item_already_canceled'));
            return;
        }

        // Compute received quantity in purchase unit
        $material = $item->material; // may be null for ad-hoc
        $receivedBase = (float) $item->receivingItems()->sum('qty_base');
        if ($material) {
            /** @var UnitConversionService $conv */
            $conv = app(UnitConversionService::class);
            $factor = (float) $conv->factor($material, $item->unit_purchase, $material->unit_stock);
            $receivedPurchase = $factor > 0 ? ($receivedBase / $factor) : 0.0;
        } else {
            // ad-hoc: base == purchase
            $receivedPurchase = $receivedBase;
        }

        $alreadyCanceled = $canceled;
        $remaining = max($ordered - $receivedPurchase - $alreadyCanceled, 0.0);
        if ($remaining <= 1e-9) {
            $this->dispatch('toast', type: 'info', message: __('procflow::po.detail.item_no_remaining_to_cancel'));
            return;
        }

        // Apply cancel of the remaining qty
        $item->qty_canceled = $alreadyCanceled + $remaining;
        $item->canceled_at = now();
        if ($reason) {
            $item->canceled_reason = $reason;
        }
        $item->save();

        // Update PO status depending on whether any receipt exists and if any effective remaining
        $po->refresh();
        $po->loadMissing(['items', 'receivings.items']);

        // Determine if any receipt exists for this PO at all
        $hasAnyReceipt = $po->receivings->flatMap->items->isNotEmpty();

        // Compute effective remaining quantity across items
        $effectiveRemaining = 0.0;
        foreach ($po->items as $lit) {
            $ordered = (float) ($lit->qty_ordered ?? 0);
            $canceledQty = (float) ($lit->qty_canceled ?? 0);
            $effectiveRemaining += max($ordered - $canceledQty, 0.0);
        }

        if ($effectiveRemaining <= 1e-9) {
            // No quantities left to receive
            $po->status = $hasAnyReceipt ? PurchaseOrderStatus::Closed : PurchaseOrderStatus::Canceled;
            $po->save();
        }

        // Reload page data
        $this->po = $po->load([
            'supplier',
            'requester',
            'items.material',
            'receivings.items',
            'receivings.items.material',
            'receivings.items.purchaseOrderItem',
        ]);

        $this->dispatch('toast', type: 'success', message: __('procflow::po.detail.item_canceled_toast'));
    }

    /**
     * Open modal to edit expected date for a specific item.
     */
    public function openExpectedDateModal(int $itemId): void
    {
        $po = $this->po->fresh(['items']);
        if (! $po) {
            return;
        }

        $statusValue = is_string($po->status) ? $po->status : ($po->status->value ?? '');
        if ($statusValue === PurchaseOrderStatus::Closed->value) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.cancel_not_allowed'));
            return;
        }

        /** @var PurchaseOrderItem|null $item */
        $item = $po->items->firstWhere('id', $itemId);
        if (! $item) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_not_found'));
            return;
        }

        // Do not allow for shipping lines
        if ($item->unit_purchase === 'shipping') {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_cancel_shipping_not_allowed'));
            return;
        }

        $this->editingItemId = $item->id;
        $this->editingExpectedDate = $item->expected_date?->format('Y-m-d');
        $this->showExpectedModal = true;
    }

    /**
     * Persist expected date for the currently editing item.
     */
    public function saveExpectedDate(): void
    {
        if (! $this->editingItemId) {
            return;
        }

        $this->validate([
            'editingExpectedDate' => ['nullable', 'date'],
        ]);

        $po = $this->po->fresh(['items']);
        if (! $po) {
            return;
        }

        $statusValue = is_string($po->status) ? $po->status : ($po->status->value ?? '');
        if ($statusValue === PurchaseOrderStatus::Closed->value) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.cancel_not_allowed'));
            return;
        }

        /** @var PurchaseOrderItem|null $item */
        $item = $po->items->firstWhere('id', $this->editingItemId);
        if (! $item) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_not_found'));
            return;
        }

        if ($item->unit_purchase === 'shipping') {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.item_cancel_shipping_not_allowed'));
            return;
        }

        $item->expected_date = $this->editingExpectedDate ? \Carbon\Carbon::parse($this->editingExpectedDate) : null;
        $item->save();

        // Reload for UI
        $this->po->refresh();
        $this->po->load([
            'supplier',
            'requester',
            'items.material',
            'receivings.items',
            'receivings.items.material',
            'receivings.items.purchaseOrderItem',
        ]);

        $this->showExpectedModal = false;
        $this->editingItemId = null;
        $this->editingExpectedDate = null;

        $this->dispatch('toast', type: 'success', message: __('procflow::po.labels.saved'));
    }
}
