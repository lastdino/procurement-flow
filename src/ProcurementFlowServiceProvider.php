<?php

declare(strict_types=1);

namespace Lastdino\ProcurementFlow;

use Illuminate\Support\ServiceProvider;
use Lastdino\ProcurementFlow\Models\Receiving;
use Lastdino\ProcurementFlow\Models\ReceivingItem;
use Lastdino\ProcurementFlow\Livewire\Procurement\Dashboard;
use Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders\Index as PoIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders\Show as PoShowComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\PendingReceiving\Index as PendingReceivingIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Materials\Index as MaterialsIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Suppliers\Index as SuppliersIndexComponent;
use Lastdino\ProcurementFlow\Observers\ReceivingObserver;
use Lastdino\ProcurementFlow\Observers\ReceivingItemObserver;
use Livewire\Livewire;

class ProcurementFlowServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Use underscore in config key to avoid edge cases when accessing via dot notation
        $this->mergeConfigFrom(__DIR__ . '/../config/procurement-flow.php', 'procurement_flow');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Web routes (Volt UI) and views namespace
        $webRoutes = __DIR__ . '/../routes/web.php';
        if (file_exists($webRoutes)) {
            $this->loadRoutesFrom($webRoutes);
        }

        // Expose package views under the "procflow" namespace
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'procflow');

        // Publish views so host apps can override
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/procflow'),
        ], 'procurement-flow-views');

        // Load translations under the "procflow" namespace
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'procflow');

        $this->publishes([
            __DIR__ . '/../config/procurement-flow.php' => config_path('procurement-flow.php'),
        ], 'procurement-flow-config');

        // Publish translations so host apps can override
        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/procflow'),
        ], 'procurement-flow-lang');

        $this->loadLivewireComponents();

        // Register model observers
        Receiving::observe(ReceivingObserver::class);
        ReceivingItem::observe(ReceivingItemObserver::class);
    }

    // custom methods for livewire components
    protected function loadLivewireComponents(): void
    {
        Livewire::component('procurement.dashboard',  Dashboard::class);
        Livewire::component('purchase-orders.index', PoIndexComponent::class);
        Livewire::component('purchase-orders.show', PoShowComponent::class);
        Livewire::component('suppliers.index', SuppliersIndexComponent::class);
        Livewire::component('procurement.materials', MaterialsIndexComponent::class);
        Livewire::component('procurement.pending-receiving.index', PendingReceivingIndexComponent::class);
        Livewire::component('procurement.materials.issue', \Lastdino\ProcurementFlow\Livewire\Procurement\Materials\Issue::class);
        Livewire::component('procurement.receiving.scan', \Lastdino\ProcurementFlow\Livewire\Procurement\Receiving\Scan::class);
        Livewire::component('procurement.settings.options.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Options\Index::class);
        Livewire::component('procurement.settings.approval.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Approval\Index::class);
        Livewire::component('procurement.settings.taxes.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Taxes\Index::class);
        Livewire::component('procurement.settings.pdf.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Pdf\Index::class);
        Livewire::component('procurement.settings.categories.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Categories\Index::class);
        Livewire::component('procurement.settings.display.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Display\Index::class);
        // Tokens management and labels
        Livewire::component('procurement.settings.tokens.index', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Tokens\Index::class);
        Livewire::component('procurement.settings.tokens.labels', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Tokens\Labels::class);
        // Ordering scan (QR → 発注ドラフト作成)
        Livewire::component('procurement.ordering.scan', \Lastdino\ProcurementFlow\Livewire\Procurement\Ordering\Scan::class);
    }
}
