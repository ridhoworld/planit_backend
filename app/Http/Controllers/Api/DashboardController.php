<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Debt;
use App\Models\FinancialGoal;
use App\Models\Investment;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get complete dashboard summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        // 1. Account balances
        $liquidAccounts = Account::where('user_id', $userId)
            ->where('is_active', true)
            ->where('type', '!=', 'credit_card')
            ->get();
        $totalLiquidBalance = (float) $liquidAccounts->sum('current_balance');

        $creditCards = Account::where('user_id', $userId)
            ->where('is_active', true)
            ->where('type', 'credit_card')
            ->get();
        $totalCreditDebt = (float) $creditCards->sum('current_balance');

        // 2. Investments Value
        $investments = Investment::where('user_id', $userId)->get();
        $totalInvestmentValue = (float) $investments->sum('current_value');

        // 3. Debts & Receivables
        $totalPayable = (float) Debt::where('user_id', $userId)->where('type', 'payable')->sum('remaining_amount');
        $totalReceivable = (float) Debt::where('user_id', $userId)->where('type', 'receivable')->sum('remaining_amount');

        // FR-4.4: Net Worth calculation
        // Net worth = total saldo akun aktif + nilai investasi + total piutang − total utang − saldo terutang kartu kredit
        $netWorth = $totalLiquidBalance + $totalInvestmentValue + $totalReceivable - $totalPayable - $totalCreditDebt;

        // 4. Monthly Cashflow (Current Month)
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $monthlyIncome = (float) Transaction::where('user_id', $userId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $monthlyExpense = (float) Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $netCashflow = $monthlyIncome - $monthlyExpense;

        // 5. Recent transactions
        $recentTransactions = Transaction::where('user_id', $userId)
            ->with(['account', 'targetAccount', 'category', 'tags'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        // 6. Active Budgets Preview (top 3)
        $budgets = Budget::where('user_id', $userId)->with('category')->limit(3)->get()->map(function ($budget) use ($userId) {
            $subCategoryIds = Category::where('parent_id', $budget->category_id)->pluck('id')->toArray();
            $categoryIds = array_merge([$budget->category_id], $subCategoryIds);

            $spent = (float) Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereIn('category_id', $categoryIds)
                ->whereBetween('transaction_date', [
                    $budget->start_date->toDateString() . ' 00:00:00',
                    $budget->end_date->toDateString() . ' 23:59:59'
                ])
                ->sum('amount');

            $limit = (float) $budget->amount_limit;
            $percentage = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0;

            return [
                'id' => $budget->id,
                'category_name' => $budget->category->name ?? 'Kategori',
                'category_color' => $budget->category->color ?? '#3B82F6',
                'amount_limit' => $limit,
                'spent_amount' => $spent,
                'percentage' => $percentage,
            ];
        });

        // 7. Upcoming bills in next 14 days
        $in14Days = Carbon::now()->addDays(14)->endOfDay();
        $upcomingBills = RecurringTransaction::where('user_id', $userId)
            ->where('is_active', true)
            ->where('next_due_date', '<=', $in14Days->toDateString())
            ->with(['account', 'category'])
            ->orderBy('next_due_date', 'asc')
            ->limit(3)
            ->get();

        // 8. Goals preview
        $goals = FinancialGoal::where('user_id', $userId)
            ->where('status', 'in_progress')
            ->limit(3)
            ->get()
            ->map(function ($g) {
                $target = (float) $g->target_amount;
                $current = (float) $g->current_amount;
                $pct = $target > 0 ? min(100, round(($current / $target) * 100, 1)) : 0;
                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'target_amount' => $target,
                    'current_amount' => $current,
                    'percentage' => $pct,
                    'color' => $g->color,
                ];
            });

        return response()->json([
            'net_worth' => $netWorth,
            'total_balance' => $totalLiquidBalance,
            'total_investments' => $totalInvestmentValue,
            'total_debts' => $totalPayable,
            'total_receivables' => $totalReceivable,
            'monthly_income' => $monthlyIncome,
            'monthly_expense' => $monthlyExpense,
            'net_cashflow' => $netCashflow,
            'recent_transactions' => $recentTransactions,
            'budgets_preview' => $budgets,
            'upcoming_bills' => $upcomingBills,
            'goals_preview' => $goals,
        ]);
    }

    /**
     * Get detailed analytics and reports.
     */
    public function reports(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $month = (int) $request->get('month', Carbon::now()->month);
        $year = (int) $request->get('year', Carbon::now()->year);

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Category Expense Breakdown
        $categoryExpenses = Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select('category_id', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('category_id')
            ->with('category')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'name' => $item->category->name ?? 'Lainnya',
                    'color' => $item->category->color ?? '#EF4444',
                    'icon' => $item->category->icon ?? 'category',
                    'amount' => (float) $item->total_amount,
                ];
            })
            ->sortByDesc('amount')
            ->values();

        $totalExpenseMonth = $categoryExpenses->sum('amount');
        $categoryBreakdown = $categoryExpenses->map(function ($item) use ($totalExpenseMonth) {
            $item['percentage'] = $totalExpenseMonth > 0 ? round(($item['amount'] / $totalExpenseMonth) * 100, 1) : 0;
            return $item;
        });

        // 6-Month Trend
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $mDate = Carbon::now()->subMonths($i);
            $mStart = $mDate->copy()->startOfMonth();
            $mEnd = $mDate->copy()->endOfMonth();

            $inc = (float) Transaction::where('user_id', $userId)
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$mStart, $mEnd])
                ->sum('amount');

            $exp = (float) Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$mStart, $mEnd])
                ->sum('amount');

            $monthlyTrend[] = [
                'month_label' => $mDate->isoFormat('MMM YY'),
                'income' => $inc,
                'expense' => $exp,
                'net' => $inc - $exp,
            ];
        }

        // Account balances
        $accounts = Account::where('user_id', $userId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type', 'current_balance', 'color']);

        return response()->json([
            'month' => $month,
            'year' => $year,
            'total_expense' => $totalExpenseMonth,
            'category_breakdown' => $categoryBreakdown,
            'monthly_trend' => $monthlyTrend,
            'accounts' => $accounts,
        ]);
    }
}

