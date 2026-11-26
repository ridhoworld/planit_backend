<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'parent_id',
        'name',
        'type',
        'allocation_group',
        'icon',
        'color',
        'is_active',
    ];

    protected $appends = [
        'effective_allocation_group',
    ];

    public function getEffectiveAllocationGroupAttribute(): string
    {
        if (!empty($this->allocation_group) && in_array($this->allocation_group, ['needs', 'wants', 'savings'])) {
            return $this->allocation_group;
        }
        return self::inferAllocationGroup($this->name, $this->type);
    }

    /**
     * Infer 50/30/20 allocation bucket ('needs', 'wants', 'savings') from category name.
     */
    public static function inferAllocationGroup(string $name, string $type = 'expense'): string
    {
        if ($type === 'income') {
            return 'savings';
        }

        $n = strtolower($name);

        // 20% Savings / Investments / Social Giving
        $savingsKeywords = [
            'tabungan', 'saving', 'investasi', 'reksadana', 'saham', 'emas',
            'kripto', 'crypto', 'dana darurat', 'deposito', 'amal', 'donasi',
            'zakat', 'sedekah',
        ];
        foreach ($savingsKeywords as $kw) {
            if (str_contains($n, $kw)) {
                return 'savings';
            }
        }

        // 30% Wants / Lifestyle
        $wantsKeywords = [
            'kopi', 'nongkrong', 'cafe', 'hiburan', 'liburan', 'wisata',
            'staycation', 'bioskop', 'game', 'streaming', 'netflix', 'spotify',
            'pakaian', 'fashion', 'skincare', 'elektronik', 'gadget', 'hobi',
            'hadiah', 'belanja', 'gym', 'olahraga', 'snack', 'jajan',
        ];
        // Note: 'Kebutuhan Rumah' or 'Bahan Makanan' should stay 'needs' even if under 'Belanja & Kebutuhan'
        if (str_contains($n, 'kebutuhan rumah') || str_contains($n, 'bahan makanan') || str_contains($n, 'groceries') || str_contains($n, 'pokok')) {
            return 'needs';
        }

        foreach ($wantsKeywords as $kw) {
            if (str_contains($n, $kw)) {
                return 'wants';
            }
        }

        // Default 50% Needs (Kebutuhan Pokok)
        return 'needs';
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }
}

