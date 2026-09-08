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

// Public auth & utility routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->name('login');
Route::get('/public/businesses', [BusinessController::class, 'publicList']);
Route::get('/public/outlets', [OutletController::class, 'publicList']);

// Protected routes (require Sanctum token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // SaaS Tenants & Business Management
    Route::apiResource('businesses', BusinessController::class);
    Route::get('/my-business',       [BusinessController::class, 'myBusiness']);
    Route::put('/my-business',       [BusinessController::class, 'updateMyBusiness']);

    // User Management & Approval Workflow
    Route::get('/users',                   [UserController::class, 'index']);
    Route::post('/users',                  [UserController::class, 'store']);
    Route::get('/users/{user}',            [UserController::class, 'show']);
    Route::put('/users/{user}',            [UserController::class, 'update']);
    Route::delete('/users/{user}',         [UserController::class, 'destroy']);
    Route::post('/users/{user}/approve',   [UserController::class, 'approve']);
    Route::post('/users/{user}/reject',    [UserController::class, 'reject']);
    Route::post('/users/{user}/suspend',   [UserController::class, 'suspend']);

    // Shifts
    Route::get('/shifts',                        [ShiftController::class, 'index']);
    Route::get('/shifts/active',                 [ShiftController::class, 'active']);
    Route::post('/shifts/open',                  [ShiftController::class, 'open']);
    Route::get('/shifts/{shift}/summary',        [ShiftController::class, 'summary']);
    Route::post('/shifts/{shift}/close',         [ShiftController::class, 'close']);
    Route::get('/shifts/{shift}/transactions',   [ShiftController::class, 'transactions']);

    // Master Data
    Route::apiResource('ingredients', IngredientController::class);
    Route::apiResource('menus', MenuController::class);
    Route::post('/menus/{menu}/recipes', [MenuController::class, 'storeRecipe']);
    Route::apiResource('outlets', OutletController::class);

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

    // Transfer Bahan Baku antar Cabang
    Route::get('/transfers',                     [TransferController::class, 'index']);
    Route::post('/transfers',                    [TransferController::class, 'store']);
    Route::get('/transfers/{transfer}',          [TransferController::class, 'show']);
    Route::post('/transfers/{transfer}/cancel',  [TransferController::class, 'cancel']);

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
    Route::get('/opnames/history',              [OpnameController::class, 'history']);
    Route::get('/opnames/sessions/{opname_no}', [OpnameController::class, 'showSession']);
    Route::get('/opnames',                      [OpnameController::class, 'index']);
    Route::post('/opnames',                     [OpnameController::class, 'upsert']);
    Route::post('/opnames/bulk',                [OpnameController::class, 'bulkUpsert']);

    // Beban Operasional (OPEX / Operating Expenses)
    Route::get('/expenses/summary',             [ExpenseController::class, 'summary']);
    Route::apiResource('expenses', ExpenseController::class);

    // Arus Kas Nyata (Cash Flow Statement & Mutasi Kas CapEx/Financing)
    Route::get('/cash-flow/statement',          [CashFlowController::class, 'statement']);
    Route::get('/cash-flow/categories',         [CashFlowController::class, 'categories']);
    Route::apiResource('cash-transactions', CashFlowController::class);

    // Reports / Analytics
    Route::get('/reports/dashboard',           [ReportController::class, 'dashboard']);
    Route::get('/reports/variance/ingredients',[ReportController::class, 'varianceIngredients']);
    Route::get('/reports/variance/menus',      [ReportController::class, 'varianceMenus']);
    Route::get('/reports/profitability',       [ReportController::class, 'profitability']);
    Route::get('/reports/profit-loss',          [ReportController::class, 'profitAndLoss']);
    Route::get('/reports/outlet-benchmark',    [ReportController::class, 'outletBenchmark']);
});
