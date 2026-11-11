<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of system and user categories.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $categories = Category::where(function ($query) use ($userId) {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => function ($q) use ($userId) {
                $q->where(function ($subQ) use ($userId) {
                    $subQ->whereNull('user_id')->orWhere('user_id', $userId);
                })->where('is_active', true);
            }])
            ->orderBy('name')
            ->get();

        $income = $categories->where('type', 'income')->values();
        $expense = $categories->where('type', 'expense')->values();

        // Also flat list for easy dropdowns
        $flat = Category::where(function ($query) use ($userId) {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories,
            'income' => $income,
            'expense' => $expense,
            'flat' => $flat,
        ]);
    }

    /**
     * Store a newly created custom category.
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

        $validated['user_id'] = $request->user()->id;
        $validated['is_active'] = true;

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Kategori berhasil dibuat',
            'category' => $category->load('parent'),
        ], 201);
    }

    /**
     * Update the specified custom category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Kategori berhasil diperbarui',
            'category' => $category,
        ]);
    }

    /**
     * Remove or archive the specified custom category.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($category->transactions()->exists()) {
            $category->update(['is_active' => false]);
            return response()->json([
                'message' => 'Kategori diarsipkan karena sudah digunakan dalam transaksi.',
            ]);
        }

        $category->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }
}

