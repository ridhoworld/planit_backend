<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    protected GeminiAiService $aiService;

    public function __construct(GeminiAiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Scan receipt / payment proof with AI Vision.
     */
    public function scanReceipt(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required', // base64 string or file upload
            'mime_type' => 'nullable|string',
        ]);

        $base64Image = '';
        $mimeType = $request->input('mime_type', 'image/jpeg');

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mimeType = $file->getMimeType();
            $base64Image = base64_encode(file_get_contents($file->getRealPath()));
        } else {
            $base64Image = $request->input('image');
        }

        $result = $this->aiService->scanReceipt($base64Image, $mimeType, $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Chat with AI Financial Copilot.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
        ]);

        try {
            $result = $this->aiService->financialChat(
                $request->user(),
                $validated['message'],
                $validated['history'] ?? []
            );

            return response()->json([
                'success' => true,
                'reply' => $result['reply'],
                'is_ai_live' => $result['is_ai_live'] ?? false,
                'quick_suggestions' => $result['quick_suggestions'] ?? [],
                'ai_notice' => $result['ai_notice'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AI Chat Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pesan AI: ' . $e->getMessage(),
            ], 500);
        }
    }
}

