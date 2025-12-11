<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lastdino\ProcurementFlow\Support\Tables;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('purchase_order_items'), function (Blueprint $table): void {
            // Use explicit, short FK name to avoid MariaDB 64-char identifier limit
            $table->unsignedBigInteger('shipping_for_item_id')
                ->nullable()
                ->after('manufacturer');

            $table->foreign('shipping_for_item_id', 'pf_poi_ship_for_fk')
                ->references('id')
                ->on(Tables::name('purchase_order_items'))
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        // Best-effort backfill: parse description like "送料（...）" and map to target item by token > SKU > name
        $this->backfillShippingLinks();
    }

    public function down(): void
    {
        Schema::table(Tables::name('purchase_order_items'), function (Blueprint $table): void {
            // Drop the explicitly named foreign key first
            $table->dropForeign('pf_poi_ship_for_fk');
            $table->dropColumn('shipping_for_item_id');
        });
    }

    protected function backfillShippingLinks(): void
    {
        // Use DB facade subtly to avoid heavy Eloquent boot in migrations
        $table = Tables::name('purchase_order_items');
        $materials = Tables::name('materials');

        // Fetch candidate shipping rows
        $shippingRows = \DB::table($table)
            ->where('unit_purchase', 'shipping')
            ->whereNull('shipping_for_item_id')
            ->select('id','purchase_order_id','description')
            ->get();

        foreach ($shippingRows as $row) {
            $desc = (string) ($row->description ?? '');
            if ($desc === '') { continue; }

            // Extract inner-most parentheses content: 送料（...） or 送料(...)
            $key = null;
            if (preg_match('/\p{Ps}([^\p{Pe}]*)\p{Pe}|\(([^)]*)\)/u', $desc, $m)) {
                $key = (string) ($m[1] ?: ($m[2] ?? ''));
                $key = trim($key);
            }
            if ($key === null || $key === '') { continue; }

            $targetId = null;

            // token:SCAN
            if (Str::startsWith(Str::lower($key), 'token:')) {
                $token = trim(Str::after($key, ':'));
                $targetId = \DB::table($table)
                    ->where('purchase_order_id', $row->purchase_order_id)
                    ->where('unit_purchase', '!=', 'shipping')
                    ->where('scan_token', $token)
                    ->value('id');
            }

            // SKU match in same PO
            if ($targetId === null) {
                $targetId = \DB::table($table . ' as i')
                    ->join($materials . ' as m', 'm.id', '=', 'i.material_id')
                    ->where('i.purchase_order_id', $row->purchase_order_id)
                    ->where('i.unit_purchase', '!=', 'shipping')
                    ->where('m.sku', $key)
                    ->value('i.id');
            }

            // Material name exact match in same PO
            if ($targetId === null) {
                $targetId = \DB::table($table . ' as i')
                    ->join($materials . ' as m', 'm.id', '=', 'i.material_id')
                    ->where('i.purchase_order_id', $row->purchase_order_id)
                    ->where('i.unit_purchase', '!=', 'shipping')
                    ->where('m.name', $key)
                    ->value('i.id');
            }

            if ($targetId !== null) {
                \DB::table($table)
                    ->where('id', $row->id)
                    ->update(['shipping_for_item_id' => $targetId]);
            }
        }
    }
};
