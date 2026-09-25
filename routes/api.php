<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\OpnameController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BatchPrepController;
use App\Http\Controllers\ModifierController;
use App\Http\Controllers\WasteController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\ShiftScheduleController;
use App\Http\Controllers\CoinPlatformController;
use App\Http\Controllers\UrgentNoteController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ReceivableController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SalesReportController;

// Public auth & utility routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->name('login');
Route::post('/forgot-password',     [AuthController::class, 'forgotPassword']);
Route::post('/verify-reset-code',   [AuthController::class, 'verifyResetCode']);
Route::post('/reset-password',      [AuthController::class, 'resetPassword']);
Route::get('/public/businesses', [BusinessController::class, 'publicList']);
Route::get('/public/outlets', [OutletController::class, 'publicList']);

// Protected routes (require Sanctum token and enforce outlet scoping)
Route::middleware(['auth:sanctum', \App\Http\Middleware\EnforceOutletScope::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // SaaS Tenants & Business Management
    Route::apiResource('businesses', BusinessController::class);
    Route::get('/my-business',       [BusinessController::class, 'myBusiness']);
    Route::get('/my-business/coins', [BusinessController::class, 'myCoins']);
    Route::put('/my-business',       [BusinessController::class, 'updateMyBusiness']);

    // Master Tables (ID-based Lookup)
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units', UnitController::class);
    Route::apiResource('expense-categories', ExpenseCategoryController::class);
    Route::apiResource('payment-methods', PaymentMethodController::class);

    // SaaS Platform Coin Top-Up & Rate Management (Website Owner / Superadmin Platform)
    Route::get('/platform/coins/overview', [CoinPlatformController::class, 'overview']);
    Route::post('/platform/coins/topup',   [CoinPlatformController::class, 'topUp']);
    Route::put('/platform/coins/rate',     [CoinPlatformController::class, 'updateRate']);
    Route::get('/platform/coins/history',  [CoinPlatformController::class, 'history']);

    // User Management & Approval Workflow
    Route::get('/roles',                   [UserController::class, 'roles']);
    Route::get('/users',                   [UserController::class, 'index']);
    Route::post('/users',                  [UserController::class, 'store']);
    Route::get('/users/{user}',            [UserController::class, 'show']);
    Route::put('/users/{user}',            [UserController::class, 'update']);
    Route::delete('/users/{user}',         [UserController::class, 'destroy']);
    Route::post('/users/{user}/approve',   [UserController::class, 'approve']);
    Route::post('/users/{user}/reject',    [UserController::class, 'reject']);
    Route::post('/users/{user}/suspend',   [UserController::class, 'suspend']);

    // Shifts & Shift Schedules (Master Shift & Roster Jadwal Kasir)
    Route::get('/shift-schedules/employees',      [ShiftScheduleController::class, 'employees']);
    Route::apiResource('shift-schedules',         ShiftScheduleController::class);
    Route::get('/shifts',                        [ShiftController::class, 'index']);
    Route::get('/shifts/active',                 [ShiftController::class, 'active']);
    Route::post('/shifts/open',                  [ShiftController::class, 'open']);
    Route::get('/shifts/{shift}/summary',        [ShiftController::class, 'summary']);
    Route::post('/shifts/{shift}/close',         [ShiftController::class, 'close']);
    Route::get('/shifts/{shift}/transactions',   [ShiftController::class, 'transactions']);

    // Master Data
    Route::post('/ingredients/bulk-import',    [IngredientController::class, 'bulkImport']);
    Route::post('/perlengkapans/bulk-import',  [IngredientController::class, 'bulkImportPerlengkapan']);
    Route::post('/menus/bulk-import',          [MenuController::class, 'bulkImport']);
    Route::post('/receivables/bulk-import',    [ReceivableController::class, 'bulkImport']);
    Route::post('/outlets/bulk-import',        [OutletController::class, 'bulkImport']);
    Route::apiResource('ingredients', IngredientController::class);
    Route::apiResource('menus', MenuController::class);
    Route::post('/menus/{menu}/recipes', [MenuController::class, 'storeRecipe']);
    Route::post('/menus/{menu}/restock', [MenuController::class, 'restock']);
    Route::get('/menus/{menu}/hpp-history', [MenuController::class, 'hppHistory']);
    Route::apiResource('outlets', OutletController::class);

    // Master Customer / Member & Poin
    Route::get('/customers/search-pos', [CustomerController::class, 'searchForPos']);
    Route::apiResource('customers', CustomerController::class);

    // Menu Modifiers / Options & Addons
    Route::apiResource('modifier-groups', ModifierController::class);
    Route::post('/menus/{menu}/modifiers', [ModifierController::class, 'assignToMenu']);

    // Resep Bertingkat & Bahan Olahan / Batch Prep
    Route::get('/prep-recipes',                 [BatchPrepController::class, 'indexRecipes']);
    Route::post('/prep-recipes',                [BatchPrepController::class, 'storeRecipe']);
    Route::get('/prep-recipes/{prepRecipe}',    [BatchPrepController::class, 'showRecipe']);
    Route::put('/prep-recipes/{prepRecipe}',    [BatchPrepController::class, 'updateRecipe']);
    Route::delete('/prep-recipes/{prepRecipe}', [BatchPrepController::class, 'destroyRecipe']);

    Route::get('/batch-preps/preview',          [BatchPrepController::class, 'previewBatch']);
    Route::get('/batch-preps',                  [BatchPrepController::class, 'indexBatches']);
    Route::post('/batch-preps',                 [BatchPrepController::class, 'storeBatch']);
    Route::get('/batch-preps/{batchPrep}',      [BatchPrepController::class, 'showBatch']);
    Route::delete('/batch-preps/{batchPrep}',   [BatchPrepController::class, 'destroyBatch']);

    // Transfer Bahan Baku antar Cabang
    Route::get('/transfers',                            [TransferController::class, 'index']);
    Route::post('/transfers',                           [TransferController::class, 'store']);
    Route::get('/transfers/{transfer}',                 [TransferController::class, 'show']);
    Route::post('/transfers/{transfer}/receive',        [TransferController::class, 'receive']);
    Route::post('/transfers/{transfer}/return',         [TransferController::class, 'returnTransfer']);
    Route::post('/transfers/{transfer}/approve-return', [TransferController::class, 'approveReturn']);
    Route::post('/transfers/{transfer}/reject-return',  [TransferController::class, 'rejectReturn']);
    Route::post('/transfers/{transfer}/cancel',         [TransferController::class, 'cancel']);

    // POS & Open Bills (Dine-in Table Management)
    Route::get('/transactions/open-bills',                               [TransactionController::class, 'openBills']);
    Route::post('/transactions/open-bills/{orderNumber}/pay',            [TransactionController::class, 'payOpenBill']);
    Route::post('/transactions/open-bills/{orderNumber}/add-items',      [TransactionController::class, 'addItemsToOpenBill']);
    Route::post('/transactions/open-bills/{orderNumber}/cancel',         [TransactionController::class, 'cancelOpenBill']);
    Route::post('/transactions/open-bills/{orderNumber}/split-by-item',  [TransactionController::class, 'paySplitByItem']);
    Route::post('/transactions/open-bills/{orderNumber}/split-evenly',   [TransactionController::class, 'paySplitEvenly']);
    Route::post('/transactions/{orderNumber}/pay',                        [TransactionController::class, 'payOpenBill']);
    Route::post('/transactions/{orderNumber}/add-items',                 [TransactionController::class, 'addItemsToOpenBill']);
    Route::post('/transactions/{orderNumber}/cancel',                    [TransactionController::class, 'cancelOpenBill']);
    Route::post('/transactions/{orderNumber}/split-by-item',             [TransactionController::class, 'paySplitByItem']);
    Route::post('/transactions/{orderNumber}/split-evenly',              [TransactionController::class, 'paySplitEvenly']);
    Route::get('/transactions',                                          [TransactionController::class, 'index']);
    Route::post('/transactions',                                         [TransactionController::class, 'store']);
    Route::delete('/transactions/{transaction}',                    [TransactionController::class, 'destroy']);

    // Nota Urgent & Bahan Tergantung (Pending Stock Shortfall Management)
    Route::get('/urgent-notes/summary',                 [UrgentNoteController::class, 'summary']);
    Route::get('/urgent-notes',                         [UrgentNoteController::class, 'index']);
    Route::post('/urgent-notes/{urgentNote}/resolve',   [UrgentNoteController::class, 'resolve']);
    Route::post('/urgent-notes/{urgentNote}/cancel',    [UrgentNoteController::class, 'cancel']);
    Route::post('/urgent-notes/batch-resolve',          [UrgentNoteController::class, 'batchResolve']);

    // Stock & Kartu Stok
    Route::get('/movements',          [MovementController::class, 'index']);
    Route::post('/movements',         [MovementController::class, 'store']);
    Route::get('/stock-card/summary', [MovementController::class, 'stockCardSummary']);
    Route::get('/stock-card',         [MovementController::class, 'stockCard']);

    // Waste & Spoilage Tracking (Bahan Terbuang & Rusak)
    Route::get('/waste-logs/analytics', [WasteController::class, 'analytics']);
    Route::apiResource('waste-logs', WasteController::class)->only(['index', 'store', 'destroy']);

    // Promo & Diskon (Discounts & Vouchers)
    Route::get('/discounts/available',          [DiscountController::class, 'availableForPos']);
    Route::post('/discounts/validate-code',     [DiscountController::class, 'validateCode']);
    Route::post('/discounts/{discount}/toggle', [DiscountController::class, 'toggleActive']);
    Route::apiResource('discounts', DiscountController::class);

    // Opname
    Route::get('/opnames/history',                      [OpnameController::class, 'history']);
    Route::get('/opnames/sessions/{opname_no}',         [OpnameController::class, 'showSession']);
    Route::post('/opnames/sessions/{opname_no}/release', [OpnameController::class, 'releaseSession']);
    Route::get('/opnames',                              [OpnameController::class, 'index']);
    Route::post('/opnames',                             [OpnameController::class, 'upsert']);
    Route::post('/opnames/bulk',                        [OpnameController::class, 'bulkUpsert']);

    // Beban Operasional (OPEX / Operating Expenses)
    Route::get('/expenses/summary',             [ExpenseController::class, 'summary']);
    Route::apiResource('expenses', ExpenseController::class);

    // Kasbon Customer (Accounts Receivable & Pembayaran Kasbon)
    Route::get('/receivables/customers',                                [ReceivableController::class, 'byCustomer']);
    Route::post('/receivables/bulk-payment',                            [ReceivableController::class, 'bulkPayment']);
    Route::post('/receivables/{receivable}/payments',                   [ReceivableController::class, 'addPayment']);
    Route::delete('/receivables/{receivable}/payments/{payment}',       [ReceivableController::class, 'deletePayment']);
    Route::apiResource('receivables', ReceivableController::class);

    // Arus Kas Nyata (Cash Flow Statement & Mutasi Kas CapEx/Financing)
    Route::get('/cash-flow/statement',          [CashFlowController::class, 'statement']);
    Route::get('/cash-flow/categories',         [CashFlowController::class, 'categories']);
    Route::apiResource('cash-transactions', CashFlowController::class);

    // Reports / Analytics
    Route::get('/reports/dashboard',           [ReportController::class, 'dashboard']);
    Route::get('/reports/widgets',             [ReportController::class, 'dashboardWidgets']);
    Route::get('/reports/variance/ingredients',[ReportController::class, 'varianceIngredients']);
    Route::get('/reports/variance/menus',      [ReportController::class, 'varianceMenus']);
    Route::get('/reports/profitability',       [ReportController::class, 'profitability']);
    Route::get('/reports/profit-loss',          [ReportController::class, 'profitAndLoss']);
    Route::get('/reports/outlet-benchmark',    [ReportController::class, 'outletBenchmark']);

    // Sales Reports (Laporan Penjualan POS)
    Route::get('/reports/sales/by-product',           [SalesReportController::class, 'salesByProduct']);
    Route::get('/reports/sales/point-redemptions',     [SalesReportController::class, 'pointRedemptions']);
    Route::get('/reports/sales/payments',              [SalesReportController::class, 'salesPayments']);
    Route::get('/reports/sales/transactions',          [SalesReportController::class, 'salesTransactionsDetailed']);
    Route::get('/reports/sales/by-customer',           [SalesReportController::class, 'salesByCustomer']);
    Route::get('/reports/sales/peak-hours',            [SalesReportController::class, 'peakHours']);
    Route::get('/reports/sales/customer-receivables',  [SalesReportController::class, 'customerReceivables']);
    Route::get('/reports/sales/promos',                [SalesReportController::class, 'promos']);
});
