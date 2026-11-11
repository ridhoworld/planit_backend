<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'initial_balance',
        'current_balance',
        'credit_limit',
        'currency',
        'color',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'initial_balance' => 'float',
        'current_balance' => 'float',
        'credit_limit' => 'float',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(Transaction::class, 'target_account_id');
    }
}

