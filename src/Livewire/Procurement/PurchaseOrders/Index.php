<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Lastdino\ProcurementFlow\Models\PurchaseOrder;
use Lastdino\ProcurementFlow\Models\OptionGroup;
use Lastdino\ProcurementFlow\Models\Option;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItemOptionValue;
use Lastdino\ProcurementFlow\Support\Settings;
use Lastdino\ProcurementFlow\Models\Receiving;
use Lastdino\ProcurementFlow\Models\ReceivingItem;
use Lastdino\ProcurementFlow\Models\PurchaseOrderItem;
use Lastdino\ProcurementFlow\Models\Supplier;
use Lastdino\ProcurementFlow\Models\Material;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Services\UnitConversionService;

class Index extends Component
{
    use WithPagination;
    public string $q = '';
    public string $status = '';
    // Separate filters
    public string $poNumber = '';
    public string $supplierId = '';
    public string $requesterId = '';
    // Date range filters (YYYY-MM-DD)
    public array $issueDate = [
        'start' => null,
        'end' => null,
    ];
    public array $expectedDate = [
        'start' => null,
        'end' => null,
    ];

    // Receiving date range filters (YYYY-MM-DD)
    public array $receivingDate = [
        'start' => null,
        'end' => null,
    ];

    // Export modal state
    public bool $showExportModal = false;
    // Matrix options
    public ?int $rowGroupId = null;
    public ?int $colGroupId = null;
    public string $aggregateType = 'amount'; // amount|quantity

    // Modal state for creating a PO
    public bool $showPoModal = false;
    /** @var array{supplier_id:?int,expected_date:?string,items:array<int,array{material_id:?int,unit_purchase:?string,qty_ordered:float|int|null,price_unit:float|int|null,tax_rate:float|int|null,description:?string,desired_date:?string|null,expected_date:?string|null,options:array<int,int|null>}>} */
    public array $poForm = [
        'supplier_id' => null,
        'expected_date' => null,
        'items' => [
            ['material_id' => '', 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => null, 'description' => null, 'desired_date' => null, 'expected_date' => null, 'options' => []],
        ],
    ];

    // Modal state for creating an Ad-hoc PO (materials not registered)
    public bool $showAdhocPoModal = false;
    /** @var array{supplier_id:?int,expected_date:?string,items:array<int,array{description:string|null,manufacturer:string|null,unit_purchase:string,qty_ordered:float|int|null,price_unit:float|int|null,tax_rate:float|int|null,desired_date:?string|null,expected_date:?string|null,options:array<int,int|null>}>} */
    public array $adhocForm = [
        'supplier_id' => null,
        'expected_date' => null,
        'items' => [
            ['description' => null, 'manufacturer' => null, 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => null, 'desired_date' => null, 'expected_date' => null, 'options' => []],
        ],
    ];

    // Detail modal removed — use Show page instead

