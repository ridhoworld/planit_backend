<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\FinancialGoal;
use App\Models\Investment;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        // Demo User for Testing
        $user = User::firstOrCreate(
            ['email' => 'demo@planit.app'],
            [
                'name' => 'Budi Pratama',
                'password' => Hash::make('password123'),
                'primary_currency' => 'IDR',
            ]
        );

        // Initial accounts
        $bca = Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'BCA Tahapan'],
            [
                'type' => 'bank',
                'initial_balance' => 12500000,
                'current_balance' => 12500000,
                'currency' => 'IDR',
                'color' => '#1E40AF',
                'icon' => 'account_balance',
                'is_active' => true,
            ]
        );

        $gopay = Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'GoPay'],
            [
                'type' => 'e-wallet',
                'initial_balance' => 850000,
                'current_balance' => 850000,
                'currency' => 'IDR',
                'color' => '#059669',
                'icon' => 'account_balance_wallet',
                'is_active' => true,
            ]
        );

        $cash = Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Dompet Tunai'],
            [
                'type' => 'cash',
                'initial_balance' => 450000,
                'current_balance' => 450000,
                'currency' => 'IDR',
                'color' => '#D97706',
                'icon' => 'payments',
                'is_active' => true,
            ]
        );

        $cc = Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Mandiri Everyday Card'],
            [
                'type' => 'credit_card',
                'initial_balance' => 0,
                'current_balance' => 0,
                'credit_limit' => 15000000,
                'currency' => 'IDR',
                'color' => '#7C3AED',
                'icon' => 'credit_card',
                'is_active' => true,
            ]
        );

        // Tags
        $tagLiburan = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'Liburan']);
        $tagReimburse = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'Reimburse']);
        $tagKopi = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'Kopi']);

        // Sample transactions
        $salaryCategory = Category::where('name', 'Gaji & Pendapatan')->first();
        $foodCategory = Category::where('name', 'Makanan & Minuman')->first();
        $billCategory = Category::where('name', 'Tagihan & Langganan')->first();

        // Income
        if ($salaryCategory) {
            $t1 = Transaction::create([
                'user_id' => $user->id,
                'account_id' => $bca->id,
                'category_id' => $salaryCategory->id,
                'type' => 'income',
                'amount' => 15000000,
                'transaction_date' => Carbon::now()->subDays(5),
                'notes' => 'Gaji Bulanan PT Maju Bersama',
            ]);
            $bca->current_balance += 15000000;
        }

        // Expense
        if ($foodCategory) {
            $t2 = Transaction::create([
                'user_id' => $user->id,
                'account_id' => $gopay->id,
                'category_id' => $foodCategory->id,
                'type' => 'expense',
                'amount' => 65000,
                'transaction_date' => Carbon::now()->subDays(1),
                'notes' => 'Makan Siang Soto Betawi',
            ]);
            $t2->tags()->attach($tagKopi->id);
            $gopay->current_balance -= 65000;
        }

        if ($billCategory) {
            $t3 = Transaction::create([
                'user_id' => $user->id,
                'account_id' => $bca->id,
                'category_id' => $billCategory->id,
                'type' => 'expense',
                'amount' => 186000,
                'transaction_date' => Carbon::now()->subDays(2),
                'notes' => 'Langganan Netflix & Spotify',
            ]);
            $bca->current_balance -= 186000;
        }

        $bca->save();
        $gopay->save();

        // Goals
        FinancialGoal::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Dana Darurat 6 Bulan'],
            [
                'target_amount' => 30000000,
                'current_amount' => 12000000,
                'deadline' => Carbon::now()->addMonths(8)->toDateString(),
                'status' => 'in_progress',
                'color' => '#10B981',
                'icon' => 'shield',
            ]
        );

        FinancialGoal::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'DP Rumah Impian'],
            [
                'target_amount' => 100000000,
                'current_amount' => 25000000,
                'deadline' => Carbon::now()->addMonths(24)->toDateString(),
                'status' => 'in_progress',
                'color' => '#3B82F6',
                'icon' => 'home',
            ]
        );

        // Debt & Receivable
        Debt::firstOrCreate(
            ['user_id' => $user->id, 'party_name' => 'Pinjaman KTA Bank Mandiri', 'type' => 'payable'],
            [
                'total_amount' => 10000000,
                'remaining_amount' => 4500000,
                'interest_rate' => 0.8,
                'due_date' => Carbon::now()->addMonths(6)->toDateString(),
                'notes' => 'Cicilan Rp 750.000 / bulan',
            ]
        );

        Debt::firstOrCreate(
            ['user_id' => $user->id, 'party_name' => 'Andi (Pinjam Liburan)', 'type' => 'receivable'],
            [
                'total_amount' => 1200000,
                'remaining_amount' => 1200000,
                'due_date' => Carbon::now()->addDays(14)->toDateString(),
                'notes' => 'Janji lunas akhir bulan ini',
            ]
        );

        // Investments
        Investment::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'BBCA - Bank Central Asia'],
            [
                'type' => 'stock',
                'units' => 1500,
                'avg_buy_price' => 9200,
                'current_price' => 10150,
                'last_price_updated_at' => Carbon::now(),
                'notes' => 'Portofolio saham bluechip',
            ]
        );

        Investment::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Emas Logam Mulia Antam'],
            [
                'type' => 'gold',
                'units' => 10, // 10 gram
                'avg_buy_price' => 1350000,
                'current_price' => 1480000,
                'last_price_updated_at' => Carbon::now(),
                'notes' => 'Tabungan emas fisik',
            ]
        );

        // Recurring Bill
        RecurringTransaction::firstOrCreate(
            ['user_id' => $user->id, 'title' => 'WiFi Indihome'],
            [
                'account_id' => $bca->id,
                'category_id' => $billCategory?->id,
                'amount' => 385000,
                'type' => 'expense',
                'frequency' => 'monthly',
                'next_due_date' => Carbon::now()->addDays(4)->toDateString(),
                'auto_create' => false,
                'is_active' => true,
            ]
        );
    }
}
