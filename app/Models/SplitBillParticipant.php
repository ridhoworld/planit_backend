<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SplitBillParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'split_bill_id',
        'name',
        'amount',
        'is_paid',
    ];

    protected $casts = [
        'amount' => 'float',
        'is_paid' => 'boolean',
    ];

    public function splitBill()
    {
        return $this->belongsTo(SplitBill::class);
    }
}

