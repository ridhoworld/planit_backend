<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'primary_currency',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    public function recurringTransactions()
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function financialGoals()
    {
        return $this->hasMany(FinancialGoal::class);
    }

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    public function splitBills()
    {
        return $this->hasMany(SplitBill::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
