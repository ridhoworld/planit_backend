<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Services\TransactionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    /**
     * Display debts with summary totals.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $debts = Debt::where('user_id', $userId)
            ->with('payments')
            ->orderBy('remaining_amount', 'desc')
            ->orderBy('due_date', 'asc')
            ->get();

        $payables = $debts->where('type', 'payable')->values();
        $receivables = $debts->where('type', 'receivable')->values();

        $totalPayable = (float) $payables->sum('remaining_amount');
        $totalReceivable = (float) $receivables->sum('remaining_amount');
        $netReceivable = $totalReceivable - $totalPayable;

        return response()->json([
            'debts' => $debts,
            'payables' => $payables,
            'receivables' => $receivables,
            'total_payable' => $totalPayable,
            'total_receivable' => $totalReceivable,
            'net_receivable' => $netReceivable,
        ]);
    }

    /**
     * Store a newly created debt or receivable.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:payable,receivable',
            'party_name' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:1',
            'remaining_amount' => 'nullable|numeric|min:0',
            'interest_rate' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['remaining_amount'] = $validated['remaining_amount'] ?? $validated['total_amount'];

        // BR-7: remaining_amount cannot exceed total_amount
        if ($validated['remaining_amount'] > $validated['total_amount']) {
            return response()->json([
                'message' => 'Sisa utang tidak boleh melebihi total utang awal.',
            ], 422);
        }

        $debt = Debt::create($validated);

        return response()->json([
            'message' => 'Catatan utang/piutang berhasil dibuat',
            'debt' => $debt,
        ], 201);
    }

    /**
     * Display the specified debt with payments.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $debt = $request->user()->debts()->with('payments.account')->findOrFail($id);

        return response()->json([
            'debt' => $debt,
        ]);
    }

    /**
     * Update the specified debt.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $debt = $request->user()->debts()->findOrFail($id);

        $validated = $request->validate([
            'party_name' => 'sometimes|required|string|max:255',
            'total_amount' => 'sometimes|required|numeric|min:1',
            'remaining_amount' => 'sometimes|numeric|min:0',
            'interest_rate' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $debt->update($validated);

        return response()->json([
            'message' => 'Data utang/piutang berhasil diperbarui',
            'debt' => $debt,
        ]);
    }

    /**
     * Record a payment for a debt/receivable.
     */
    public function addPayment(Request $request, int $id): JsonResponse
    {
        $debt = $request->user()->debts()->findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'account_id' => 'nullable|exists:accounts,id',
            'notes' => 'nullable|string',
        ]);

        $payAmount = (float) $validated['amount'];

        if ($payAmount > (float) $debt->remaining_amount) {
            return response()->json([
                'message' => 'Nominal pembayaran melebihi sisa utang/piutang.',
            ], 422);
        }

        return DB::transaction(function () use ($debt, $validated, $payAmount, $request) {
            $payment = DebtPayment::create([
                'debt_id' => $debt->id,
                'account_id' => $validated['account_id'] ?? null,
                'amount' => $payAmount,
                'payment_date' => $validated['payment_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $debt->remaining_amount = max(0, (float) $debt->remaining_amount - $payAmount);
            $debt->save();

            // Optionally create a transaction in the selected account
            if (!empty($validated['account_id'])) {
                $type = $debt->type === 'payable' ? 'expense' : 'income'; // Paying our debt = expense, receiving piutang = income
                try {
                    $this->transactionService->createTransaction($request->user(), [
                        'account_id' => $validated['account_id'],
                        'type' => $type,
                        'amount' => $payAmount,
                        'transaction_date' => $validated['payment_date'],
                        'notes' => ($debt->type === 'payable' ? 'Pembayaran Utang: ' : 'Penerimaan Piutang: ') . $debt->party_name,
                    ]);
                } catch (Exception $e) {
                    // Log or handle
                }
            }

            return response()->json([
                'message' => 'Pembayaran berhasil dicatat',
                'debt' => $debt->fresh('payments'),
                'payment' => $payment,
            ]);
        });
    }

    /**
     * Remove the specified debt.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $debt = $request->user()->debts()->findOrFail($id);
        $debt->delete();

        return response()->json([
            'message' => 'Catatan utang/piutang berhasil dihapus',
        ]);
    }
}

