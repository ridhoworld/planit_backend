<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    /**
     * Display a listing of user accounts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->accounts();

        if ($request->has('active_only') && filter_var($request->active_only, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_active', true);
        }

        $accounts = $query->orderBy('is_active', 'desc')->orderBy('name', 'asc')->get();

        $totalBalance = (float) $request->user()->accounts()->where('is_active', true)->where('type', '!=', 'credit_card')->sum('current_balance');
        $totalCreditDebt = (float) $request->user()->accounts()->where('is_active', true)->where('type', 'credit_card')->sum('current_balance');

        return response()->json([
            'accounts' => $accounts,
            'total_balance' => $totalBalance,
            'total_credit_debt' => $totalCreditDebt,
        ]);
    }

    /**
     * Store a newly created account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:bank,e-wallet,cash,credit_card,investment',
            'initial_balance' => 'required|numeric|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['current_balance'] = $validated['initial_balance'];
        $validated['is_active'] = true;

        if ($validated['type'] !== 'credit_card') {
            $validated['credit_limit'] = null;
        }

        $account = Account::create($validated);

        return response()->json([
            'message' => 'Akun berhasil dibuat',
            'account' => $account,
        ], 201);
    }

    /**
     * Display the specified account.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $request->user()->accounts()->findOrFail($id);

        return response()->json([
            'account' => $account,
        ]);
    }

    /**
     * Update the specified account.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = $request->user()->accounts()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:bank,e-wallet,cash,credit_card,investment',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        $account->update($validated);

        return response()->json([
            'message' => 'Akun berhasil diperbarui',
            'account' => $account,
        ]);
    }

    /**
     * Delete or archive the specified account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $request->user()->accounts()->findOrFail($id);

        // Check if account has transactions
        $hasTransactions = $account->transactions()->exists() || $account->incomingTransfers()->exists();

        if ($hasTransactions) {
            // Archive account rather than hard deleting to preserve transaction history (BR-5)
            $account->update(['is_active' => false]);
            return response()->json([
                'message' => 'Akun diarsipkan karena memiliki riwayat transaksi.',
                'account' => $account,
            ]);
        }

        $account->delete();

        return response()->json([
            'message' => 'Akun berhasil dihapus.',
        ]);
    }

    /**
     * Adjust account balance (Penyesuaian Saldo).
     */
    public function adjustBalance(Request $request, int $id): JsonResponse
    {
        $account = $request->user()->accounts()->findOrFail($id);

        $validated = $request->validate([
            'new_balance' => 'required|numeric',
            'notes' => 'nullable|string|max:255',
        ]);

        $difference = (float) $validated['new_balance'] - (float) $account->current_balance;

        DB::transaction(function () use ($account, $validated, $difference, $request) {
            $account->current_balance = (float) $validated['new_balance'];
            $account->save();

            // Record adjustment as a transaction for audit trail
            if (abs($difference) > 0.01) {
                Transaction::create([
                    'user_id' => $request->user()->id,
                    'account_id' => $account->id,
                    'type' => $difference > 0 ? 'income' : 'expense',
                    'amount' => abs($difference),
                    'transaction_date' => Carbon::now(),
                    'notes' => 'Penyesuaian Saldo: ' . ($validated['notes'] ?? 'Koreksi manual'),
                ]);
            }
        });

        return response()->json([
            'message' => 'Saldo berhasil disesuaikan.',
            'account' => $account->fresh(),
        ]);
    }
}