    public function getOrdersProperty()
    {
        $q = (string) $this->q;
        $status = (string) $this->status;
        $poNumber = (string) $this->poNumber;
        $supplierId = (string) $this->supplierId;
        $requesterId = (string) $this->requesterId;
        $issueFrom = (string) $this->issueDate['start'];
        $issueTo = (string) $this->issueDate['end'];
        $expFrom = (string) $this->expectedDate['start'];
        $expTo = (string) $this->expectedDate['end'];

        return PurchaseOrder::query()
            ->with(['supplier', 'requester'])
            // Dedicated filters
            ->when($poNumber !== '', function ($query) use ($poNumber) {
                // Allow prefix/partial match for PO#
                $query->where('po_number', 'like', $poNumber.'%');
            })
            ->when($supplierId !== '', function ($query) use ($supplierId) {
                $query->where('supplier_id', (int) $supplierId);
            })
            ->when($requesterId !== '', function ($query) use ($requesterId) {
                $query->where('created_by', (int) $requesterId);
            })
            // Issue date range
            ->when($issueFrom !== '', function ($query) use ($issueFrom) {
                $query->whereDate('issue_date', '>=', $issueFrom);
            })
            ->when($issueTo !== '', function ($query) use ($issueTo) {
                $query->whereDate('issue_date', '<=', $issueTo);
            })
            // Expected date range
            ->when($expFrom !== '', function ($query) use ($expFrom) {
                $query->whereDate('expected_date', '>=', $expFrom);
            })
            ->when($expTo !== '', function ($query) use ($expTo) {
                $query->whereDate('expected_date', '<=', $expTo);
            })
            ->when($q !== '', function ($query) use ($q) {
                // キーワードを空白で分割して AND 条件を実現（案1）
                $keywords = preg_split('/\s+/u', trim((string) $q)) ?: [];

                if (count($keywords) > 1) {
                    // 複数語のときは、以下のフィールドに対して AND 検索：
                    // - materials.name（品名）
                    // - materials.manufacturer_name（メーカー名）
                    // - purchase_order_items.description（説明）
                    // - purchase_order_items.manufacturer（単発注文のメーカー名）
                    foreach ($keywords as $word) {
                        $like = "%{$word}%";
                        $query->where(function ($and) use ($like, $word) {
                            $and
                                // 資材マスタの品名／メーカー名
                                ->orWhereHas('items.material', function ($mq) use ($like) {
                                    $mq->where(function ($mm) use ($like) {
                                        $mm->where('name', 'like', $like)
                                           ->orWhere('manufacturer_name', 'like', $like);
                                    });
                                })
                                // 発注アイテムの説明／単発メーカー名
                                ->orWhereHas('items', function ($iq) use ($like) {
                                    $iq->where(function ($iqq) use ($like) {
                                        $iqq->where('description', 'like', $like)
                                            ->orWhere('manufacturer', 'like', $like);
                                    });
                                });
                        });
                    }
                } else {
                    // 単一語は従来の広い OR 検索を維持（UIの利便性維持）
                    $single = $keywords[0] ?? $q;
                    $query->where(function ($sub) use ($single) {
                        $sub->where('po_number', 'like', "%{$single}%")
                            ->orWhere('notes', 'like', "%{$single}%")
                            // サプライヤー名
                            ->orWhereHas('supplier', function ($sq) use ($single) {
                                $sq->where('name', 'like', "%{$single}%");
                            })
                            // 発注者（作成者）名
                            ->orWhereHas('requester', function ($uq) use ($single) {
                                $uq->where('name', 'like', "%{$single}%");
                            })
                            // アイテムの品名／メーカー名（資材マスタ）
                            ->orWhereHas('items.material', function ($mq) use ($single) {
                                $mq->where(function ($mm) use ($single) {
                                    $mm->where('name', 'like', "%{$single}%")
                                       ->orWhere('manufacturer_name', 'like', "%{$single}%");
                                });
                            })
                            // 発注アイテムのスキャン用トークン（先頭一致／全一致どちらも可）
                            ->orWhereHas('items', function ($iq) use ($single) {
                                $iq->where(function ($iqq) use ($single) {
                                    $iqq->where('scan_token', $single)
                                        ->orWhere('scan_token', 'like', $single.'%');
                                });
                            })
                            // 単発（アドホック）品目の説明
                            ->orWhereHas('items', function ($iq) use ($single) {
                                $iq->where('description', 'like', "%{$single}%");
                            });
                    });
                }
            })
            ->when($status !== '', fn ($qrb) => $qrb->where('status', $status))
            ->latest('id')
            ->paginate(50);
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPoNumber(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierId(): void
    {
        $this->resetPage();
    }

    public function updatedRequesterId(): void
    {
        $this->resetPage();
    }

    public function updatedIssueDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedIssueDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedExpectedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedExpectedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->status = '';
        $this->poNumber = '';
        $this->supplierId = '';
        $this->requesterId = '';
        $this->issueDate = [
            'start' => null,
            'end' => null,
        ];
        $this->expectedDate = [
            'start' => null,
            'end' => null,
        ];
        $this->receivingDate = [
            'start' => null,
            'end' => null,
        ];
        $this->resetPage();
    }

    public function openExportModal(): void
    {
        $this->resetErrorBag('receivingDate');
        $this->resetErrorBag('aggregateType');
        // defaults
        if (! in_array($this->aggregateType, ['amount', 'quantity'], true)) {
            $this->aggregateType = 'amount';
        }
        $this->showExportModal = true;
    }

    public function render(): View
    {
        return view('procflow::livewire.procurement.purchase-orders.index');
    }

    /**
     * Active option groups for select inputs in export modal.
     */
    public function getOptionGroupsProperty()
    {
        return OptionGroup::query()->active()->ordered()->get(['id', 'name']);
    }

    /**
     * Excel export for order & delivery history filtered by receiving date range.
     */
    public function exportExcel(): ?StreamedResponse
    {
        $from = (string) ($this->receivingDate['start'] ?? '');
        $to = (string) ($this->receivingDate['end'] ?? '');

        if ($from === '' || $to === '') {
            $this->addError('receivingDate', __('procflow::po.export.validation.receiving_required'));
            return null;
        }

        if (! in_array($this->aggregateType, ['amount', 'quantity'], true)) {
            $this->addError('aggregateType', __('procflow::po.export.validation.aggregate_required'));
            return null;
        }

        // Build dataset
        $items = ReceivingItem::query()
            ->join((new Receiving())->getTable() . ' as r', 'r.id', '=', (new ReceivingItem())->getTable() . '.receiving_id')
            ->whereDate('r.received_at', '>=', $from)
            ->whereDate('r.received_at', '<=', $to)
            ->with([
                'receiving:id,purchase_order_id,received_at,notes',
                // include manufacturer on item for ad-hoc lines
                'purchaseOrderItem:id,purchase_order_id,material_id,qty_ordered,price_unit,note,description,manufacturer',
                'purchaseOrderItem.purchaseOrder:id,po_number,supplier_id,issue_date',
                // include manufacturer_name on material when available
                'purchaseOrderItem.material:id,sku,name,manufacturer_name',
                'purchaseOrderItem.purchaseOrder.supplier:id,name',
                // Options (if any)
                'purchaseOrderItem.optionValues.option:id,name',
                'purchaseOrderItem.optionValues.group:id,name,sort_order',
            ])
            ->orderBy('r.received_at', 'asc')
            ->select((new ReceivingItem())->getTable() . '.*', 'r.received_at')
            ->get();

        // Spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Build dynamic option group headers based on data in range
        $baseBeforeOptionHeaders = [
            __('procflow::po.export.excel.headers.order_no'),
            __('procflow::po.export.excel.headers.supplier'),
            __('procflow::po.export.excel.headers.issue_date'),
            __('procflow::po.export.excel.headers.received_at'),
            __('procflow::po.export.excel.headers.sku'),
            __('procflow::po.export.excel.headers.name'),
            __('procflow::po.export.excel.headers.manufacturer'),
        ];
        $baseAfterOptionHeaders = [
            __('procflow::po.export.excel.headers.qty_ordered'),
            __('procflow::po.export.excel.headers.qty_received'),
            __('procflow::po.export.excel.headers.unit_price'),
            __('procflow::po.export.excel.headers.amount'),
            __('procflow::po.export.excel.headers.note'),
        ];

        // Collect unique option groups used in the result set
        /** @var array<int, array{id:int,name:string,sort:int}> $groupMeta */
        $groupMeta = [];
        foreach ($items as $riForGroups) {
            $poiForGroups = $riForGroups->purchaseOrderItem;
            $ovc = $poiForGroups?->optionValues;
            if (! $ovc) {
                continue;
            }
            foreach ($ovc as $ov) {
                $gid = (int) ($ov->group?->id ?? 0);
                if ($gid > 0 && ! isset($groupMeta[$gid])) {
                    $groupMeta[$gid] = [
                        'id' => $gid,
                        'name' => (string) ($ov->group?->name ?? ''),
                        'sort' => (int) ($ov->group?->sort_order ?? 0),
                    ];
                }
            }
        }

        // Sort groups by sort_order then name
        $sortedGroups = array_values($groupMeta);
        usort($sortedGroups, static function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['sort'] <=> $b['sort'];
        });

        $dynamicOptionHeaders = array_map(static fn ($g) => $g['name'], $sortedGroups);

        $headers = array_merge($baseBeforeOptionHeaders, $dynamicOptionHeaders, $baseAfterOptionHeaders);
        $rows = [$headers];

        foreach ($items as $ri) {
            /** @var ReceivingItem $ri */
            $poi = $ri->purchaseOrderItem;
            $po = $poi?->purchaseOrder;
            $rcv = $ri->receiving;
            $mat = $poi?->material;
            $poNumber = (string) ($po?->po_number ?? '');
            $supplierName = (string) ($po?->supplier?->name ?? '');
            $issueDate = $po?->issue_date ? Carbon::parse($po->issue_date)->format('Y-m-d') : '';
            $receivedAt = $rcv?->received_at ? Carbon::parse($rcv->received_at)->format('Y-m-d') : '';
            $sku = (string) ($mat?->sku ?? '');
            $name = (string) ($mat?->name ?? ($poi?->description ?? ''));
            $manufacturer = (string) ($mat?->manufacturer_name ?? ($poi?->manufacturer ?? ''));
            $qtyOrdered = (float) ($poi?->qty_ordered ?? 0);
            $qtyReceived = (float) ($ri->qty_received ?? 0);
            $unitPrice = (float) ($poi?->price_unit ?? 0);
            $amount = $unitPrice * $qtyReceived;
            $note = (string) ($poi?->note ?? '');

            // Map option values to dynamic group columns (option name per group)
            $optionValuesByGroupId = [];
            /** @var \Illuminate\Support\Collection<int, \Lastdino\ProcurementFlow\Models\PurchaseOrderItemOptionValue>|null $ovc */
            $ovc = $poi?->optionValues;
            if ($ovc) {
                foreach ($ovc as $ov) {
                    $gid = (int) ($ov->group?->id ?? 0);
                    if ($gid > 0) {
                        $optionValuesByGroupId[$gid] = (string) ($ov->option?->name ?? '');
                    }
                }
            }

            // Compose row: base-before, dynamic option group columns, base-after
            $dynamicOptionValues = [];
            foreach ($sortedGroups as $g) {
                $dynamicOptionValues[] = (string) ($optionValuesByGroupId[$g['id']] ?? '');
            }

            $values = array_merge(
                [$poNumber, $supplierName, $issueDate, $receivedAt, $sku, $name, $manufacturer],
                $dynamicOptionValues,
                [(float) $qtyOrdered, (float) $qtyReceived, (float) $unitPrice, (float) $amount, $note]
            );
            $rows[] = $values;
        }

        // Dump all rows starting at A1
        $sheet->fromArray($rows, null, 'A1', true);

        // Auto size columns
        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimensionByColumn($colIndex)->setAutoSize(true);
        }

        // ==============================
        // Matrix summary sheet (by options)
        // ==============================
        // Determine axis groups (allow user selection; else: prefer 費用区分/部門区分; else first/second detected)
        $rowGroup = null; // ['id'=>int,'name'=>string]
        $colGroup = null; // ['id'=>int,'name'=>string]
        $groupsById = [];
        foreach ($sortedGroups as $g) {
            $groupsById[(int) $g['id']] = $g;
        }
        $userSelectedRow = $this->rowGroupId !== null && $this->rowGroupId !== 0;
        $userSelectedCol = $this->colGroupId !== null && $this->colGroupId !== 0;

