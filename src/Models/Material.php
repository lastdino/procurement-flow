<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Lastdino\ProcurementFlow\Support\Tables;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Material extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'sku', 'name', 'tax_code', 'unit_stock', 'unit_purchase_default', 'safety_stock', 'category_id', 'current_stock', 'preferred_supplier_id',
        'manufacturer_name', 'storage_location', 'applicable_regulation', 'ghs_mark', 'protective_equipment', 'unit_price',
        // 発注制約
        'moq', 'pack_size',
        // shipping
        'separate_shipping', 'shipping_fee_per_order',
        // lot management
        'manage_by_lot',
        // activation
        'is_active',
    ];

    public function getTable()
    {
        return Tables::name('materials');
    }

    protected function casts(): array
    {
        return [
            'safety_stock' => 'decimal:6',
            'current_stock' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'moq' => 'decimal:6',
            'pack_size' => 'decimal:6',
            'separate_shipping' => 'boolean',
            'shipping_fee_per_order' => 'decimal:2',
            'manage_by_lot' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope: only active materials.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'category_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(MaterialLot::class, 'material_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'material_id');
    }

    /**
     * Register media collections for this model.
     */
    public function registerMediaCollections(): void
    {
        // SDS (Safety Data Sheet) – PDF only, single file per material
        $this->addMediaCollection('sds')
            ->acceptsMimeTypes(['application/pdf'])
            ->useDisk('local')
            ->singleFile();
    }

    /**
     * Parse the ghs_mark string into an array of keys.
     */
    public function ghsMarkList(): array
    {
        $raw = (string) ($this->ghs_mark ?? '');
        if ($raw === '') {
            return [];
        }
        /** @var array<int, string> $tokens */
        $tokens = array_map('trim', preg_split('/[\s,|]+/', $raw) ?: []);
        return array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
    }

    /**
     * Build public URLs for GHS images based on config mapping and stored keys.
     *
     * @return array<int, string>
     */
    public function ghsImageUrls(): array
    {
        // Prefer hyphen key if the host app publishes its own config file, else fall back to merged underscore key
        /** @var array<string, mixed> $cfg */
        $cfg = (array) (config('procurement-flow.ghs') ?? config('procurement_flow.ghs') ?? []);

        /** @var string $disk */
        $disk = (string) ($cfg['disk'] ?? 'public');
        /** @var string $dir */
        $dir = trim((string) ($cfg['directory'] ?? 'ghs_labels'), '/');
        /** @var array<string, string> $map */
        $map = (array) ($cfg['map'] ?? []);
        /** @var string|null $placeholder */
        $placeholder = isset($cfg['placeholder']) ? (string) $cfg['placeholder'] : null;

        $urls = [];
        foreach ($this->ghsMarkList() as $key) {
            $filename = $map[$key] ?? null;
            if (is_string($filename) && $filename !== '') {
                $path = $dir . '/' . ltrim($filename, '/');
                if (Storage::disk($disk)->exists($path)) {
                    $urls[] = Storage::disk($disk)->url($path);
                    continue;
                }
            }

            if (is_string($placeholder) && $placeholder !== '') {
                $phPath = $dir . '/' . ltrim($placeholder, '/');
                if (Storage::disk($disk)->exists($phPath)) {
                    $urls[] = Storage::disk($disk)->url($phPath);
                }
            }
        }

        return $urls;
    }
}
