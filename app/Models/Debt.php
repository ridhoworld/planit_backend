<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Debt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'party_name',
        'total_amount',
        'remaining_amount',
        'interest_rate',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'remaining_amount' => 'float',
        'interest_rate' => 'float',
        'due_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(DebtPayment::class);
    }
}

