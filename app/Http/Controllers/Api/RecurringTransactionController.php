<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecurringTransaction;
use App\Services\TransactionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringTransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    /**
     * Display a listing of recurring bills and upcoming 30 days bills.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $recurring = RecurringTransaction::where('user_id', $userId)
            ->with(['account', 'category'])
            ->orderBy('next_due_date', 'asc')
            ->get();

        $today = Carbon::now()->startOfDay();
        $in30Days = Carbon::now()->addDays(30)->endOfDay();

        $upcoming = $recurring->where('is_active', true)
            ->where('next_due_date', '<=', $in30Days->toDateString())
            ->values();

        return response()->json([
            'recurring' => $recurring,
            'upcoming_bills' => $upcoming,
        ]);
    }

    /**
     * Store a newly created recurring transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'category_id' => 'nullable|exists:categories,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:expense,income',
            'frequency' => 'required|in:daily,weekly,monthly,yearly',
            'next_due_date' => 'required|date',
            'auto_create' => 'nullable|boolean',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['is_active'] = true;
        $validated['auto_create'] = $validated['auto_create'] ?? false;

        $recurring = RecurringTransaction::create($validated);

        return response()->json([
            'message' => 'Tagihan rutin berhasil ditambahkan',
            'recurring' => $recurring->load(['account', 'category']),
        ], 201);
    }

    /**
     * Update the specified recurring transaction.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);

        $validated = $request->validate([
            'account_id' => 'sometimes|required|exists:accounts,id',
            'category_id' => 'nullable|exists:categories,id',
            'title' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'type' => 'sometimes|required|in:expense,income',
            'frequency' => 'sometimes|required|in:daily,weekly,monthly,yearly',
            'next_due_date' => 'sometimes|required|date',
            'auto_create' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $recurring->update($validated);

        return response()->json([
            'message' => 'Tagihan rutin berhasil diperbarui',
            'recurring' => $recurring->load(['account', 'category']),
        ]);
    }

    /**
     * Remove the specified recurring transaction.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);
        $recurring->delete();

        return response()->json([
            'message' => 'Tagihan rutin berhasil dihapus',
        ]);
    }

    /**
     * Execute recurring bill: create the actual transaction and advance next_due_date.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);

        try {
            // Create actual transaction
            $transaction = $this->transactionService->createTransaction($request->user(), [
                'account_id' => $recurring->account_id,
                'category_id' => $recurring->category_id,
                'type' => $recurring->type,
                'amount' => $recurring->amount,
                'transaction_date' => Carbon::now(),
                'notes' => 'Pembayaran Rutin: ' . $recurring->title,
            ]);

            // Advance next_due_date
            $dueDate = Carbon::parse($recurring->next_due_date);
            switch ($recurring->frequency) {
                case 'daily':
                    $nextDate = $dueDate->addDay();
                    break;
                case 'weekly':
                    $nextDate = $dueDate->addWeek();
                    break;
                case 'yearly':
                    $nextDate = $dueDate->addYear();
                    break;
                case 'monthly':
                default:
                    $nextDate = $dueDate->addMonth();
                    break;
            }

            $recurring->next_due_date = $nextDate->toDateString();
            $recurring->save();

            return response()->json([
                'message' => 'Tagihan berhasil dibayar & dicatat dalam transaksi',
                'transaction' => $transaction,
                'recurring' => $recurring,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

