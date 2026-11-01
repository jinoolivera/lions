<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('v1/auth')->group(function (): void {
    Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::get('/user', function (Request $request) { return $request->user(); })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::get('/sunat/mode', [\App\Http\Controllers\Api\V1\SunatSettingsController::class, 'mode']);
    Route::get('/sunat/settings', [\App\Http\Controllers\Api\V1\SunatSettingsController::class, 'show']);
    Route::put('/sunat/settings', [\App\Http\Controllers\Api\V1\SunatSettingsController::class, 'update']);
    Route::post('/sunat/settings', [\App\Http\Controllers\Api\V1\SunatSettingsController::class, 'update']);
    Route::post('/sunat/test-connection', [\App\Http\Controllers\Api\V1\SunatSettingsController::class, 'testConnection']);
    Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
    Route::post('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
    Route::patch('/products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update']);
    Route::post('/products/{product}/presentations', [\App\Http\Controllers\Api\V1\ProductController::class, 'addPresentation']);
    Route::patch('/products/{product}/presentations/{presentation}', [\App\Http\Controllers\Api\V1\ProductController::class, 'updatePresentation']);
    Route::get('/product-categories', [\App\Http\Controllers\Api\V1\ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [\App\Http\Controllers\Api\V1\ProductCategoryController::class, 'store']);
    Route::patch('/product-categories/{productCategory}', [\App\Http\Controllers\Api\V1\ProductCategoryController::class, 'update']);
    Route::get('/product-masters', [\App\Http\Controllers\Api\V1\ProductMasterController::class, 'index']);
    Route::post('/product-brands', [\App\Http\Controllers\Api\V1\ProductMasterController::class, 'storeBrand']);
    Route::patch('/product-brands/{id}', [\App\Http\Controllers\Api\V1\ProductMasterController::class, 'updateBrand']);
    Route::post('/product-units', [\App\Http\Controllers\Api\V1\ProductMasterController::class, 'storeUnit']);
    Route::patch('/product-units/{id}', [\App\Http\Controllers\Api\V1\ProductMasterController::class, 'updateUnit']);
    Route::get('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'index']);
    Route::post('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'store']);
    Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index']);
    Route::post('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'store']);
    Route::get('/customers/{customer}/account', [\App\Http\Controllers\Api\V1\CustomerController::class, 'account']);
    Route::post('/customers/{customer}/advances', [\App\Http\Controllers\Api\V1\CustomerController::class, 'registerAdvance']);
    Route::post('/customers/{customer}/advances/apply', [\App\Http\Controllers\Api\V1\CustomerController::class, 'applyAdvance']);
    Route::get('/purchase-orders', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders/direct', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'direct']);
    Route::post('/purchase-orders', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{purchaseOrder}', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{purchaseOrder}/receipts', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'receive']);
    Route::post('/purchase-orders/{purchaseOrder}/invoices', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'invoice']);
    Route::get('/supplier-invoices', [\App\Http\Controllers\Api\V1\SupplierInvoiceController::class, 'index']);
    Route::post('/supplier-invoices/{supplierInvoice}/payments', [\App\Http\Controllers\Api\V1\SupplierInvoiceController::class, 'pay']);
    Route::get('/warehouses', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'index']);
    Route::post('/warehouses', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'store']);
    Route::post('/warehouses/{warehouse}/locations', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'storeLocation']);
    Route::patch('/warehouses/{warehouse}/locations/{location}', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'updateLocation']);
    Route::get('/inventory/movements', [\App\Http\Controllers\Api\V1\InventoryMovementController::class, 'index']);
    Route::post('/inventory/movements', [\App\Http\Controllers\Api\V1\InventoryMovementController::class, 'store']);
    Route::post('/inventory/counts', [\App\Http\Controllers\Api\V1\InventoryMovementController::class, 'physicalCount']);
    Route::get('/inventory/transfers', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'index']);
    Route::post('/inventory/transfers', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'dispatch']);
    Route::post('/inventory/transfers/{stockTransfer}/receipts', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'receive']);
    Route::get('/cash/sessions', [\App\Http\Controllers\Api\V1\SalesController::class, 'sessions']);
    Route::post('/cash/sessions', [\App\Http\Controllers\Api\V1\SalesController::class, 'openSession']);
    Route::post('/cash/sessions/{cashSession}/close', [\App\Http\Controllers\Api\V1\SalesController::class, 'closeSession']);
    Route::get('/sales', [\App\Http\Controllers\Api\V1\SalesController::class, 'index']);
    Route::post('/sales', [\App\Http\Controllers\Api\V1\SalesController::class, 'create']);
    Route::post('/sales/{sale}/sunat-document', [\App\Http\Controllers\Api\V1\SalesController::class, 'issueSunatDocument']);
    Route::get('/sales/documents/{document}/{kind}', [\App\Http\Controllers\Api\V1\SalesController::class, 'downloadSunatDocument']);
    Route::post('/sales/{sale}/external-document', [\App\Http\Controllers\Api\V1\SalesController::class, 'registerExternalDocument']);
    Route::post('/sales/{sale}/payments', [\App\Http\Controllers\Api\V1\SalesController::class, 'collectPayment']);
    Route::post('/sales/{sale}/returns', [\App\Http\Controllers\Api\V1\SalesController::class, 'returnSale']);
    Route::post('/sales/{sale}/cancel', [\App\Http\Controllers\Api\V1\SalesController::class, 'cancelSale']);
    Route::get('/delivery-orders', [\App\Http\Controllers\Api\V1\DeliveryOrderController::class, 'index']);
    Route::post('/delivery-orders', [\App\Http\Controllers\Api\V1\DeliveryOrderController::class, 'store']);
    Route::post('/delivery-orders/{deliveryOrder}/pick', [\App\Http\Controllers\Api\V1\DeliveryOrderController::class, 'pick']);
    Route::post('/delivery-orders/{deliveryOrder}/deliver', [\App\Http\Controllers\Api\V1\DeliveryOrderController::class, 'deliver']);
    Route::post('/delivery-orders/{deliveryOrder}/cancel', [\App\Http\Controllers\Api\V1\DeliveryOrderController::class, 'cancel']);
    Route::get('/reports/overview', [\App\Http\Controllers\Api\V1\ReportController::class, 'overview']);
    Route::get('/finance/transactions', [\App\Http\Controllers\Api\V1\FinanceController::class, 'index']);
    Route::post('/finance/categories', [\App\Http\Controllers\Api\V1\FinanceController::class, 'storeCategory']);
    Route::post('/finance/transactions', [\App\Http\Controllers\Api\V1\FinanceController::class, 'store']);
});
