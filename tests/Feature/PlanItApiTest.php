<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanItApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_can_login_with_demo_account(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'demo@planit.app',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user', 'message']);
    }

    public function test_user_can_fetch_dashboard_summary(): void
    {
        $user = User::where('email', 'demo@planit.app')->first();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'net_worth',
                'total_balance',
                'total_investments',
                'monthly_income',
                'monthly_expense',
                'recent_transactions',
            ]);
    }

    public function test_atomic_transaction_balance_updates(): void
    {
        $user = User::where('email', 'demo@planit.app')->first();
        $account = Account::where('user_id', $user->id)->where('type', 'cash')->first();
        $category = Category::where('type', 'expense')->first();

        $initialBalance = (float) $account->current_balance;

        // Record expense of 50,000
        $response = $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50000,
            'transaction_date' => now()->toDateTimeString(),
            'notes' => 'Testing expense',
        ]);

        $response->assertStatus(201);

        $account->refresh();
        $this->assertEquals($initialBalance - 50000, $account->current_balance);
    }
}