        if ($userSelectedRow) {
            $rid = (int) $this->rowGroupId;
            if (isset($groupsById[$rid])) {
                $rowGroup = $groupsById[$rid];
            }
        }
        if ($userSelectedCol) {
            $cid = (int) $this->colGroupId;
            if (isset($groupsById[$cid])) {
                $colGroup = $groupsById[$cid];
            }
        }
        // If neither axis selected by user, auto-detect preferred axes
        if (! $userSelectedRow && ! $userSelectedCol && $rowGroup === null) {
            foreach ($sortedGroups as $g) {
                if ($g['name'] === '費用区分') { $rowGroup = $g; break; }
            }
        }
        if (! $userSelectedRow && ! $userSelectedCol && $colGroup === null) {
            foreach ($sortedGroups as $g) {
                if ($g['name'] === '部門区分') { $colGroup = $g; break; }
            }
        }
        if (! $userSelectedRow && $rowGroup === null && ! empty($sortedGroups)) {
            $rowGroup = $sortedGroups[0];
        }
        // If user selected only row group, keep single-axis (do not auto-fill column)
        if (! $userSelectedRow && $colGroup === null) {
            foreach ($sortedGroups as $g) {
                if (! $rowGroup || $g['id'] !== $rowGroup['id']) { $colGroup = $g; break; }
            }
        }
        // If user selected only column group and row is still null, try to auto-pick a different row group
        if ($userSelectedCol && ! $userSelectedRow && $rowGroup === null) {
            foreach ($sortedGroups as $g) {
                if ($g['id'] !== $colGroup['id']) { $rowGroup = $g; break; }
            }
            // Fallback: if nothing else, allow same group as row
            if ($rowGroup === null && $colGroup !== null) {
                $rowGroup = $colGroup;
            }
        }

        // Only create a matrix sheet when at least one axis exists
        if ($rowGroup !== null) {
            $rowLabel = (string) $rowGroup['name'];
            $colLabel = (string) ($colGroup['name'] ?? '');

            // Collect distinct option values for axis groups from the dataset
            $rowKeys = [];
            $colKeys = [];
            foreach ($items as $ri0) {
                $poi0 = $ri0->purchaseOrderItem;
                $ovc0 = $poi0?->optionValues;
                $rowKey = __('procflow::po.export.excel.matrix.unset');
                $colKey = __('procflow::po.export.excel.matrix.unset');
                if ($ovc0) {
                    foreach ($ovc0 as $ov0) {
                        $gid0 = (int) ($ov0->group?->id ?? 0);
                        if ($rowGroup && $gid0 === (int) $rowGroup['id']) {
                            $rowKey = (string) ($ov0->option?->name ?? __('procflow::po.export.excel.matrix.unset'));
                        }
                        if ($colGroup && $gid0 === (int) $colGroup['id']) {
                            $colKey = (string) ($ov0->option?->name ?? __('procflow::po.export.excel.matrix.unset'));
                        }
                    }
                }
                $rowKeys[$rowKey] = true;
                if ($colGroup !== null) {
                    $colKeys[$colKey] = true;
                }
            }

            // Fallback for when there is no column group: use single column unset label
            if ($colGroup === null) {
                $colKeys = [__('procflow::po.export.excel.matrix.unset') => true];
            }

            $rowValues = array_keys($rowKeys);
            sort($rowValues, SORT_NATURAL);
            $colValues = array_keys($colKeys);
            sort($colValues, SORT_NATURAL);

            // Initialize matrix sums
            $matrix = [];
            foreach ($rowValues as $rk) {
                $matrix[$rk] = [];
                foreach ($colValues as $ck) {
                    $matrix[$rk][$ck] = 0.0;
                }
            }

            // Aggregate amounts (qty_received * unit_price)
            foreach ($items as $ri1) {
                $poi1 = $ri1->purchaseOrderItem;
                $ovc1 = $poi1?->optionValues;
                $rowKey = __('procflow::po.export.excel.matrix.unset');
                $colKey = __('procflow::po.export.excel.matrix.unset');
                if ($ovc1) {
                    foreach ($ovc1 as $ov1) {
                        $gid1 = (int) ($ov1->group?->id ?? 0);
                        if ($rowGroup && $gid1 === (int) $rowGroup['id']) {
                            $rowKey = (string) ($ov1->option?->name ?? __('procflow::po.export.excel.matrix.unset'));
                        }
                        if ($colGroup && $gid1 === (int) $colGroup['id']) {
                            $colKey = (string) ($ov1->option?->name ?? __('procflow::po.export.excel.matrix.unset'));
                        }
                    }
                }
                $qty1 = (float) ($ri1->qty_received ?? 0);
                $amount1 = (float) (($poi1?->price_unit ?? 0) * $qty1);
                if (! isset($matrix[$rowKey])) {
                    $matrix[$rowKey] = [];
                }
                if (! isset($matrix[$rowKey][$colKey])) {
                    $matrix[$rowKey][$colKey] = 0.0;
                }
                $matrix[$rowKey][$colKey] += ($this->aggregateType === 'quantity') ? $qty1 : $amount1;
            }

            // Build sheet rows
            $matrixHeaders = array_merge([''], $colValues, ['計']);
            $matrixRows = [$matrixHeaders];
            $columnTotals = array_fill_keys($colValues, 0.0);
            $grandTotal = 0.0;
            foreach ($rowValues as $rk) {
                $rowTotal = 0.0;
                $dataRow = [$rk];
                foreach ($colValues as $ck) {
                    $val = (float) ($matrix[$rk][$ck] ?? 0.0);
                    $dataRow[] = $val;
                    $rowTotal += $val;
                    $columnTotals[$ck] += $val;
                }
                $grandTotal += $rowTotal;
                $dataRow[] = $rowTotal;
                $matrixRows[] = $dataRow;
            }
            // Final totals row
            $totalsRow = [__('procflow::po.export.excel.matrix.total')];
            foreach ($colValues as $ck) {
                $totalsRow[] = (float) $columnTotals[$ck];
            }
            $totalsRow[] = (float) $grandTotal;
            $matrixRows[] = $totalsRow;

            // Create new sheet and dump
            $matrixSheet = $spreadsheet->createSheet();
            $matrixSheet->setTitle(__('procflow::po.export.excel.matrix.sheet_title'));
            $matrixSheet->fromArray($matrixRows, null, 'A1', true);
            // Auto-size
            foreach (range(1, count($matrixHeaders)) as $colIndex) {
                $matrixSheet->getColumnDimensionByColumn($colIndex)->setAutoSize(true);
            }
        }

