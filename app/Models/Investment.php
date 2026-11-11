<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'units',
        'avg_buy_price',
        'current_price',
        'last_price_updated_at',
        'notes',
    ];

    protected $casts = [
        'units' => 'float',
        'avg_buy_price' => 'float',
        'current_price' => 'float',
        'last_price_updated_at' => 'datetime',
    ];

    protected $appends = [
        'total_invested',
        'current_value',
        'unrealized_gain_loss',
        'unrealized_gain_loss_percentage',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTotalInvestedAttribute(): float
    {
        return (float) ($this->units * $this->avg_buy_price);
    }

    public function getCurrentValueAttribute(): float
    {
        return (float) ($this->units * $this->current_price);
    }

    public function getUnrealizedGainLossAttribute(): float
    {
        return (float) ($this->current_value - $this->total_invested);
    }

    public function getUnrealizedGainLossPercentageAttribute(): float
    {
        if ($this->total_invested <= 0) {
            return 0.0;
        }
        return (float) round(($this->unrealized_gain_loss / $this->total_invested) * 100, 2);
    }
}

