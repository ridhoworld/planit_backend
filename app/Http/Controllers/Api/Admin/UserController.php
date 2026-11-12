<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List all users with search and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->withCount(['accounts', 'transactions']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $perPage = (int) $request->get('per_page', 15);
        $users = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Create a new user (Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'user'])],
            'primary_currency' => 'nullable|string|max:10',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'primary_currency' => $validated['primary_currency'] ?? 'IDR',
        ]);

        return response()->json([
            'message' => 'Pengguna baru berhasil ditambahkan oleh Administrator',
            'user' => $user,
        ], 201);
    }

    /**
     * Show detailed user info and data summary.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::withCount([
            'accounts',
            'transactions',
            'budgets',
            'financialGoals',
            'debts',
        ])->findOrFail($id);

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $currentUser = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'role' => ['sometimes', Rule::in(['admin', 'user'])],
            'primary_currency' => 'nullable|string|max:10',
        ]);

        // Safety: Admin cannot demote self if only 1 admin remains
        if (isset($validated['role']) && $validated['role'] === 'user' && $user->id === $currentUser->id) {
            $adminCount = User::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Tidak dapat mengubah role Anda sendiri karena Anda adalah satu-satunya Administrator.',
                ], 422);
            }
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Data pengguna berhasil diperbarui',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $currentUser = $request->user();

        if ($user->id === $currentUser->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menghapus akun Administrator Anda sendiri.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'Pengguna berhasil dihapus dari sistem.',
        ]);
    }
}

