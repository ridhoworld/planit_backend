<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List categories with scope filter: 'all', 'master' (user_id is null), or 'custom' (user_id is not null).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::with(['parent', 'user:id,name,email']);

        $scope = $request->query('scope', 'all');
        if ($scope === 'master') {
            $query->whereNull('user_id');
        } elseif ($scope === 'custom') {
            $query->whereNotNull('user_id');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $categories = $query->orderBy('type')->orderBy('name')->get();

        $masterCount = Category::whereNull('user_id')->count();
        $customCount = Category::whereNotNull('user_id')->count();

        return response()->json([
            'categories' => $categories,
            'total' => $categories->count(),
            'master_count' => $masterCount,
            'custom_count' => $customCount,
        ]);
    }

    /**
     * Store a new master category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:income,expense',
            'parent_id' => 'nullable|exists:categories,id',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
        ]);

        $category = Category::create([
            'user_id' => null, // Master category for all users
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'icon' => $validated['icon'] ?? 'category',
            'color' => $validated['color'] ?? '#3B82F6',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Kategori master berhasil ditambahkan',
            'category' => $category->load('parent'),
        ], 201);
    }

    /**
     * Promote a user's custom category to become a master category.
     */
    public function promoteToMaster(int $id): JsonResponse
    {
        $category = Category::with('user')->findOrFail($id);
        $oldUserName = $category->user ? $category->user->name : 'Pengguna';
        $category->user_id = null;
        $category->save();

        return response()->json([
            'message' => "Kategori \"{$category->name}\" dari {$oldUserName} berhasil diubah menjadi Kategori Master Sistem!",
            'category' => $category->fresh()->load('parent'),
        ]);
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:income,expense',
            'parent_id' => 'nullable|exists:categories,id',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Kategori berhasil diperbarui',
            'category' => $category->fresh()->load('parent'),
        ]);
    }

    /**
     * Delete a category.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus',
        ]);
    }
}
