<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_scan_receipt_with_ai(): void
    {
        $user = User::factory()->create();

        // Dummy 1x1 png base64
        $dummyBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->actingAs($user)->postJson('/api/ai/scan-receipt', [
            'image' => $dummyBase64,
            'mime_type' => 'image/png',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'merchant',
                'amount',
                'date',
                'type',
                'suggested_category_name',
            ],
        ]);
    }

    public function test_user_can_chat_with_financial_copilot(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso']);

        $category = \App\Models\Category::create([
            'user_id' => $user->id,
            'name' => 'Makanan',
            'type' => 'expense',
        ]);

        \App\Models\Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount_limit' => 2000000,
            'period' => 'monthly',
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/ai/chat', [
            'message' => 'Bagaimana kondisi keuangan saya saat ini?',
            'history' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'reply',
            'quick_suggestions',
        ]);
    }
}

