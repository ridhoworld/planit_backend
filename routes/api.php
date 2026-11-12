<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DebtController;
use App\Http\Controllers\Api\FinancialGoalController;
use App\Http\Controllers\Api\InvestmentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RecurringTransactionController;
use App\Http\Controllers\Api\SplitBillController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Auth & User Profile
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user', [AuthController::class, 'updateProfile']);

    // Dashboard & Analytics
    Route::get('/dashboard', [DashboardController::class, 'summary']);
    Route::get('/reports', [DashboardController::class, 'reports']);

    // Accounts
    Route::apiResource('accounts', AccountController::class);
    Route::post('/accounts/{id}/adjust-balance', [AccountController::class, 'adjustBalance']);

    // Categories
    Route::apiResource('categories', CategoryController::class)->except(['show']);

    // Tags
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);

    // Transactions
    Route::apiResource('transactions', TransactionController::class);
    Route::post('/transactions/{id}/receipt', [TransactionController::class, 'uploadReceipt']);

    // Budgets
    Route::apiResource('budgets', BudgetController::class)->except(['show']);

    // Recurring Bills & Subscriptions
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)->except(['show']);
    Route::post('/recurring-transactions/{id}/execute', [RecurringTransactionController::class, 'execute']);

    // Financial Goals
    Route::apiResource('financial-goals', FinancialGoalController::class)->except(['show']);
    Route::post('/financial-goals/{id}/deposit', [FinancialGoalController::class, 'deposit']);

    // Debts & Receivables
    Route::apiResource('debts', DebtController::class);
    Route::post('/debts/{id}/payments', [DebtController::class, 'addPayment']);

    // Investments & Portfolio
    Route::apiResource('investments', InvestmentController::class)->except(['show']);
    Route::post('/investments/{id}/update-price', [InvestmentController::class, 'updatePrice']);

    // Split Bills
    Route::get('/split-bills', [SplitBillController::class, 'index']);
    Route::post('/split-bills', [SplitBillController::class, 'store']);
    Route::put('/split-bills/{billId}/participant/{participantId}/toggle-paid', [SplitBillController::class, 'toggleParticipantPaid']);
    Route::delete('/split-bills/{id}', [SplitBillController::class, 'destroy']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Admin Management (Role: Admin Only)
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'stats']);
        Route::apiResource('users', \App\Http\Controllers\Api\Admin\UserController::class);
        Route::apiResource('categories', \App\Http\Controllers\Api\Admin\CategoryController::class);
        Route::get('/transactions', [\App\Http\Controllers\Api\Admin\TransactionController::class, 'index']);
    });
});
