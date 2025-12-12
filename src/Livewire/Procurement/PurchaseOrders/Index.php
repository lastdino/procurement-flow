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

use Lastdino\ProcurementFlow\Support\Settings;
use Lastdino\ProcurementFlow\Models\Receiving;
use Lastdino\ProcurementFlow\Models\ReceivingItem;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;

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
        if (! $this->ensureApprovalFlowConfigured()) {
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
        $validated = $this->validatePurchaseOrderPayload('poForm', $payload, $rules);


        // Path A: legacy/single-supplier explicit flow when supplier_id provided
        if (! empty($validated['supplier_id'])) {
            // Build input for shared factory
            [$optionService, $factory, $approval] = $this->services();

            $lines = [];
            foreach ($validated['items'] as $idx => $line) {
                $lines[] = [
                    'material_id' => (isset($line['material_id']) && $line['material_id'] !== '' && $line['material_id'] !== null)
                        ? (int) $line['material_id']
                        : null,
                    'description' => $line['description'] ?? null,
                    'unit_purchase' => (string) $line['unit_purchase'],
                    'qty_ordered' => (float) $line['qty_ordered'],
                    'price_unit' => (float) $line['price_unit'],
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'desired_date' => $line['desired_date'] ?? null,
                    'expected_date' => $line['expected_date'] ?? null,
                    'note' => $line['note'] ?? null,
                    // Normalize & validate options with shared service
                    'options' => $optionService->normalizeAndValidate((array) ($this->poForm['items'][$idx]['options'] ?? [])),
                ];
            }

            $poInput = [
                'supplier_id' => (int) $validated['supplier_id'],
                'expected_date' => $validated['expected_date'] ?? null,
                'delivery_location' => (string) ($validated['delivery_location'] ?? ''),
                'items' => $lines,
            ];

            /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $po */
            $po = $factory->create($poInput, true);
            // 承認フロー登録（設定されたFlow IDで登録）
            $approval->registerForPo($po);

            $this->showPoModal = false;
            $this->redirectToPoShow($po);
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

        $optionService = app(\Lastdino\ProcurementFlow\Services\OptionSelectionService::class);
        $factory = app(\Lastdino\ProcurementFlow\Services\PurchaseOrderFactory::class);
        $approval = app(\Lastdino\ProcurementFlow\Services\ApprovalFlowRegistrar::class);

        $created = [];
        foreach ($groups as $sid => $lines) {
            $compiledLines = [];
            foreach ($lines as $entry) {
                $idx = (int) $entry['idx'];
                $line = $entry['line'];
                $compiledLines[] = [
                    'material_id' => $line['material_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'unit_purchase' => (string) $line['unit_purchase'],
                    'qty_ordered' => (float) $line['qty_ordered'],
                    'price_unit' => (float) $line['price_unit'],
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'desired_date' => $line['desired_date'] ?? null,
                    'expected_date' => $line['expected_date'] ?? null,
                    'note' => $line['note'] ?? null,
                    'options' => $optionService->normalizeAndValidate((array) ($this->poForm['items'][$idx]['options'] ?? [])),
                ];
            }

            $poInput = [
                'supplier_id' => (int) $sid,
                'expected_date' => $validated['expected_date'] ?? null,
                'delivery_location' => (string) ($validated['delivery_location'] ?? ''),
                'items' => $compiledLines,
            ];

            /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $po */
            $po = $factory->create($poInput, true);
            $approval->registerForPo($po);
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
        if (! $this->ensureApprovalFlowConfigured()) {
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

        // Validate with "adhocForm."-prefixed keys so error bag aligns with wire:model (error bag name should match your Blade bindings)
        $payload = [
            'adhocForm' => [
                'supplier_id' => $this->adhocForm['supplier_id'],
                'expected_date' => $this->adhocForm['expected_date'],
                'delivery_location' => (string) ($this->adhocForm['delivery_location'] ?? ''),
                'items' => $items,
            ],
        ];
        $validated = $this->validatePurchaseOrderPayload('adhocForm', $payload, $rules);

        // アドホック発注フローでは supplier_id は必須（FormRequest の withValidator は使っていないため、ここで強制）
        if (empty($validated['supplier_id'])) {
            $this->addError('adhocForm.supplier_id', 'アドホック行が含まれるため、サプライヤーの選択が必要です。');
            return;
        }

        // Build lines and create via shared factory/services
        [$optionService, $factory, $approval] = $this->services();

        $lines = [];
        foreach ($validated['items'] as $idx => $line) {
            $lines[] = [
                'material_id' => null,
                'description' => $line['description'] ?? null,
                'manufacturer' => $line['manufacturer'] ?? null,
                'unit_purchase' => (string) $line['unit_purchase'],
                'qty_ordered' => (float) $line['qty_ordered'],
                'price_unit' => (float) $line['price_unit'],
                'tax_rate' => $line['tax_rate'] ?? null, // 指定があれば優先、なければ Factory が既定税率を解決
                'desired_date' => $line['desired_date'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'note' => $line['note'] ?? null,
                'options' => $optionService->normalizeAndValidate((array) ($this->adhocForm['items'][$idx]['options'] ?? [])),
            ];
        }

        $poInput = [
            'supplier_id' => (int) $validated['supplier_id'],
            'expected_date' => $validated['expected_date'] ?? null,
            'delivery_location' => (string) ($validated['delivery_location'] ?? ''),
            'items' => $lines,
        ];

        /** @var \Lastdino\ProcurementFlow\Models\PurchaseOrder $po */
        $po = $factory->create($poInput, true);
        $approval->registerForPo($po);

        $this->showAdhocPoModal = false;
        $this->redirectToPoShow($po);
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

    /**
     * 共通: 承認フローの事前チェック。未設定ならエラーを積んで false を返す。
     */
    protected function ensureApprovalFlowConfigured(): bool
    {
        try {
            $flowIdStr = \Lastdino\ProcurementFlow\Models\AppSetting::get('approval_flow.purchase_order_flow_id');
            $flowId = (int) ($flowIdStr ?? 0);
            if ($flowId <= 0 || ! \Lastdino\ApprovalFlow\Models\ApprovalFlow::query()->whereKey($flowId)->exists()) {
                $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->addError('approval_flow', '承認フローが未設定のため発注できません。管理者に連絡してください。');
            return false;
        }
    }

    /**
     * 共通: フォームごとのプレフィックスでバリデーションを実行して該当フォーム配下の配列を返す。
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    protected function validatePurchaseOrderPayload(string $formKey, array $payload, array $rules): array
    {
        $prefixedRules = $this->prefixFormRules($rules, $formKey . '.');
        $validatedAll = validator($payload, $prefixedRules)->validate();
        return $validatedAll[$formKey] ?? [];
    }

    /**
     * 共通: よく使うサービスの取得。
     * @return array{0:\Lastdino\ProcurementFlow\Services\OptionSelectionService,1:\Lastdino\ProcurementFlow\Services\PurchaseOrderFactory,2:\Lastdino\ProcurementFlow\Services\ApprovalFlowRegistrar}
     */
    protected function services(): array
    {
        $optionService = app(\Lastdino\ProcurementFlow\Services\OptionSelectionService::class);
        $factory = app(\Lastdino\ProcurementFlow\Services\PurchaseOrderFactory::class);
        $approval = app(\Lastdino\ProcurementFlow\Services\ApprovalFlowRegistrar::class);
        return [$optionService, $factory, $approval];
    }

    /**
     * 共通: 作成した PO の詳細へ遷移（ルートが無い場合は何もしない）
     */
    protected function redirectToPoShow(\Lastdino\ProcurementFlow\Models\PurchaseOrder $po): void
    {
        if (\Illuminate\Support\Facades\Route::has('procurement.purchase-orders.show')) {
            $this->redirectRoute('procurement.purchase-orders.show', ['po' => $po->id]);
            return;
        }
        if (\Illuminate\Support\Facades\Route::has('purchase-orders.show')) {
            $this->redirectRoute('purchase-orders.show', ['purchase_order' => $po->id]);
        }
    }
}
