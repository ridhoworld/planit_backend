<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List all master categories (user_id is null).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::whereNull('user_id')->with('parent');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $categories = $query->orderBy('type')->orderBy('name')->get();

        return response()->json([
            'categories' => $categories,
            'total' => $categories->count(),
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
     * Update an existing master category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::whereNull('user_id')->findOrFail($id);

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
            'message' => 'Kategori master berhasil diperbarui',
            'category' => $category->fresh()->load('parent'),
        ]);
    }

    /**
     * Delete a master category.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::whereNull('user_id')->findOrFail($id);
        $category->delete();

        return response()->json([
            'message' => 'Kategori master berhasil dihapus',
        ]);
    }
}