        $today = Carbon::now()->format('Ymd');
        $filename = "注文納品履歴_{$today}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename);
    }

    // Modal helpers
    public function openCreatePo(): void
    {
        $this->resetPoForm();
        $this->showPoModal = true;
    }

    public function closeCreatePo(): void
    {
        $this->showPoModal = false;
    }

    public function addPoItem(): void
    {
        $this->poForm['items'][] = ['material_id' => '', 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => null, 'tax_locked' => false, 'description' => null, 'desired_date' => null, 'expected_date' => null, 'note' => null, 'options' => []];
    }

    public function removePoItem(int $index): void
    {
        unset($this->poForm['items'][$index]);
        $this->poForm['items'] = array_values($this->poForm['items']);
    }

    public function getSuppliersProperty()
    {
        return \Lastdino\ProcurementFlow\Models\Supplier::query()
            ->orderBy('name')
            ->get(['id','name','auto_send_po']);
    }

    public function getMaterialsProperty()
    {
        return \Lastdino\ProcurementFlow\Models\Material::query()
            ->active()
            ->orderBy('sku')
            ->get();
    }

    public function getUsersProperty()
    {
        return \App\Models\User::query()
            ->orderBy('name')
            ->limit(100)
            ->get(['id','name']);
    }

    // Active option groups and options for Create PO modal (UI auto-reflection)
    public function getActiveGroupsProperty()
    {
        return OptionGroup::query()->active()->ordered()->get(['id','name']);
    }

    /**
     * @return array<int, array<int, array{id:int,name:string}>>
     */
    public function getActiveOptionsByGroupProperty(): array
    {
        $options = Option::query()->active()->ordered()->get(['id','name','group_id']);
        $by = [];
        foreach ($options as $opt) {
            $gid = (int) $opt->getAttribute('group_id');
            $by[$gid][] = [
                'id' => (int) $opt->getKey(),
                'name' => (string) $opt->getAttribute('name'),
            ];
        }
        return $by;
    }

    /**
     * Preview grouping of current form items by supplier based on each material's preferred supplier.
     * @return array<int, array{supplier_id:int,name:string,lines:int,subtotal:float}>
     */
    public function getPoSupplierPreviewProperty(): array
    {
        $preview = [];
        $groups = [];
        foreach (array_values($this->poForm['items']) as $idx => $line) {
            $materialId = $line['material_id'] ?? null;
            if (empty($materialId)) {
                // skip ad-hoc in this flow
                continue;
            }
            /** @var \Lastdino\ProcurementFlow\Models\Material|null $mat */
            $mat = \Lastdino\ProcurementFlow\Models\Material::find((int) $materialId);
            if (! $mat || is_null($mat->preferred_supplier_id)) {
                continue;
            }
            $sid = (int) $mat->preferred_supplier_id;
            if (! isset($groups[$sid])) {
                /** @var \Lastdino\ProcurementFlow\Models\Supplier|null $sup */
                $sup = \Lastdino\ProcurementFlow\Models\Supplier::query()->find($sid);
                $groups[$sid] = [
                    'supplier_id' => $sid,
                    'name' => $sup?->name ?? ('Supplier #'.$sid),
                    'lines' => 0,
                    'subtotal' => 0.0,
                ];
            }
            $qty = (float) ($line['qty_ordered'] ?? 0);
            $price = (float) ($line['price_unit'] ?? 0);
            $groups[$sid]['lines'] += 1;
            $groups[$sid]['subtotal'] += ($qty * $price);
        }
        // normalize
        foreach ($groups as $g) {
            $g['subtotal'] = (float) $g['subtotal'];
            $preview[] = $g;
        }
        return $preview;
    }

    public function savePoFromModal(): void
    {
        // 承認フロー事前チェック（validateの前で止める）
        try {
            $flowIdStr = \Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id');
            $flowId = (int) ($flowIdStr ?? 0);
            if ($flowId <= 0 || ! \Lastdino\ApprovalFlow\Models\ApprovalFlow::query()->whereKey($flowId)->exists()) {
                $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
                return;
            }
        } catch (\Throwable $e) {
            $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
            return;
        }

        $rules = (new \Lastdino\ProcurementFlow\Http\Requests\StorePurchaseOrderRequest())->rules();

        // Normalize items payload to ensure keys existence (especially options)
        $items = array_map(function ($line) {
            return [
                'material_id' => $line['material_id'] ?? null,
                'description' => $line['description'] ?? null,
                'unit_purchase' => $line['unit_purchase'] ?? '',
                'qty_ordered' => $line['qty_ordered'] ?? null,
                'price_unit' => $line['price_unit'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'desired_date' => $line['desired_date'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'note' => $line['note'] ?? null,
                'options' => (array) ($line['options'] ?? []),
            ];
        }, array_values($this->poForm['items']));

        // Validate with "poForm."-prefixed keys so error bag aligns with wire:model / <flux:error name="poForm.*"> in Blade
        $payload = [
            'poForm' => [
                'supplier_id' => $this->poForm['supplier_id'],
                'expected_date' => $this->poForm['expected_date'],
                'delivery_location' => (string) ($this->poForm['delivery_location'] ?? ''),
                'items' => $items,
            ],
        ];
        $prefixedRules = $this->prefixFormRules($rules, 'poForm.');
        $validatedAll = validator($payload, $prefixedRules)->validate();

        $validated = $validatedAll['poForm'];


        // Path A: legacy/single-supplier explicit flow when supplier_id provided
        if (! empty($validated['supplier_id'])) {
            /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $po */
            $po = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $po = \Lastdino\ProcurementFlow\Models\PurchaseOrder::create([
                'supplier_id' => $validated['supplier_id'],
                'status' => 'draft',
                'expected_date' => $validated['expected_date'] ?? null,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                // 納品先：UI指定があれば使用。空ならPDF設定の既定値を保存
                'delivery_location' => (string) ($validated['delivery_location'] ?? (Settings::pdf()['delivery_location'] ?? '')),
                'created_by' => auth()->id() ?: null,
            ]);

            $subtotal = 0.0;
            $tax = 0.0;

            // Resolve tax set once using expected_date if present
            $expectedDate = isset($validated['expected_date']) && $validated['expected_date'] ? \Carbon\Carbon::parse($validated['expected_date']) : null;
            $taxSet = Settings::itemTax($expectedDate);

            // 1) 通常のアイテム行（品目ごとの送料行も直後に自動生成・紐づけ）
            $materialIdsInOrder = [];
            foreach ($validated['items'] as $idx => $line) {
                $materialId = $line['material_id'] ?? null;
                if (! is_null($materialId)) {
                    // Validate material exists (already validated), and optionally fetch if needed later
                    /** @var \Lastdino\ProcurementFlow\Models\Material|null $material */
                    $material = \Lastdino\ProcurementFlow\Models\Material::find($materialId);
                }
                $lineTotal = (float) $line['qty_ordered'] * (float) $line['price_unit'];
                // If tax_rate not provided, derive from material tax_code
                if (array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== '') {
                    $lineTaxRate = (float) $line['tax_rate'];
                } else {
                    /** @var \Lastdino\ProcurementFlow\Models\Material|null $material */
                    // use previously fetched $material when available
                    $lineTaxRate = $this->resolveMaterialTaxRate($material, $taxSet);
                }
                $lineTax = $lineTotal * $lineTaxRate;

            /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrderItem $createdItem */
            $createdItem = \Lastdino\ProcurementFlow\Models\PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'material_id' => $materialId,
                'description' => $line['description'] ?? null,
                'unit_purchase' => $line['unit_purchase'],
                'qty_ordered' => $line['qty_ordered'],
                'price_unit' => $line['price_unit'],
                'tax_rate' => $lineTaxRate,
                'line_total' => $lineTotal,
                'desired_date' => $line['desired_date'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'note' => $line['note'] ?? null,
            ]);

            // Persist selected options into pivot per active group
            // Use original form data for options since they are not part of validated rules
            $selectedOptions = (array) ($this->poForm['items'][$idx]['options'] ?? []); // [group_id => option_id]
            foreach ($selectedOptions as $groupId => $optionId) {
                if (empty($optionId)) {
                    continue;
                }
                // Validate option belongs to group and is active
                $exists = Option::query()
                    ->active()
                    ->where('group_id', (int) $groupId)
                    ->whereKey((int) $optionId)
                    ->exists();
                if (! $exists) {
                    // skip invalid selection
                    continue;
                }

                PurchaseOrderItemOptionValue::query()->updateOrCreate(
                    [
                        'purchase_order_item_id' => (int) $createdItem->getKey(),
                        'group_id' => (int) $groupId,
                    ],
                    [
                        'option_id' => (int) $optionId,
                    ]
                );
            }

            $subtotal += $lineTotal;
            $tax += $lineTax;
            if (! is_null($materialId)) {
                $materialIdsInOrder[(int) $materialId] = true;
            }

            // 品目ごとの送料行を自動生成・紐づけ（B案）
            if (! is_null($materialId) && isset($material) && $material) {
                $separate = (bool) ($material->getAttribute('separate_shipping') ?? false);
                $fee = (float) ($material->getAttribute('shipping_fee_per_order') ?? 0);
                if ($separate && $fee > 0) {
                    $shipping = Settings::shipping();
                    $shippingTaxable = (bool) ($shipping['taxable'] ?? true);
                    $shippingTaxRate = $shippingTaxable ? (float) ($shipping['tax_rate'] ?? 0.10) : 0.0;
                    $desc = '送料（' . (string) ($material->getAttribute('name') ?? $material->getAttribute('sku') ?? '対象資材') . '）';

                    \Lastdino\ProcurementFlow\Models\PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'material_id' => null,
                        'description' => $desc,
                        'unit_purchase' => 'shipping',
                        'qty_ordered' => 1,
                        'price_unit' => $fee,
                        'tax_rate' => $shippingTaxRate,
                        'line_total' => $fee,
                        'desired_date' => null,
                        'expected_date' => null,
                        'shipping_for_item_id' => (int) $createdItem->getKey(),
                    ]);

                    $subtotal += $fee;
                    $tax += ($fee * $shippingTaxRate);
                }
            }
        }

        // 2) まとめての送料生成はB案では不要（各行の直後で生成済み）

        $po->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);

                return $po;
            });

            // 承認フロー登録（設定されたFlow IDで登録）
            try {
                /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $poModel */
                $poModel = $po->fresh();
                $authorId = (int) (auth()->id() ?? $poModel->created_by ?? 0);
                $link = null;
                if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
                    $link = route('procurement.purchase-orders.show', ['po' => $poModel->id]);
                } elseif (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
                    $link = route('purchase-orders.show', ['purchase_order' => $poModel->id]);
                }
                $flowId = (int) ((\Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id')) ?? 0);
                \Log::debug('Registering approval flow for PO ID: '.$poModel->id.' with author ID: '.$authorId.' and flow ID: '.$flowId);
                if ($authorId > 0 && $flowId > 0) {
                    $poModel->registerApprovalFlowTask($flowId, $authorId, null, null, $link);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to register approval flow for PO: '.$e->getMessage(), ['po_id' => $po->id]);
            }

            $this->showPoModal = false;
            if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
                $this->redirectRoute('procurement.purchase-orders.show', ['po' => $po->id]);
            } elseif (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
                $this->redirectRoute('purchase-orders.show', ['purchase_order' => $po->id]);
            }
            return;
        }

        // Path B: supplier-less flow → split by each material's preferred supplier
        $items = array_values($validated['items']);
        $groups = [];
        foreach ($items as $idx => $line) {
            $matId = $line['material_id'] ?? null;
            if (is_null($matId)) {
                // Should be prevented by validator for this flow
                $this->addError('poForm.items.'.$idx.'.material_id', 'アドホック行はこのフローでは使用できません。');
                return;
            }
            /** @var \Lastdino\ProcurementFlow\Models\Material|null $mat */
            $mat = \Lastdino\ProcurementFlow\Models\Material::find((int) $matId);
            if (! $mat || is_null($mat->preferred_supplier_id)) {
                $this->addError('poForm.items.'.$idx.'.material_id', 'この資材に紐づくサプライヤーが未設定のため、自動発注できません。');
                return;
            }
            $sid = (int) $mat->preferred_supplier_id;
            $groups[$sid][] = ['idx' => $idx, 'line' => $line];
        }

        $created = [];
        foreach ($groups as $sid => $lines) {
            $po = \Illuminate\Support\Facades\DB::transaction(function () use ($sid, $lines, $validated) {
                $po = \Lastdino\ProcurementFlow\Models\PurchaseOrder::create([
                    'supplier_id' => (int) $sid,
                    'status' => 'draft',
                    'expected_date' => $validated['expected_date'] ?? null,
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    // 納品先：UI指定があれば使用。空ならPDF設定の既定値を保存
                    'delivery_location' => (string) ($validated['delivery_location'] ?? (Settings::pdf()['delivery_location'] ?? '')),
                    'created_by' => auth()->id() ?: null,
                ]);

                $subtotal = 0.0;
                $tax = 0.0;
                $expectedDate = isset($validated['expected_date']) && $validated['expected_date'] ? \Carbon\Carbon::parse($validated['expected_date']) : null;
                $taxSet = $this->resolveCurrentItemTaxSet($expectedDate);
                $materialIdsInOrder = [];

                foreach ($lines as $entry) {
                    $idx = (int) $entry['idx'];
                    $line = $entry['line'];
                    $materialId = $line['material_id'] ?? null;
                    $material = null;
                    if (! is_null($materialId)) {
                        $material = \Lastdino\ProcurementFlow\Models\Material::find((int) $materialId);
                    }
                    $lineTotal = (float) $line['qty_ordered'] * (float) $line['price_unit'];
                    $lineTaxRate = null;
                    if (array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== '') {
                        $lineTaxRate = (float) $line['tax_rate'];
                    } else {
                        $lineTaxRate = $this->resolveMaterialTaxRate($material, $taxSet);
                    }
                    $lineTax = $lineTotal * $lineTaxRate;

                    /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrderItem $createdItem */
                    $createdItem = \Lastdino\ProcurementFlow\Models\PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'material_id' => $materialId,
                        'description' => $line['description'] ?? null,
                        'unit_purchase' => $line['unit_purchase'],
                        'qty_ordered' => $line['qty_ordered'],
                        'price_unit' => $line['price_unit'],
                        'tax_rate' => $lineTaxRate,
                        'line_total' => $lineTotal,
                        'desired_date' => $line['desired_date'] ?? null,
                        'expected_date' => $line['expected_date'] ?? null,
                    ]);

                    // Persist options selected in UI for this line
                    $selectedOptions = (array) ($this->poForm['items'][$idx]['options'] ?? []);
                    foreach ($selectedOptions as $groupId => $optionId) {
                        if (empty($optionId)) { continue; }
                        $exists = Option::query()->active()->where('group_id', (int) $groupId)->whereKey((int) $optionId)->exists();
                        if (! $exists) { continue; }
                        PurchaseOrderItemOptionValue::query()->updateOrCreate(
                            [
                                'purchase_order_item_id' => (int) $createdItem->getKey(),
                                'group_id' => (int) $groupId,
                            ],
                            [
                                'option_id' => (int) $optionId,
                            ]
                        );
                    }

                    $subtotal += $lineTotal;
                    $tax += $lineTax;
                    if (! is_null($materialId)) {
                        $materialIdsInOrder[(int) $materialId] = true;
                    }

                    // 品目ごとの送料行を自動生成・紐づけ（B案）
                    if (! is_null($materialId) && isset($material) && $material) {
                        $separate = (bool) ($material->getAttribute('separate_shipping') ?? false);
                        $fee = (float) ($material->getAttribute('shipping_fee_per_order') ?? 0);
                        if ($separate && $fee > 0) {
                            $shippingTaxable = (bool) config('procurement-flow.shipping.taxable', true);
                            $shippingTaxRate = $shippingTaxable ? (float) config('procurement-flow.shipping.tax_rate', 0.10) : 0.0;
                            $desc = '送料（' . (string) ($material->getAttribute('name') ?? $material->getAttribute('sku') ?? '対象資材') . '）';
                            \Lastdino\ProcurementFlow\Models\PurchaseOrderItem::create([
                                'purchase_order_id' => $po->id,
                                'material_id' => null,
                                'description' => $desc,
                                'unit_purchase' => 'shipping',
                                'qty_ordered' => 1,
                                'price_unit' => $fee,
                                'tax_rate' => $shippingTaxRate,
                                'line_total' => $fee,
                                'desired_date' => null,
                                'expected_date' => null,
                                'shipping_for_item_id' => (int) $createdItem->getKey(),
                            ]);
                            $subtotal += $fee;
                            $tax += ($fee * $shippingTaxRate);
                        }
                    }
                }

                // まとめての送料生成はB案では不要（各行の直後で生成済み）

                $po->update([
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $subtotal + $tax,
                ]);

                return $po;
            });

            // Register approval task per PO
            try {
                $poModel = $po->fresh();
                $authorId = (int) (auth()->id() ?? $poModel->created_by ?? 0);
                $link = null;
                if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
                    $link = route('procurement.purchase-orders.show', ['po' => $poModel->id]);
                } elseif (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
                    $link = route('purchase-orders.show', ['purchase_order' => $poModel->id]);
                }
                $flowId = (int) ((\Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id')) ?? 0);
                if ($authorId > 0 && $flowId > 0) {
                    $poModel->registerApprovalFlowTask($flowId, $authorId, null, null, $link);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to register approval flow for PO: '.$e->getMessage(), ['po_id' => $po->id]);
            }

            $created[] = $po->id;
        }

        $this->showPoModal = false;
        $count = count($created);
        $this->dispatch('toast', type: 'success', message: $count.'件の発注書を作成しました');
        // Stay on index; optionally we could redirect when only one created.
    }

    // When supplier changes, prefill auto_send flag from supplier default
    public function updatedPoFormSupplierId($value): void
    {
        $supplierId = (int) ($value ?? 0);
        if ($supplierId <= 0) {
            return;
        }

        /** @var \Lastdino\ProcurementFlow\Models\Supplier|null $sup */
        $sup = \Lastdino\ProcurementFlow\Models\Supplier::query()->find($supplierId);
        if ($sup) {
            // UI では選択しない方針のため、何もしない
        }
    }

    // Ad-hoc order helpers
    public function openAdhocPo(): void
    {
        $this->resetAdhocForm();
        // Prefill initial ad-hoc line tax_rate from config default (schedule-aware)
        $expectedDate = isset($this->adhocForm['expected_date']) && $this->adhocForm['expected_date'] ? \Carbon\Carbon::parse($this->adhocForm['expected_date']) : null;
        $taxSet = $this->resolveCurrentItemTaxSet($expectedDate);
        if (isset($this->adhocForm['items'][0]) && (is_null($this->adhocForm['items'][0]['tax_rate']) || $this->adhocForm['items'][0]['tax_rate'] === '')) {
            $this->adhocForm['items'][0]['tax_rate'] = (float) ($taxSet['default_rate'] ?? 0.10);
        }
        $this->showAdhocPoModal = true;
    }

    public function closeAdhocPo(): void
    {
        $this->showAdhocPoModal = false;
    }

    public function addAdhocItem(): void
    {
        // Prefill default item tax for ad-hoc lines based on expected_date and config
        $expectedDate = isset($this->adhocForm['expected_date']) && $this->adhocForm['expected_date'] ? \Carbon\Carbon::parse($this->adhocForm['expected_date']) : null;
        $taxSet = $this->resolveCurrentItemTaxSet($expectedDate);
        $defaultRate = (float) ($taxSet['default_rate'] ?? 0.10);
        $this->adhocForm['items'][] = ['description' => null, 'manufacturer' => null, 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => $defaultRate, 'tax_locked' => false, 'desired_date' => null, 'expected_date' => null, 'note' => null, 'options' => []];
    }

    public function removeAdhocItem(int $index): void
    {
        unset($this->adhocForm['items'][$index]);
        $this->adhocForm['items'] = array_values($this->adhocForm['items']);
    }

    public function saveAdhocPoFromModal(): void
    {
        // 承認フロー事前チェック（validateの前で止める）
        try {
            $flowIdStr = \Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id');
            $flowId = (int) ($flowIdStr ?? 0);
            if ($flowId <= 0 || ! \Lastdino\ApprovalFlow\Models\ApprovalFlow::query()->whereKey($flowId)->exists()) {
                $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
                return;
            }
        } catch (\Throwable $e) {
            $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
            return;
        }

        // Reuse same rules; items will have material_id null and require description
        $rules = (new \Lastdino\ProcurementFlow\Http\Requests\StorePurchaseOrderRequest())->rules();

        // Map adhoc items to expected structure (material_id => null)
        $items = array_map(function ($line) {
            return [
                'material_id' => null,
                'description' => $line['description'] ?? null,
                'manufacturer' => $line['manufacturer'] ?? null,
                'unit_purchase' => $line['unit_purchase'] ?? '',
                'qty_ordered' => $line['qty_ordered'] ?? null,
                'price_unit' => $line['price_unit'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'desired_date' => $line['desired_date'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'note' => $line['note'] ?? null,
                // pass through options for validation (required per active group)
                'options' => (array) ($line['options'] ?? []),
            ];
        }, array_values($this->adhocForm['items']));

        // Validate with "adhocForm."-prefixed keys so error bag aligns with wire:model / <flux:error name="adhocForm.*"> (if used)
        $payload = [
            'adhocForm' => [
                'supplier_id' => $this->adhocForm['supplier_id'],
                'expected_date' => $this->adhocForm['expected_date'],
                'delivery_location' => (string) ($this->adhocForm['delivery_location'] ?? ''),
                'items' => $items,
            ],
        ];
        $prefixedRules = $this->prefixFormRules($rules, 'adhocForm.');
        $validatedAll = validator($payload, $prefixedRules)->validate();
        $validated = $validatedAll['adhocForm'];

        // アドホック発注フローでは supplier_id は必須（FormRequest の withValidator は使っていないため、ここで強制）
        if (empty($validated['supplier_id'])) {
            $this->addError('adhocForm.supplier_id', 'アドホック行が含まれるため、サプライヤーの選択が必要です。');
            return;
        }

        /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $po */
        $po = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $po = \Lastdino\ProcurementFlow\Models\PurchaseOrder::create([
                'supplier_id' => $validated['supplier_id'],
                'status' => 'draft',
                'expected_date' => $validated['expected_date'] ?? null,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                // 納品先：UI指定があれば使用。空ならPDF設定の既定値を保存
                'delivery_location' => (string) ($validated['delivery_location'] ?? (Settings::pdf()['delivery_location'] ?? '')),
                'created_by' => auth()->id() ?: null,
            ]);

            $subtotal = 0.0;
            $tax = 0.0;

            $expectedDate = isset($validated['expected_date']) && $validated['expected_date'] ? \Carbon\Carbon::parse($validated['expected_date']) : null;
            $taxSet = $this->resolveCurrentItemTaxSet($expectedDate);

            foreach ($validated['items'] as $idx => $line) {
                $lineTotal = (float) $line['qty_ordered'] * (float) $line['price_unit'];
                // For ad-hoc items (no material), use default rate when not provided
                $lineTaxRate = null;
                if (array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== '') {
                    $lineTaxRate = (float) $line['tax_rate'];
                } else {
                    $lineTaxRate = (float) ($taxSet['default_rate'] ?? 0.10);
                }
                $lineTax = $lineTotal * $lineTaxRate;

                /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrderItem $createdItem */
                $createdItem = \Lastdino\ProcurementFlow\Models\PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'material_id' => null, // ad-hoc
                    'description' => $line['description'] ?? null,
                    'manufacturer' => $line['manufacturer'] ?? null,
                    'unit_purchase' => $line['unit_purchase'],
                    'qty_ordered' => $line['qty_ordered'],
                    'price_unit' => $line['price_unit'],
                    'tax_rate' => $lineTaxRate,
                    'line_total' => $lineTotal,
                    'desired_date' => $line['desired_date'] ?? null,
                    'expected_date' => $line['expected_date'] ?? null,
                    'note' => $line['note'] ?? null,
                ]);

                // Persist selected options into pivot per active group
                $selectedOptions = (array) ($this->adhocForm['items'][$idx]['options'] ?? []); // [group_id => option_id]
                foreach ($selectedOptions as $groupId => $optionId) {
                    if (empty($optionId)) {
                        continue;
                    }
                    // Validate option belongs to group and is active
                    $exists = Option::query()
                        ->active()
                        ->where('group_id', (int) $groupId)
                        ->whereKey((int) $optionId)
                        ->exists();
                    if (! $exists) {
                        continue;
                    }

                    PurchaseOrderItemOptionValue::query()->updateOrCreate(
                        [
                            'purchase_order_item_id' => (int) $createdItem->getKey(),
                            'group_id' => (int) $groupId,
                        ],
                        [
                            'option_id' => (int) $optionId,
                        ]
                    );
                }

                $subtotal += $lineTotal;
                $tax += $lineTax;
            }

            $po->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax,
            ]);

            return $po;
        });

        // 承認フロー登録（設定されたFlow IDで登録）
        try {
            /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $poModel */
            $poModel = $po->fresh();
            $authorId = (int) (auth()->id() ?? $poModel->created_by ?? 0);
            $link = null;
            if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
                $link = route('procurement.purchase-orders.show', ['po' => $poModel->id]);
            } elseif (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
                $link = route('purchase-orders.show', ['purchase_order' => $poModel->id]);
            }
            $flowId = (int) ((\Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id')) ?? 0);
            \Log::debug('Registering approval flow for Adhoc PO ID: '.$poModel->id.' with author ID: '.$authorId.' and flow ID: '.$flowId);
            if ($authorId > 0 && $flowId > 0) {
                $poModel->registerApprovalFlowTask($flowId, $authorId, null, null, $link);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to register approval flow for Adhoc PO: '.$e->getMessage(), ['po_id' => $po->id]);
        }

        $this->showAdhocPoModal = false;
        if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
            $this->redirectRoute('procurement.purchase-orders.show', ['po' => $po->id]);
        } elseif (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
            $this->redirectRoute('purchase-orders.show', ['purchase_order' => $po->id]);
        }
    }

    /**
     * 現在（または予定日）に有効な商品税セットを返す。
     * @return array{default_rate: float, rates: array<string,float>}
     */
    protected function resolveCurrentItemTaxSet(?\Carbon\Carbon $at): array
    {
        $cfg = (array) config('procurement-flow.item_tax', []);
        $default = (float) ($cfg['default_rate'] ?? 0.10);
        $rates = (array) ($cfg['rates'] ?? []);
        $schedule = (array) ($cfg['schedule'] ?? []);

        if ($at && ! empty($schedule)) {
            foreach ($schedule as $entry) {
                $from = $entry['effective_from'] ?? null;
                if ($from && $at->greaterThanOrEqualTo(\Carbon\Carbon::parse($from))) {
                    $default = (float) ($entry['default_rate'] ?? $default);
                    $rates = array_merge($rates, (array) ($entry['rates'] ?? []));
                }
            }
        }

        return ['default_rate' => $default, 'rates' => $rates];
    }

    /**
     * 資材の tax_code に応じて税率を返す。該当コードが無い場合はデフォルト。
     */
    protected function resolveMaterialTaxRate(?\Lastdino\ProcurementFlow\Models\Material $material, array $taxSet): float
    {
        $code = $material ? (string) ($material->getAttribute('tax_code') ?? 'standard') : 'standard';
        $default = (float) ($taxSet['default_rate'] ?? 0.10);
        $rates = (array) ($taxSet['rates'] ?? []);
        return match ($code) {
            'reduced' => (float) ($rates['reduced'] ?? $default),
            default => $default,
        };
    }

    public function onMaterialChanged(int $index, $materialId): void
    {
        $materialId = (int) $materialId;
        if (! isset($this->poForm['items'][$index])) {
            return;
        }

        if ($materialId === 0) {
            // Reset unit when material cleared
            $this->poForm['items'][$index]['unit_purchase'] = '';
            return;
        }

        /** @var \Lastdino\ProcurementFlow\Models\Material|null $material */
        $material = \Lastdino\ProcurementFlow\Models\Material::find($materialId);
        if ($material) {
            // In supplier-less flow, disallow materials without preferred supplier
            $preferred = $material->preferred_supplier_id;
            if (is_null($preferred) && empty($this->poForm['supplier_id'])) {
                $this->poForm['items'][$index]['material_id'] = null;
                $this->poForm['items'][$index]['unit_purchase'] = '';
                $this->addError("poForm.items.$index.material_id", 'この資材に紐づくサプライヤーが未設定です。資材に指定サプライヤーを設定してください。');
                return;
            }
            // If supplier is not chosen yet and material has preferred supplier, auto-assign it
            if (empty($this->poForm['supplier_id']) && ! is_null($preferred)) {
                $this->poForm['supplier_id'] = (int) $preferred;
            }
            $defaultUnit = $material->unit_purchase_default ?: $material->unit_stock;
            $this->poForm['items'][$index]['unit_purchase'] = (string) $defaultUnit;
            // Also default unit price from material master if present
            if (! is_null($material->unit_price)) {
                $this->poForm['items'][$index]['price_unit'] = (float) $material->unit_price;
            }
            // Auto-fill tax rate if not set by user
            $current = $this->poForm['items'][$index]['tax_rate'] ?? null;
            if ($current === null || $current === '') {
                $exp = isset($this->poForm['expected_date']) && $this->poForm['expected_date'] ? \Carbon\Carbon::parse($this->poForm['expected_date']) : null;
                $taxSet = $this->resolveCurrentItemTaxSet($exp);
                $this->poForm['items'][$index]['tax_rate'] = $this->resolveMaterialTaxRate($material, $taxSet);
                $this->poForm['items'][$index]['tax_locked'] = false; // mark as auto-applied
            }
        }
    }

    // When expected_date changes, re-evaluate auto-applied (null) tax rates
    public function updatedPoFormExpectedDate($value): void
    {
        $exp = !empty($value) ? \Carbon\Carbon::parse($value) : null;
        $taxSet = $this->resolveCurrentItemTaxSet($exp);
        foreach ($this->poForm['items'] as $i => $line) {
            $current = $line['tax_rate'] ?? null;
            $locked = (bool) ($line['tax_locked'] ?? false);
            if (! $locked) {
                $materialId = $line['material_id'] ?? null;
                if (! is_null($materialId)) {
                    /** @var \Lastdino\ProcurementFlow\Models\Material|null $material */
                    $material = \Lastdino\ProcurementFlow\Models\Material::find((int) $materialId);
                    $this->poForm['items'][$i]['tax_rate'] = $this->resolveMaterialTaxRate($material, $taxSet);
                } else {
                    // Ad-hoc default
                    $this->poForm['items'][$i]['tax_rate'] = (float) ($taxSet['default_rate'] ?? 0.10);
                }
            }
        }
    }

    // Detect manual override of tax_rate and lock the line against auto-updates
    public function updatedPoForm($value, $name): void
    {
        // $name example: 'poForm.items.1.tax_rate'
        if (is_string($name) && str_ends_with($name, '.tax_rate')) {
            // extract index
            $parts = explode('.', $name);
            $idxKey = array_search('items', $parts, true);
            if ($idxKey !== false && isset($parts[$idxKey + 1])) {
                $i = (int) $parts[$idxKey + 1];
                if (isset($this->poForm['items'][$i])) {
                    $this->poForm['items'][$i]['tax_locked'] = true;
                }
            }
        }
    }

    // Detail modal helpers removed — detail is handled on the Show page

    /**
     * Cancel a draft Purchase Order. Only allowed when current status is Draft.
     */
    public function cancelPo(int $id): void
    {
        /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder|null $po */
        $po = PurchaseOrder::query()->find($id);
        if (! $po) {
            $this->dispatch('toast', type: 'error', message: 'Purchase order not found');
            return;
        }

        // Normalize status value
        $statusValue = is_string($po->status) ? $po->status : ($po->status->value ?? '');
        if ($statusValue !== PurchaseOrderStatus::Draft->value) {
            $this->dispatch('toast', type: 'error', message: __('procflow::po.detail.cancel_not_allowed'));
            return;
        }

        $po->status = PurchaseOrderStatus::Canceled;
        $po->save();

        // Detail modal removed; no in-place refresh needed

        // Refresh the list by resetting the page to trigger re-query
        $this->resetPage();

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

        // Update effective line_total for non-shipping lines
        $effectiveQty = max($ordered - (float) $item->qty_canceled, 0.0);
        $item->line_total = $effectiveQty * (float) ($item->price_unit ?? 0);
        $item->save();

        // Recompute PO totals
        $po = $po->refresh()->loadMissing(['items.receivingItems']);
        $this->recomputeTotals($po);

        // If no remaining effective quantity across all non-shipping items, update PO status as requested:
        // - If there is at least some received quantity on any item, mark as Closed
        // - Otherwise (no receipts at all), mark as Canceled
        $effectiveRemaining = 0.0;
        $hasAnyReceipt = false;
        foreach ($po->items as $lit) {
            /** @var PurchaseOrderItem $lit */
            if (($lit->unit_purchase ?? '') === 'shipping') {
                continue;
            }
            $ordered = (float) ($lit->qty_ordered ?? 0);
            $canceledQty = (float) ($lit->qty_canceled ?? 0);
            $effectiveRemaining += max($ordered - $canceledQty, 0.0);
            if (! $hasAnyReceipt && $lit->receivingItems()->exists()) {
                $hasAnyReceipt = true;
            }
        }

        if ($effectiveRemaining <= 1e-9) {
            $po->status = $hasAnyReceipt ? PurchaseOrderStatus::Closed : PurchaseOrderStatus::Canceled;
            $po->save();
        }

        // Detail modal removed; no in-place refresh needed

        $this->dispatch('toast', type: 'success', message: __('procflow::po.detail.item_canceled_toast'));
    }

    protected function recomputeTotals(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        $subtotal = 0.0; $tax = 0.0;
        foreach ($po->items as $it) {
            /** @var PurchaseOrderItem $it */
            // Shipping: keep as-is
            if ($it->unit_purchase === 'shipping') {
                $lt = (float) ($it->line_total ?? 0);
                $subtotal += $lt;
                $tax += $lt * (float) ($it->tax_rate ?? 0);
                continue;
            }
            $qty = max(((float) ($it->qty_ordered ?? 0)) - ((float) ($it->qty_canceled ?? 0)), 0.0);
            $line = $qty * (float) ($it->price_unit ?? 0);
            $subtotal += $line;
            $tax += $line * (float) ($it->tax_rate ?? 0);
        }
        $po->subtotal = $subtotal;
        $po->tax = $tax;
        $po->total = $subtotal + $tax;
        $po->save();
    }

    // Item expected date editing moved to Show component

    protected function resetPoForm(): void
    {
        $this->poForm = [
            'supplier_id' => null,
            'expected_date' => null,
            // 発注単位の納品先（未指定時はPDF設定の既定値を初期値として表示）
            'delivery_location' => (string) (Settings::pdf()['delivery_location'] ?? ''),
            'items' => [
                ['material_id' => '', 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => null, 'tax_locked' => false, 'description' => null, 'desired_date' => null, 'expected_date' => null, 'note' => null, 'options' => []],
            ],
        ];
    }

    protected function resetAdhocForm(): void
    {
        $this->adhocForm = [
            'supplier_id' => null,
            'expected_date' => null,
            // 発注単位の納品先（未指定時はPDF設定の既定値を初期値として表示）
            'delivery_location' => (string) (Settings::pdf()['delivery_location'] ?? ''),
            'items' => [
                ['description' => null, 'unit_purchase' => '', 'qty_ordered' => null, 'price_unit' => null, 'tax_rate' => null, 'tax_locked' => false, 'desired_date' => null, 'expected_date' => null, 'note' => null, 'options' => []],
            ],
        ];
    }

    /**
     * 与えられたバリデーションルール配列のキー（フィールド名）にフォーム配列名のプレフィックスを付与します。
     *
     * 例: [ 'items.*.qty_ordered' => 'required' ] に対して、prefix が 'poForm.' の場合、
     *     [ 'poForm.items.*.qty_ordered' => 'required' ] を返します。
     */
    protected function prefixFormRules(array $rules, string $prefix): array
    {
        $prefixed = [];

        foreach ($rules as $key => $rule) {
            $newKey = $prefix . $key;

            // Prefix field references inside dependent validation rules (strings)
            // Only for rules where other field names are referenced (required_with/without and their variants)
            $newRule = $rule;

            $dependentRuleNames = [
                'required_with', 'required_with_all', 'required_with_any',
                'required_without', 'required_without_all', 'required_without_any',
            ];

            $prefixFieldList = function (string $list) use ($prefix): string {
                $parts = array_map('trim', explode(',', $list));
                $parts = array_map(function ($field) use ($prefix) {
                    // Avoid double-prefixing
                    if ($field === '') {
                        return $field;
                    }
                    return str_starts_with($field, $prefix) ? $field : $prefix . $field;
                }, $parts);
                return implode(',', $parts);
            };

            $transformStringRule = function (string $ruleStr) use ($dependentRuleNames, $prefixFieldList): string {
                $segments = explode('|', $ruleStr);
                foreach ($segments as &$seg) {
                    if (strpos($seg, ':') === false) {
                        continue;
                    }
                    [$name, $value] = explode(':', $seg, 2);
                    if (in_array($name, $dependentRuleNames, true)) {
                        $seg = $name . ':' . $prefixFieldList($value);
                    }
                }
                unset($seg);
                return implode('|', $segments);
            };

            if (is_string($newRule)) {
                $newRule = $transformStringRule($newRule);
            } elseif (is_array($newRule)) {
                $newRule = array_map(function ($r) use ($transformStringRule) {
                    if (is_string($r)) {
                        return $transformStringRule($r);
                    }
                    // Leave Rule objects and other instances as-is
                    return $r;
                }, $newRule);
            }

            $prefixed[$newKey] = $newRule;
        }

        return $prefixed;
    }
}
