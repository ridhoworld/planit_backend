<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get system-wide administration statistics and metrics.
     */
    public function stats(Request $request): JsonResponse
    {
        $totalUsers = User::count();
        $adminCount = User::where('role', 'admin')->count();
        $regularUserCount = User::where('role', 'user')->count();

        $totalAccounts = Account::count();
        $totalTransactions = Transaction::count();
        $totalVolume = (float) Transaction::sum('amount');

        $recentUsers = User::orderBy('id', 'desc')->take(5)->get(['id', 'name', 'email', 'role', 'created_at']);

        $recentTransactions = Transaction::with(['user:id,name,email', 'category:id,name', 'account:id,name'])
            ->orderBy('id', 'desc')
            ->take(8)
            ->get();

        $totalCategories = Category::whereNull('user_id')->count();

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'admin_count' => $adminCount,
                'user_count' => $regularUserCount,
                'total_accounts' => $totalAccounts,
                'total_transactions' => $totalTransactions,
                'total_volume' => $totalVolume,
                'master_categories_count' => $totalCategories,
            ],
            'recent_users' => $recentUsers,
            'recent_transactions' => $recentTransactions,
        ]);
    }
}

