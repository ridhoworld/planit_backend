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

        // Update expense from 50,000 to 75,000 and change notes
        $txId = $response->json('transaction.id');
        $updateRes = $this->actingAs($user)->putJson("/api/transactions/{$txId}", [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 75000,
            'transaction_date' => now()->toDateTimeString(),
            'notes' => 'Updated expense amount',
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('transaction.amount', 75000)
            ->assertJsonPath('transaction.notes', 'Updated expense amount');

        $account->refresh();
        $this->assertEquals($initialBalance - 75000, $account->current_balance);
    }

    public function test_50_30_20_allocation_calculation_and_auto_budgets(): void
    {
        $user = User::where('email', 'demo@planit.app')->first();

        // 1. Update allocation settings with 10,000,000 monthly income target & auto-generate budgets
        $response = $this->actingAs($user)->postJson('/api/budgets/allocation-settings', [
            'monthly_income_target' => 10000000,
            'needs_ratio' => 50,
            'wants_ratio' => 30,
            'savings_ratio' => 20,
            'auto_generate_budgets' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('allocation_50_30_20.base_income', 10000000)
            ->assertJsonPath('allocation_50_30_20.needs.limit', 5000000)
            ->assertJsonPath('allocation_50_30_20.wants.limit', 3000000)
            ->assertJsonPath('allocation_50_30_20.savings.target', 2000000);

        $this->assertGreaterThan(0, $response->json('generated_budgets_count'));

        // 2. Verify GET /api/budgets includes allocation_50_30_20
        $budgetRes = $this->actingAs($user)->getJson('/api/budgets');
        $budgetRes->assertStatus(200)
            ->assertJsonStructure([
                'budgets',
                'total_limit',
                'total_spent',
                'allocation_50_30_20' => [
                    'base_income',
                    'ratios',
                    'needs',
                    'wants',
                    'savings',
                    'recommendation',
                ],
            ]);
    }
}

