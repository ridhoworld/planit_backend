<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\TransactionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    /**
     * Display a listing of user transactions with search and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->transactions()
            ->with(['account', 'targetAccount', 'category.parent', 'tags'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date . ' 00:00:00');
        }

        if ($request->filled('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date . ' 23:59:59');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('account_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('account_id', $request->account_id)
                  ->orWhere('target_account_id', $request->account_id);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', function ($q) use ($request) {
                $q->where('tags.id', $request->tag_id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                  ->orWhereHas('category', function ($sub) use ($search) {
                      $sub->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Store a newly created transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'target_account_id' => 'nullable|exists:accounts,id',
            'category_id' => 'nullable|exists:categories,id',
            'type' => 'required|in:income,expense,transfer',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'notes' => 'nullable|string',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'receipt' => 'nullable|image|max:10240', // 10MB
        ]);

        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store('receipts', 'public');
            $validated['receipt_path'] = $path;
        }

        try {
            $transaction = $this->transactionService->createTransaction($request->user(), $validated);

            return response()->json([
                'message' => 'Transaksi berhasil dicatat',
                'transaction' => $transaction,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()
            ->with(['account', 'targetAccount', 'category.parent', 'tags'])
            ->findOrFail($id);

        return response()->json([
            'transaction' => $transaction,
        ]);
    }

    /**
     * Update the specified transaction.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        $validated = $request->validate([
            'account_id' => 'sometimes|required|exists:accounts,id',
            'target_account_id' => 'nullable|exists:accounts,id',
            'category_id' => 'nullable|exists:categories,id',
            'type' => 'sometimes|required|in:income,expense,transfer',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'transaction_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'receipt' => 'nullable|image|max:10240',
        ]);

        if ($request->hasFile('receipt')) {
            if ($transaction->receipt_path) {
                Storage::disk('public')->delete($transaction->receipt_path);
            }
            $validated['receipt_path'] = $request->file('receipt')->store('receipts', 'public');
        }

        try {
            $updated = $this->transactionService->updateTransaction($transaction, $validated);

            return response()->json([
                'message' => 'Transaksi berhasil diperbarui',
                'transaction' => $updated,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove the specified transaction.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        try {
            $this->transactionService->deleteTransaction($transaction);

            return response()->json([
                'message' => 'Transaksi berhasil dihapus dan saldo dipulihkan',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Upload a receipt for an existing transaction.
     */
    public function uploadReceipt(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'receipt' => 'required|image|max:10240',
        ]);

        $transaction = $request->user()->transactions()->findOrFail($id);

        if ($transaction->receipt_path) {
            Storage::disk('public')->delete($transaction->receipt_path);
        }

        $path = $request->file('receipt')->store('receipts', 'public');
        $transaction->update(['receipt_path' => $path]);

        return response()->json([
            'message' => 'Struk berhasil diunggah',
            'receipt_path' => $path,
            'receipt_url' => asset('storage/' . $path),
        ]);
    }
}

