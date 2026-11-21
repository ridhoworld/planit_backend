<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AllocationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    protected AllocationService $allocationService;

    public function __construct(AllocationService $allocationService)
    {
        $this->allocationService = $allocationService;
    }

    /**
     * Display budgets with real-time spent, progress calculation, and 50/30/20 allocation summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $budgets = Budget::where('user_id', $userId)->with('category')->get();

        $budgetData = $budgets->map(function ($budget) use ($userId) {
            // Include sub-categories of this category
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
            $remaining = max(0, $limit - $spent);
            $percentage = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0;

            $status = 'safe'; // < 80%
            if ($percentage >= 100) {
                $status = 'exceeded';
            } elseif ($percentage >= 80) {
                $status = 'warning';
            }

            return [
                'id' => $budget->id,
                'category_id' => $budget->category_id,
                'category' => $budget->category,
                'amount_limit' => $limit,
                'spent_amount' => $spent,
                'remaining_amount' => $remaining,
                'percentage' => $percentage,
                'status' => $status,
                'period' => $budget->period,
                'start_date' => $budget->start_date->toDateString(),
                'end_date' => $budget->end_date->toDateString(),
                'created_at' => $budget->created_at,
            ];
        });

        $totalBudgetLimit = $budgetData->sum('amount_limit');
        $totalSpent = $budgetData->sum('spent_amount');
        $allocation = $this->allocationService->calculateMonthlyAllocation($user);

        return response()->json([
            'budgets' => $budgetData,
            'total_limit' => $totalBudgetLimit,
            'total_spent' => $totalSpent,
            'allocation_50_30_20' => $allocation,
        ]);
    }

    /**
     * Update user's 50/30/20 allocation preferences (monthly income baseline, custom ratios, and optional auto-budget generation).
     */
    public function updateAllocationSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'monthly_income_target' => 'nullable|numeric|min:0',
            'needs_ratio' => 'nullable|integer|min:10|max:80',
            'wants_ratio' => 'nullable|integer|min:5|max:70',
            'savings_ratio' => 'nullable|integer|min:5|max:70',
            'auto_generate_budgets' => 'nullable|boolean',
        ]);

        $user = $request->user();

        $needs = $validated['needs_ratio'] ?? ($user->needs_ratio ?? 50);
        $wants = $validated['wants_ratio'] ?? ($user->wants_ratio ?? 30);
        $savings = $validated['savings_ratio'] ?? ($user->savings_ratio ?? 20);

        if (($needs + $wants + $savings) !== 100) {
            return response()->json([
                'message' => 'Total persentase Kebutuhan + Keinginan + Saving harus berjumlah tepat 100%.',
            ], 422);
        }

        $user->update([
            'monthly_income_target' => array_key_exists('monthly_income_target', $validated)
                ? ($validated['monthly_income_target'] ?? 0)
                : ($user->monthly_income_target ?? 0),
            'needs_ratio' => $needs,
            'wants_ratio' => $wants,
            'savings_ratio' => $savings,
        ]);

        $allocation = $this->allocationService->calculateMonthlyAllocation($user->fresh());
        $generatedCount = 0;

        if (!empty($validated['auto_generate_budgets'])) {
            $baseIncome = (float) ($allocation['base_income'] ?? 0);
            if ($baseIncome <= 0) {
                return response()->json([
                    'message' => 'Masukkan Target Pemasukan Bulanan atau catat pemasukan terlebih dahulu untuk membagi anggaran kategori otomatis.',
                ], 422);
            }
            $generatedCount = $this->allocationService->autoGenerateCategoryBudgets($user->fresh(), $baseIncome);
        }

        return response()->json([
            'message' => $generatedCount > 0
                ? "Alokasi {$needs}/{$wants}/{$savings} disimpan & {$generatedCount} anggaran kategori berhasil dibuat otomatis!"
                : "Pengaturan alokasi {$needs}/{$wants}/{$savings} berhasil diperbarui.",
            'allocation_50_30_20' => $this->allocationService->calculateMonthlyAllocation($user->fresh()),
            'generated_budgets_count' => $generatedCount,
        ]);
    }

    /**
     * Store a newly created budget.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'amount_limit' => 'required|numeric|min:1000',
            'period' => 'required|in:weekly,monthly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $validated['user_id'] = $request->user()->id;

        // BR-9: Check for overlapping period on the same category
        $overlap = Budget::where('user_id', $request->user()->id)
            ->where('category_id', $validated['category_id'])
            ->where(function ($query) use ($validated) {
                $query->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                      ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']]);
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'Anggaran untuk kategori ini sudah ada pada rentang tanggal yang dipilih.',
            ], 422);
        }

        $budget = Budget::create($validated);

        return response()->json([
            'message' => 'Anggaran berhasil dibuat',
            'budget' => $budget->load('category'),
        ], 201);
    }

    /**
     * Update the specified budget.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $budget = $request->user()->budgets()->findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'sometimes|required|exists:categories,id',
            'amount_limit' => 'sometimes|required|numeric|min:1000',
            'period' => 'sometimes|required|in:weekly,monthly,yearly',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
        ]);

        $budget->update($validated);

        return response()->json([
            'message' => 'Anggaran berhasil diperbarui',
            'budget' => $budget->load('category'),
        ]);
    }

    /**
     * Remove the specified budget.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $budget = $request->user()->budgets()->findOrFail($id);
        $budget->delete();

        return response()->json([
            'message' => 'Anggaran berhasil dihapus',
        ]);
    }
}

