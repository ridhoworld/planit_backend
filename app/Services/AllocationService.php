<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialGoal;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class AllocationService
{
    /**
     * Calculate 50/30/20 Allocation Summary (Kebutuhan Pokok 50%, Keinginan 30%, Saving 20%)
     * for the given user and month/year.
     */
    public function calculateMonthlyAllocation(User $user, ?int $month = null, ?int $year = null): array
    {
        $month = $month ?: Carbon::now()->month;
        $year = $year ?: Carbon::now()->year;

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // 1. Monthly Income & Target Baseline
        $actualMonthlyIncome = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $incomeTarget = (float) ($user->monthly_income_target ?? 0);
        $baseIncome = $incomeTarget > 0 ? $incomeTarget : $actualMonthlyIncome;

        // 2. Ratios (Default 50 / 30 / 20)
        $needsRatio = (int) ($user->needs_ratio ?? 50);
        $wantsRatio = (int) ($user->wants_ratio ?? 30);
        $savingsRatio = (int) ($user->savings_ratio ?? 20);

        if ($needsRatio + $wantsRatio + $savingsRatio !== 100) {
            $needsRatio = 50;
            $wantsRatio = 30;
            $savingsRatio = 20;
        }

        // 3. Gather Expense Transactions for this month
        $expenses = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->with('category.parent')
            ->get();

        $needsSpent = 0.0;
        $wantsSpent = 0.0;
        $explicitSavingsSpent = 0.0;

        $needsBreakdown = [];
        $wantsBreakdown = [];
        $savingsBreakdown = [];

        foreach ($expenses as $tx) {
            $cat = $tx->category;
            $catName = $cat ? $cat->name : 'Pengeluaran Lainnya';
            $group = $cat ? $cat->effective_allocation_group : 'needs';
            $amount = (float) $tx->amount;

            if ($group === 'wants') {
                $wantsSpent += $amount;
                $wantsBreakdown[$catName] = ($wantsBreakdown[$catName] ?? 0.0) + $amount;
            } elseif ($group === 'savings') {
                $explicitSavingsSpent += $amount;
                $savingsBreakdown[$catName] = ($savingsBreakdown[$catName] ?? 0.0) + $amount;
            } else {
                $needsSpent += $amount;
                $needsBreakdown[$catName] = ($needsBreakdown[$catName] ?? 0.0) + $amount;
            }
        }

        $totalConsumptionExpense = $needsSpent + $wantsSpent;
        $totalAllExpense = $totalConsumptionExpense + $explicitSavingsSpent;

        // 4. Calculate Limits / Targets based on Base Income
        // If baseIncome is 0 but user has expenses, we can also compute relative ratio against total expenses
        $referenceAmount = $baseIncome > 0 ? $baseIncome : $totalAllExpense;

        $needsLimit = round($baseIncome * ($needsRatio / 100), 2);
        $wantsLimit = round($baseIncome * ($wantsRatio / 100), 2);
        $savingsTarget = round($baseIncome * ($savingsRatio / 100), 2);

        // Actual savings:
        // If user has baseIncome > 0, savings = unspent income (baseIncome - needsSpent - wantsSpent)
        // which naturally includes explicitSavingsSpent + remaining cashflow!
        // If baseIncome == 0, savings = explicitSavingsSpent.
        $actualSaved = $baseIncome > 0
            ? max($explicitSavingsSpent, $baseIncome - $needsSpent - $wantsSpent)
            : $explicitSavingsSpent;

        // 5. Status & Percentages for Needs (50%)
        $needsShareOfIncome = $referenceAmount > 0 ? round(($needsSpent / $referenceAmount) * 100, 1) : 0.0;
        $needsUsagePct = $needsLimit > 0 ? round(($needsSpent / $needsLimit) * 100, 1) : ($needsSpent > 0 ? 100.0 : 0.0);
        $needsRemaining = max(0.0, $needsLimit - $needsSpent);
        $needsStatus = 'safe';
        if ($needsLimit > 0 && $needsSpent > $needsLimit) {
            $needsStatus = 'exceeded';
        } elseif ($needsUsagePct >= 85) {
            $needsStatus = 'warning';
        }

        // 6. Status & Percentages for Wants (30%)
        $wantsShareOfIncome = $referenceAmount > 0 ? round(($wantsSpent / $referenceAmount) * 100, 1) : 0.0;
        $wantsUsagePct = $wantsLimit > 0 ? round(($wantsSpent / $wantsLimit) * 100, 1) : ($wantsSpent > 0 ? 100.0 : 0.0);
        $wantsRemaining = max(0.0, $wantsLimit - $wantsSpent);
        $wantsStatus = 'safe';
        if ($wantsLimit > 0 && $wantsSpent > $wantsLimit) {
            $wantsStatus = 'exceeded';
        } elseif ($wantsUsagePct >= 85) {
            $wantsStatus = 'warning';
        }

        // 7. Status & Percentages for Savings (20%)
        $savingsShareOfIncome = $referenceAmount > 0 ? round(($actualSaved / $referenceAmount) * 100, 1) : 0.0;
        $savingsAchievementPct = $savingsTarget > 0 ? round(($actualSaved / $savingsTarget) * 100, 1) : ($actualSaved > 0 ? 100.0 : 0.0);
        $savingsShortfall = max(0.0, $savingsTarget - $actualSaved);
        $savingsStatus = 'achieved';
        if ($savingsTarget > 0) {
            if ($actualSaved >= $savingsTarget) {
                $savingsStatus = 'achieved';
            } elseif ($actualSaved >= ($savingsTarget * 0.5)) {
                $savingsStatus = 'warning';
            } else {
                $savingsStatus = 'low';
            }
        } elseif ($actualSaved <= 0) {
            $savingsStatus = 'low';
        }

        // Format top categories per bucket
        arsort($needsBreakdown);
        arsort($wantsBreakdown);
        arsort($savingsBreakdown);

        $formatTop = function (array $items): array {
            $out = [];
            foreach (array_slice($items, 0, 4, true) as $name => $amt) {
                $out[] = ['name' => $name, 'amount' => $amt];
            }
            return $out;
        };

        // 8. Generate Smart Recommendation Message
        $recommendation = $this->buildRecommendation(
            $baseIncome,
            $needsRatio,
            $wantsRatio,
            $savingsRatio,
            $needsSpent,
            $needsLimit,
            $wantsSpent,
            $wantsLimit,
            $actualSaved,
            $savingsTarget
        );

        return [
            'month' => $month,
            'year' => $year,
            'actual_monthly_income' => $actualMonthlyIncome,
            'monthly_income_target' => $incomeTarget,
            'base_income' => $baseIncome,
            'income_source' => $incomeTarget > 0 ? 'target' : 'actual_transactions',
            'total_expense' => $totalAllExpense,
            'ratios' => [
                'needs' => $needsRatio,
                'wants' => $wantsRatio,
                'savings' => $savingsRatio,
            ],
            'needs' => [
                'label' => 'Kebutuhan Pokok',
                'ratio' => $needsRatio,
                'limit' => $needsLimit,
                'spent' => $needsSpent,
                'remaining' => $needsRemaining,
                'usage_percentage' => $needsUsagePct,
                'share_of_income' => $needsShareOfIncome,
                'status' => $needsStatus,
                'top_categories' => $formatTop($needsBreakdown),
            ],
            'wants' => [
                'label' => 'Keinginan & Gaya Hidup',
                'ratio' => $wantsRatio,
                'limit' => $wantsLimit,
                'spent' => $wantsSpent,
                'remaining' => $wantsRemaining,
                'usage_percentage' => $wantsUsagePct,
                'share_of_income' => $wantsShareOfIncome,
                'status' => $wantsStatus,
                'top_categories' => $formatTop($wantsBreakdown),
            ],
            'savings' => [
                'label' => 'Disimpan / Saving',
                'ratio' => $savingsRatio,
                'target' => $savingsTarget,
                'saved' => $actualSaved,
                'explicit_saved' => $explicitSavingsSpent,
                'shortfall' => $savingsShortfall,
                'achievement_percentage' => $savingsAchievementPct,
                'share_of_income' => $savingsShareOfIncome,
                'status' => $savingsStatus,
                'top_categories' => $formatTop($savingsBreakdown),
            ],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Auto-generate or update monthly category budgets based on the user's 50/30/20 allocation.
     */
    public function autoGenerateCategoryBudgets(User $user, float $baseIncome): int
    {
        if ($baseIncome <= 0) {
            return 0;
        }

        $needsRatio = (int) ($user->needs_ratio ?? 50);
        $wantsRatio = (int) ($user->wants_ratio ?? 30);

        $needsPool = round($baseIncome * ($needsRatio / 100), 2);
        $wantsPool = round($baseIncome * ($wantsRatio / 100), 2);

        $rootExpenseCategories = Category::where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->where('type', 'expense')
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->get();

        $needsCats = $rootExpenseCategories->filter(fn ($c) => $c->effective_allocation_group === 'needs')->values();
        $wantsCats = $rootExpenseCategories->filter(fn ($c) => $c->effective_allocation_group === 'wants')->values();

        $startDate = Carbon::now()->startOfMonth()->toDateString();
        $endDate = Carbon::now()->endOfMonth()->toDateString();

        $count = 0;

        // Weighted distribution for Needs categories (Food & Bills get slightly higher weight)
        if ($needsCats->isNotEmpty() && $needsPool > 0) {
            $weights = [];
            foreach ($needsCats as $cat) {
                $n = strtolower($cat->name);
                if (str_contains($n, 'makan')) {
                    $weights[$cat->id] = 35;
                } elseif (str_contains($n, 'tagihan') || str_contains($n, 'listrik')) {
                    $weights[$cat->id] = 25;
                } elseif (str_contains($n, 'transport')) {
                    $weights[$cat->id] = 20;
                } else {
                    $weights[$cat->id] = 10;
                }
            }
            $totalWeight = array_sum($weights);
            foreach ($needsCats as $cat) {
                $limit = round(($needsPool * ($weights[$cat->id] / $totalWeight)) / 1000) * 1000;
                if ($limit >= 10000) {
                    Budget::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'category_id' => $cat->id,
                            'period' => 'monthly',
                        ],
                        [
                            'amount_limit' => $limit,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ]
                    );
                    $count++;
                }
            }
        }

        // Distribution for Wants categories
        if ($wantsCats->isNotEmpty() && $wantsPool > 0) {
            $perCat = round(($wantsPool / $wantsCats->count()) / 1000) * 1000;
            foreach ($wantsCats as $cat) {
                if ($perCat >= 10000) {
                    Budget::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'category_id' => $cat->id,
                            'period' => 'monthly',
                        ],
                        [
                            'amount_limit' => $perCat,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ]
                    );
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function buildRecommendation(
        float $baseIncome,
        int $needsRatio,
        int $wantsRatio,
        int $savingsRatio,
        float $needsSpent,
        float $needsLimit,
        float $wantsSpent,
        float $wantsLimit,
        float $actualSaved,
        float $savingsTarget
    ): string {
        if ($baseIncome <= 0) {
            return 'Tetapkan Target Pemasukan Bulanan atau catat transaksi pemasukan untuk menghitung kuota Rp 50% Kebutuhan Pokok, 30% Keinginan, dan 20% Saving secara otomatis.';
        }

        $warnings = [];
        if ($needsSpent > $needsLimit) {
            $over = number_format($needsSpent - $needsLimit, 0, ',', '.');
            $warnings[] = "Pos Kebutuhan Pokok ({$needsRatio}%) melebihi jatah sebesar Rp {$over}.";
        }
        if ($wantsSpent > $wantsLimit) {
            $over = number_format($wantsSpent - $wantsLimit, 0, ',', '.');
            $warnings[] = "Pos Keinginan ({$wantsRatio}%) melebihi batas sebesar Rp {$over}. Kurangi pengeluaran gaya hidup/nongkrong.";
        }

        if (!empty($warnings)) {
            return implode(' ', $warnings);
        }

        if ($actualSaved >= $savingsTarget) {
            $savedFmt = number_format($actualSaved, 0, ',', '.');
            return "Luar biasa! Pengeluaran Kebutuhan ({$needsRatio}%) & Keinginan ({$wantsRatio}%) Anda terkendali, dan porsi Saving telah mencapai Rp {$savedFmt}.";
        }

        return "Alokasi pengeluaran Anda masih dalam batas aman aturan {$needsRatio}/{$wantsRatio}/{$savingsRatio}.";
    }
}

