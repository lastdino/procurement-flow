<?php

use Illuminate\Support\Facades\Route;
use Lastdino\ProcurementFlow\Livewire\Procurement\Dashboard as DashboardComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders\Index as PoIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\PurchaseOrders\Show as PoShowComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\PendingReceiving\Index as PendingReceivingIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Materials\Index as MaterialsIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Materials\Show as MaterialsShowComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Materials\Issue as MaterialsIssueComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Suppliers\Index as SuppliersIndexComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Receiving\Scan as ReceivingScanComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Ordering\Scan as OrderingScanComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Options\Index as OptionsSettingsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Approval\Index as ApprovalSettingsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Taxes\Index as TaxesSettingsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Pdf\Index as PdfSettingsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Tokens\Index as TokensSettingsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Tokens\Labels as TokenLabelsComponent;
use Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Categories\Index as CategoriesSettingsComponent;
use Lastdino\ProcurementFlow\Http\Controllers\PurchaseOrderPdfController;
use Lastdino\ProcurementFlow\Http\Controllers\MaterialSdsDownloadController;


Route::group([
    'prefix' => config('procurement_flow.route_prefix', 'procurement'),
    'middleware' => config('procurement_flow.middleware', ['web', 'auth']),
], function () {
    // Dashboard
    Route::get('/', DashboardComponent::class)
        ->name('procurement.dashboard');

    // Purchase Orders
    Route::get('/purchase-orders', PoIndexComponent::class)
        ->name('procurement.purchase-orders.index');

    // Purchase Order detail
    Route::get('/purchase-orders/{po}', PoShowComponent::class)
        ->name('procurement.purchase-orders.show');

    // Purchase Order PDF download
    Route::get('/purchase-orders/{po}/pdf', PurchaseOrderPdfController::class)
        ->name('procurement.purchase-orders.pdf');


    // Pending Receiving
    Route::get('/pending-receiving', PendingReceivingIndexComponent::class)
        ->name('procurement.pending-receiving.index');

    // Materials
    Route::get('/materials', MaterialsIndexComponent::class)
        ->name('procurement.materials.index');
    Route::get('/materials/{material}', MaterialsShowComponent::class)
        ->name('procurement.materials.show');
    Route::get('/materials/{material}/issue', MaterialsIssueComponent::class)
        ->name('procurement.materials.issue');
    // Material SDS secure download (signed + auth)
    Route::get('/materials/{material}/sds', MaterialSdsDownloadController::class)
        ->middleware('signed')
        ->name('procurement.materials.sds.download');

    // Suppliers
    Route::get('/suppliers', SuppliersIndexComponent::class)
        ->name('procurement.suppliers.index');

    // Receiving scan page
    Route::get('/receivings/scan', ReceivingScanComponent::class)
        ->name('procurement.receiving.scan');

    // Options settings
    Route::get('/settings/options', OptionsSettingsComponent::class)
        ->name('procurement.settings.options');

    // Approval settings (flowId selection for POs)
    Route::get('/settings/approval', ApprovalSettingsComponent::class)
        ->name('procurement.settings.approval');

    // Taxes settings
    Route::get('/settings/taxes', TaxesSettingsComponent::class)
        ->name('procurement.settings.taxes');

    // Material Categories settings
    Route::get('/settings/categories', CategoriesSettingsComponent::class)
        ->name('procurement.settings.categories');

    // PDF settings
    Route::get('/settings/pdf', PdfSettingsComponent::class)
        ->name('procurement.settings.pdf');

    // Display settings (decimals & currency)
    Route::get('/settings/display', \Lastdino\ProcurementFlow\Livewire\Procurement\Settings\Display\Index::class)
        ->name('procurement.settings.display');

    // Ordering Tokens settings (CRUD)
    Route::get('/settings/tokens', TokensSettingsComponent::class)
        ->name('procurement.settings.tokens');

    // Token Labels (printable)
    Route::get('/settings/labels', TokenLabelsComponent::class)
        ->name('procurement.settings.labels');

    // Ordering scan page (QR→発注ドラフト作成)
    Route::get('/ordering/scan', OrderingScanComponent::class)
        ->name('procurement.ordering.scan');
});

