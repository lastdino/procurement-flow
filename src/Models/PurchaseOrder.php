<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lastdino\ProcurementFlow\Enums\PurchaseOrderStatus;
use Lastdino\ProcurementFlow\Casts\PurchaseOrderStatusCast;
use Lastdino\ProcurementFlow\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Lastdino\ProcurementFlow\Mail\PurchaseOrderIssuedMail;
use Lastdino\ProcurementFlow\Services\PoNumberGenerator;
use Lastdino\ApprovalFlow\Traits\HasApprovalFlow;

class PurchaseOrder extends Model
{
    use HasApprovalFlow;

    protected $fillable = [
        'po_number','supplier_id','status','issue_date','expected_date','subtotal','tax','total',
        'shipping_total','shipping_tax_total',
        'invoice_number','delivery_note_number','notes','created_by',
        // 発注ごとの納品先
        'delivery_location',
        // UI からの個別指定は廃止。サプライヤー設定に基づき自動送信する。
        'auto_send_to_supplier',
    ];

    public function getTable()
    {
        return Tables::name('purchase_orders');
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatusCast::class,
            'issue_date' => 'datetime',
            'expected_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'shipping_tax_total' => 'decimal:2',
            // 互換性のためにキャストは残すが、送信可否の判定には使用しない。
            'auto_send_to_supplier' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }
    public function receivings(): HasMany { return $this->hasMany(Receiving::class); }

    /**
     * 承認完了時の処理：PO発行し、必要であればサプライヤーへメール送信
     */
    public function onApproved(): void
    {
        // 発行処理（Draftのみ）
        if ($this->status === PurchaseOrderStatus::Draft) {
            // 発番・Issued化
            $numbers = app(PoNumberGenerator::class);
            $this->po_number = $this->po_number ?: $numbers->generate(CarbonImmutable::now());
            $this->status = PurchaseOrderStatus::Issued;
            $this->issue_date = CarbonImmutable::now();
            // 送料は作成時にアイテムとして追加され、各合計に反映済みとする
            $this->shipping_total = 0;
            $this->shipping_tax_total = 0;
            $this->save();
        }

        // 自動送信可否はサプライヤーの設定で決定する
        /** @var Supplier|null $supplier */
        $supplier = $this->supplier;
        $shouldAutoSend = (bool) ($supplier?->getAttribute('auto_send_po') ?? false);
        if ($shouldAutoSend) {
            $to = $supplier?->email;
            if (! empty($to)) {
                $mailable = new PurchaseOrderIssuedMail($this->fresh(['supplier','items']));

                // CCはカンマ区切りを配列に正規化
                $ccs = [];
                if (! empty($supplier?->email_cc)) {
                    $ccs = array_values(array_filter(array_map(function ($v) {
                        return trim((string) $v);
                    }, explode(',', (string) $supplier->email_cc)), function ($v) {
                        return $v !== '';
                    }));
                }

                $pending = Mail::to($to);
                if (! empty($ccs)) {
                    $pending = $pending->cc($ccs);
                }

                $pending->queue($mailable);
            }
        }
    }
}
