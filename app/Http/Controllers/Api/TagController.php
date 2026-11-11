<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = $request->user()->tags()->withCount('transactions')->orderBy('name')->get();

        return response()->json([
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
        ]);

        $tag = Tag::firstOrCreate([
            'user_id' => $request->user()->id,
            'name' => ltrim(trim($validated['name']), '#'),
        ]);

        return response()->json([
            'message' => 'Tag berhasil dibuat',
            'tag' => $tag,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tag = $request->user()->tags()->findOrFail($id);
        $tag->delete();

        return response()->json([
            'message' => 'Tag berhasil dihapus',
        ]);
    }
}

