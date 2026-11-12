<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Display global audit listing of all transactions across all users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with(['user:id,name,email', 'account:id,name', 'targetAccount:id,name', 'category:id,name']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $transactions = $query->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }
}

