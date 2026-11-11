<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Create a new transaction with atomic balance updates and budget checks.
     */
    public function createTransaction(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $type = $data['type'];
            $amount = (float) $data['amount'];
            $accountId = $data['account_id'];
            $targetAccountId = $data['target_account_id'] ?? null;
            $categoryId = $data['category_id'] ?? null;

            // BR-5: Account must be active
            $account = Account::where('id', $accountId)->where('user_id', $user->id)->firstOrFail();
            if (!$account->is_active) {
                throw new Exception('Akun sumber tidak aktif.');
            }

            // BR-1: Transfer target validation
            if ($type === 'transfer') {
                if (!$targetAccountId || $targetAccountId == $accountId) {
                    throw new Exception('Akun tujuan transfer tidak valid.');
                }
                $targetAccount = Account::where('id', $targetAccountId)->where('user_id', $user->id)->firstOrFail();
                if (!$targetAccount->is_active) {
                    throw new Exception('Akun tujuan transfer tidak aktif.');
                }
                $categoryId = null; // BR-2
            } else {
                $targetAccountId = null;
                if (!$categoryId) {
                    throw new Exception('Kategori wajib diisi untuk transaksi pemasukan/pengeluaran.');
                }
            }

            // Apply balance updates (BR-6)
            if ($type === 'expense') {
                $account->current_balance -= $amount;
            } elseif ($type === 'income') {
                $account->current_balance += $amount;
            } elseif ($type === 'transfer') {
                $account->current_balance -= $amount;
                $targetAccount->current_balance += $amount;
                $targetAccount->save();
            }
            $account->save();

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'account_id' => $accountId,
                'target_account_id' => $targetAccountId,
                'category_id' => $categoryId,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'] ?? Carbon::now(),
                'notes' => $data['notes'] ?? null,
                'receipt_path' => $data['receipt_path'] ?? null,
            ]);

            // Sync tags
            if (!empty($data['tag_ids'])) {
                $transaction->tags()->sync($data['tag_ids']);
            }

            // Check budget warnings for expense
            if ($type === 'expense' && $categoryId) {
                $this->checkBudgetAlerts($user, $categoryId, $amount);
            }

            return $transaction->load(['account', 'targetAccount', 'category', 'tags']);
        });
    }

    /**
     * Update an existing transaction atomically.
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $user = $transaction->user;

            // Revert previous balance impact
            $this->revertTransactionBalance($transaction);

            // Apply new parameters
            $type = $data['type'] ?? $transaction->type;
            $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $transaction->amount;
            $accountId = $data['account_id'] ?? $transaction->account_id;
            $targetAccountId = $data['target_account_id'] ?? ($type === 'transfer' ? $transaction->target_account_id : null);
            $categoryId = $type === 'transfer' ? null : ($data['category_id'] ?? $transaction->category_id);

            $account = Account::where('id', $accountId)->where('user_id', $user->id)->firstOrFail();
            if (!$account->is_active) {
                throw new Exception('Akun tidak aktif.');
            }

            if ($type === 'transfer') {
                if (!$targetAccountId || $targetAccountId == $accountId) {
                    throw new Exception('Akun tujuan transfer tidak valid.');
                }
                $targetAccount = Account::where('id', $targetAccountId)->where('user_id', $user->id)->firstOrFail();
                if (!$targetAccount->is_active) {
                    throw new Exception('Akun tujuan tidak aktif.');
                }

                $account->current_balance -= $amount;
                $targetAccount->current_balance += $amount;
                $targetAccount->save();
            } elseif ($type === 'expense') {
                $account->current_balance -= $amount;
            } elseif ($type === 'income') {
                $account->current_balance += $amount;
            }
            $account->save();

            $transaction->update([
                'account_id' => $accountId,
                'target_account_id' => $targetAccountId,
                'category_id' => $categoryId,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
                'notes' => $data['notes'] ?? $transaction->notes,
                'receipt_path' => $data['receipt_path'] ?? $transaction->receipt_path,
            ]);

            if (isset($data['tag_ids'])) {
                $transaction->tags()->sync($data['tag_ids']);
            }

            return $transaction->fresh(['account', 'targetAccount', 'category', 'tags']);
        });
    }

    /**
     * Delete transaction and revert balance.
     */
    public function deleteTransaction(Transaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction) {
            $this->revertTransactionBalance($transaction);
            return $transaction->delete();
        });
    }

    /**
     * Revert the balance effect of a transaction.
     */
    protected function revertTransactionBalance(Transaction $transaction): void
    {
        $account = Account::find($transaction->account_id);
        if (!$account) return;

        $amount = (float) $transaction->amount;

        if ($transaction->type === 'expense') {
            $account->current_balance += $amount;
            $account->save();
        } elseif ($transaction->type === 'income') {
            $account->current_balance -= $amount;
            $account->save();
        } elseif ($transaction->type === 'transfer' && $transaction->target_account_id) {
            $account->current_balance += $amount;
            $account->save();

            $targetAccount = Account::find($transaction->target_account_id);
            if ($targetAccount) {
                $targetAccount->current_balance -= $amount;
                $targetAccount->save();
            }
        }
    }

    /**
     * Check budget alerts (80% and 100%).
     */
    protected function checkBudgetAlerts(User $user, int $categoryId, float $amount): void
    {
        $category = Category::find($categoryId);
        if (!$category) return;

        // Check if there's a budget for this category or its parent
        $categoryIds = [$categoryId];
        if ($category->parent_id) {
            $categoryIds[] = $category->parent_id;
        }

        $now = Carbon::now();
        $budgets = Budget::where('user_id', $user->id)
            ->whereIn('category_id', $categoryIds)
            ->where('start_date', '<=', $now->toDateString())
            ->where('end_date', '>=', $now->toDateString())
            ->get();

        foreach ($budgets as $budget) {
            // Calculate total spent for this budget's category (and subcategories)
            $subCategoryIds = Category::where('parent_id', $budget->category_id)->pluck('id')->toArray();
            $allCategoryIds = array_merge([$budget->category_id], $subCategoryIds);

            $spent = (float) Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereIn('category_id', $allCategoryIds)
                ->whereBetween('transaction_date', [$budget->start_date . ' 00:00:00', $budget->end_date . ' 23:59:59'])
                ->sum('amount');

            $limit = (float) $budget->amount_limit;
            if ($limit <= 0) continue;

            $ratio = $spent / $limit;
            $categoryName = $budget->category->name ?? 'Kategori';

            if ($ratio >= 1.0) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Peringatan Anggaran!',
                    'message' => "Anggaran untuk {$categoryName} telah mencapai 100%! Terpakai: Rp " . number_format($spent, 0, ',', '.') . " dari limit Rp " . number_format($limit, 0, ',', '.'),
                    'type' => 'budget_alert_100',
                    'data' => ['budget_id' => $budget->id, 'spent' => $spent, 'limit' => $limit],
                ]);
            } elseif ($ratio >= 0.8) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Perhatian Anggaran',
                    'message' => "Anggaran untuk {$categoryName} sudah mencapai 80%. Terpakai: Rp " . number_format($spent, 0, ',', '.'),
                    'type' => 'budget_alert_80',
                    'data' => ['budget_id' => $budget->id, 'spent' => $spent, 'limit' => $limit],
                ]);
            }
        }
    }
}

