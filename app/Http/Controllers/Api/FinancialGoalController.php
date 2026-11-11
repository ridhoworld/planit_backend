<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialGoal;
use App\Models\Notification;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialGoalController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    /**
     * Display a listing of financial goals with calculated metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()->financialGoals()->orderBy('created_at', 'desc')->get();

        $goalsWithMetrics = $goals->map(function ($goal) {
            $target = (float) $goal->target_amount;
            $current = (float) $goal->current_amount;
            $remaining = max(0, $target - $current);
            $percentage = $target > 0 ? min(100, round(($current / $target) * 100, 1)) : 0;

            $suggestedMonthly = 0.0;
            if ($remaining > 0 && $goal->deadline) {
                $now = Carbon::now();
                $deadline = Carbon::parse($goal->deadline);
                $monthsLeft = max(1, $now->diffInMonths($deadline, false));
                if ($monthsLeft > 0) {
                    $suggestedMonthly = round($remaining / $monthsLeft, 0);
                }
            }

            return [
                'id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => $target,
                'current_amount' => $current,
                'remaining_amount' => $remaining,
                'percentage' => $percentage,
                'suggested_monthly' => $suggestedMonthly,
                'deadline' => $goal->deadline ? $goal->deadline->toDateString() : null,
                'status' => $goal->status,
                'color' => $goal->color,
                'icon' => $goal->icon,
                'created_at' => $goal->created_at,
            ];
        });

        $totalTarget = $goalsWithMetrics->sum('target_amount');
        $totalSaved = $goalsWithMetrics->sum('current_amount');

        return response()->json([
            'goals' => $goalsWithMetrics,
            'total_target' => $totalTarget,
            'total_saved' => $totalSaved,
        ]);
    }

    /**
     * Store a newly created financial goal.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'target_amount' => 'required|numeric|min:1000',
            'current_amount' => 'nullable|numeric|min:0',
            'deadline' => 'nullable|date',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['current_amount'] = $validated['current_amount'] ?? 0;
        $validated['status'] = $validated['current_amount'] >= $validated['target_amount'] ? 'completed' : 'in_progress';

        $goal = FinancialGoal::create($validated);

        return response()->json([
            'message' => 'Tujuan keuangan berhasil dibuat',
            'goal' => $goal,
        ], 201);
    }

    /**
     * Update the specified financial goal.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $goal = $request->user()->financialGoals()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'target_amount' => 'sometimes|required|numeric|min:1000',
            'current_amount' => 'sometimes|numeric|min:0',
            'deadline' => 'nullable|date',
            'status' => 'sometimes|in:in_progress,completed,paused',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
        ]);

        if (isset($validated['current_amount']) && isset($validated['target_amount'])) {
            if ($validated['current_amount'] >= $validated['target_amount']) {
                $validated['status'] = 'completed';
            }
        }

        $goal->update($validated);

        return response()->json([
            'message' => 'Tujuan keuangan berhasil diperbarui',
            'goal' => $goal,
        ]);
    }

    /**
     * Deposit savings into a financial goal.
     */
    public function deposit(Request $request, int $id): JsonResponse
    {
        $goal = $request->user()->financialGoals()->findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'account_id' => 'nullable|exists:accounts,id', // optional: deduct from an account
        ]);

        $amount = (float) $validated['amount'];
        $goal->current_amount += $amount;

        // BR-8: Auto complete
        if ($goal->current_amount >= $goal->target_amount) {
            $goal->status = 'completed';

            Notification::create([
                'user_id' => $request->user()->id,
                'title' => '🎉 Selamat! Target Keuangan Tercapai',
                'message' => "Tujuan '{$goal->name}' berhasil mencapai target Rp " . number_format($goal->target_amount, 0, ',', '.') . "!",
                'type' => 'goal_reached',
                'data' => ['goal_id' => $goal->id],
            ]);
        }

        $goal->save();

        // If account_id provided, deduct from account as expense / transfer
        if (!empty($validated['account_id'])) {
            try {
                $this->transactionService->createTransaction($request->user(), [
                    'account_id' => $validated['account_id'],
                    'type' => 'expense',
                    'amount' => $amount,
                    'transaction_date' => Carbon::now(),
                    'notes' => 'Setoran Tabungan: ' . $goal->name,
                ]);
            } catch (\Throwable $e) {
                // Keep deposit
            }
        }

        return response()->json([
            'message' => 'Setoran berhasil ditambahkan ke ' . $goal->name,
            'goal' => $goal,
        ]);
    }

    /**
     * Remove the specified financial goal.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $goal = $request->user()->financialGoals()->findOrFail($id);
        $goal->delete();

        return response()->json([
            'message' => 'Tujuan keuangan berhasil dihapus',
        ]);
    }
}

