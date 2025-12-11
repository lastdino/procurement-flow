<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Models\Material;
use Lastdino\ProcurementFlow\Models\PurchaseOrder;
use Illuminate\Support\Facades\Cache;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class Dashboard extends Component
{
    public function getOpenPoCountProperty(): int
    {
        $query = fn () => (int) PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::Receiving,
            ])
            ->count();

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.open_po_count', now()->addMinutes(3), $query);
        }

        return $query();
    }

    public function getThisMonthTotalProperty(): float
    {
        $query = fn () => (float) PurchaseOrder::query()
            ->whereNotNull('issue_date')
            ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total');

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.this_month_total', now()->addMinutes(3), $query);
        }

        return $query();
    }

    public function getLowStocksProperty()
    {
        $query = fn () => Material::query()
            ->select(['id', 'sku', 'name', 'manage_by_lot', 'current_stock', 'safety_stock'])
            ->withSum('lots', 'qty_on_hand')
            ->whereNotNull('safety_stock')
            ->where(function ($q) {
                // Lot-managed: compare sum(lots.qty_on_hand) with safety_stock
                $q->where(function ($sq) {
                    $lotsTable = (new \Lastdino\ProcurementFlow\Models\MaterialLot())->getTable();
                    $materialsTable = (new \Lastdino\ProcurementFlow\Models\Material())->getTable();

                    $sub = "(select COALESCE(sum({$lotsTable}.qty_on_hand), 0) from {$lotsTable} where {$lotsTable}.material_id = {$materialsTable}.id)";

                    $sq->where('manage_by_lot', true)
                        ->whereRaw("{$sub} < COALESCE(safety_stock, 0)");
                })
                // Non-lot: compare current_stock with safety_stock
                ->orWhere(function ($sq) {
                    $sq->where(function ($w) {
                        $w->whereNull('manage_by_lot')->orWhere('manage_by_lot', false);
                    })
                    ->whereRaw('COALESCE(current_stock, 0) < COALESCE(safety_stock, 0)');
                });
            })
            // Using the alias from withSum() is safe in ORDER BY
            ->orderByRaw('CASE WHEN manage_by_lot THEN COALESCE(lots_sum_qty_on_hand, 0) ELSE COALESCE(current_stock, 0) END asc')
            ->limit(10)
            ->get();

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.low_stocks', now()->addMinute(), $query);
        }

        return $query();
    }

    public function getOverduePoCountProperty(): int
    {
        $query = fn () => (int) PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::Receiving,
            ])
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', now()->startOfDay())
            ->count();

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.overdue_po_count', now()->addMinutes(3), $query);
        }

        return $query();
    }

    public function getUpcomingPoCount7dProperty(): int
    {
        $start = now()->startOfDay();
        $end = now()->copy()->addDays(7)->endOfDay();

        $query = fn () => (int) PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::Receiving,
            ])
            ->whereBetween('expected_date', [$start, $end])
            ->count();

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.upcoming_po_count_7d', now()->addMinutes(3), $query);
        }

        return $query();
    }

    public function getLowStockCriticalCountProperty(): int
    {
        // Critical: stock <= 50% of safety_stock
        $query = function () {
            $materials = Material::query()
                ->select(['id', 'manage_by_lot', 'current_stock', 'safety_stock'])
                ->withSum('lots', 'qty_on_hand')
                ->whereNotNull('safety_stock')
                ->get();

            $count = 0;
            foreach ($materials as $m) {
                $safety = (float) ($m->safety_stock ?? 0);
                $threshold = 0.5 * $safety;
                $stock = (float) (
                    $m->manage_by_lot
                        ? ($m->lots_sum_qty_on_hand ?? 0)
                        : ($m->current_stock ?? 0)
                );
                if ($stock <= $threshold) {
                    $count++;
                }
            }

            return $count;
        };

        if (config('app.env') === 'production') {
            return (int) Cache::remember('procflow.dashboard.low_stocks_critical_count', now()->addMinutes(1), $query);
        }

        return $query();
    }

    /**
     * Supplier ranking Top3 by spend in the last 30 days.
     * Returns collection of arrays: [supplier_id, name, total]
     */
    public function getSupplierTop3Property(): Collection
    {
        $query = function () {
            $since = now()->copy()->subDays(30);

            $rows = PurchaseOrder::query()
                ->select(['supplier_id'])
                ->selectRaw('SUM(COALESCE(total,0)) as spend_total')
                ->whereNotNull('issue_date')
                ->where('issue_date', '>=', $since)
                ->whereNotNull('supplier_id')
                ->with(['supplier:id,name,is_active'])
                ->groupBy('supplier_id')
                ->orderByDesc('spend_total')
                ->limit(3)
                ->get();

            return $rows->map(function ($po) {
                return [
                    'supplier_id' => (int) $po->supplier_id,
                    'name' => (string) ($po->supplier->name ?? '—'),
                    'total' => (float) $po->spend_total,
                ];
            });
        };

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.supplier_top3', now()->addMinutes(5), $query);
        }

        return $query();
    }

    /**
     * Weekly spend sparkline for last 12 weeks based on issue_date.
     * Returns array of 12 points: [ ['week_start' => Y-m-d, 'total' => float], ... ]
     */
    public function getWeeklySpendSparklineProperty(): array
    {
        $query = function () {
            $end = now()->endOfWeek();
            $start = now()->copy()->subWeeks(11)->startOfWeek();

            $pos = PurchaseOrder::query()
                ->select(['id', 'issue_date', 'total'])
                ->whereNotNull('issue_date')
                ->whereBetween('issue_date', [$start, $end])
                ->get();

            // Bucket by week start date (Y-m-d)
            $buckets = [];
            for ($i = 11; $i >= 0; $i--) {
                $wkStart = now()->copy()->subWeeks($i)->startOfWeek()->format('Y-m-d');
                $buckets[$wkStart] = 0.0;
            }

            foreach ($pos as $po) {
                /** @var \Illuminate\Support\CarbonImmutable|\Illuminate\Support\Carbon $d */
                $d = $po->issue_date;
                $wkStart = $d->copy()->startOfWeek()->format('Y-m-d');
                if (array_key_exists($wkStart, $buckets)) {
                    $buckets[$wkStart] += (float) ($po->total ?? 0);
                }
            }

            return collect($buckets)->map(function ($total, $wkStart) {
                return ['week_start' => $wkStart, 'total' => (float) $total];
            })->values()->all();
        };

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.weekly_spend_12', now()->addMinutes(5), $query);
        }

        return $query();
    }

    /**
     * OTIF (On-Time In Full) over last 30 days based on PO Items.
     * Returns [percent => float, on_time_full => int, total => int].
     */
    public function getOtif30dProperty(): array
    {
        $query = function () {
            $since = now()->copy()->subDays(30);

            // Load PO Items within timeframe (by PO issue_date) with related receivings
            $items = PurchaseOrderItem::query()
                ->select(['id', 'purchase_order_id', 'material_id', 'unit_purchase', 'qty_ordered', 'qty_canceled', 'expected_date'])
                ->with([
                    'purchaseOrder:id,issue_date,expected_date',
                    'receivingItems:id,purchase_order_item_id,receiving_id,qty_received',
                    'receivingItems.receiving:id,received_at',
                ])
                ->whereHas('purchaseOrder', function ($q) use ($since) {
                    $q->whereNotNull('issue_date')->where('issue_date', '>=', $since);
                })
                // Exclude only shipping fee rows (unit_purchase = 'shipping')
                ->where(function ($q) {
                    $q->whereNull('unit_purchase')
                      ->orWhere('unit_purchase', '!=', 'shipping');
                })
                ->get();

            $total = 0;
            $onTimeFull = 0;

            foreach ($items as $item) {
                $total++;

                // Exclude fully canceled lines from OTIF
                $ordered = max(((float) ($item->qty_ordered ?? 0)) - ((float) ($item->qty_canceled ?? 0)), 0.0);
                if ($ordered <= 0) {
                    // No effective order quantity -> skip from denominator
                    $total--;
                    continue;
                }
                $receivedTotal = 0.0;
                $lastReceivedAt = null;

                foreach ($item->receivingItems as $ri) {
                    $receivedTotal += (float) ($ri->qty_received ?? 0);
                    $rec = $ri->receiving?->received_at;
                    if ($rec !== null) {
                        $ts = $rec->timestamp;
                        if ($lastReceivedAt === null || $ts > $lastReceivedAt) {
                            $lastReceivedAt = $ts;
                        }
                    }
                }

                $expected = $item->expected_date ?: $item->purchaseOrder?->expected_date;

                $isFull = $receivedTotal >= $ordered && $ordered > 0;
                $isOnTime = false;
                if ($expected !== null) {
                    // Compare last received at end-of-day against expected end-of-day
                    $isOnTime = $lastReceivedAt !== null && $lastReceivedAt <= $expected->copy()->endOfDay()->timestamp;
                }

                if ($isFull && $isOnTime) {
                    $onTimeFull++;
                }
            }

            $percent = $total > 0 ? (100.0 * $onTimeFull / $total) : 100.0;

            return [
                'percent' => round($percent, 1),
                'on_time_full' => $onTimeFull,
                'total' => $total,
            ];
        };

        if (config('app.env') === 'production') {
            return Cache::remember('procflow.dashboard.otif_30d', now()->addMinutes(5), $query);
        }

        return $query();
    }

    public function render(): View
    {
        return view('procflow::livewire.procurement.dashboard');
    }
}
