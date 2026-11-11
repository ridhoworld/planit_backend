<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default Income Categories
        $incomeCategories = [
            ['name' => 'Gaji & Pendapatan', 'icon' => 'payments', 'color' => '#10B981'],
            ['name' => 'Bonus & THR', 'icon' => 'redeem', 'color' => '#34D399'],
            ['name' => 'Investasi & Dividen', 'icon' => 'trending_up', 'color' => '#059669'],
            ['name' => 'Freelance / Sampingan', 'icon' => 'laptop', 'color' => '#6EE7B7'],
            ['name' => 'Hadiah & Hibah', 'icon' => 'card_giftcard', 'color' => '#A7F3D0'],
            ['name' => 'Pemasukan Lainnya', 'icon' => 'more_horiz', 'color' => '#9CA3AF'],
        ];

        foreach ($incomeCategories as $item) {
            Category::firstOrCreate(
                ['user_id' => null, 'name' => $item['name'], 'type' => 'income'],
                ['icon' => $item['icon'], 'color' => $item['color'], 'is_active' => true]
            );
        }

        // Default Expense Categories & Sub-categories
        $expenseCategories = [
            [
                'name' => 'Makanan & Minuman',
                'icon' => 'restaurant',
                'color' => '#EF4444',
                'subs' => ['Makan Siang/Malam', 'Kopi & Nongkrong', 'Bahan Makanan / Groceries']
            ],
            [
                'name' => 'Transportasi',
                'icon' => 'directions_car',
                'color' => '#F97316',
                'subs' => ['Bensin & Parkir', 'Transportasi Online', 'Tol / Tiket']
            ],
            [
                'name' => 'Belanja & Kebutuhan',
                'icon' => 'shopping_bag',
                'color' => '#F59E0B',
                'subs' => ['Pakaian', 'Elektronik & Gadget', 'Kebutuhan Rumah']
            ],
            [
                'name' => 'Tagihan & Langganan',
                'icon' => 'receipt_long',
                'color' => '#8B5CF6',
                'subs' => ['Listrik & Air', 'Internet & Pulsa', 'Streaming & Software']
            ],
            [
                'name' => 'Kesehatan',
                'icon' => 'medical_services',
                'color' => '#EC4899',
                'subs' => ['Obat & Vitamin', 'Dokter / Rumah Sakit', 'Olahraga & Gym']
            ],
            [
                'name' => 'Hiburan & Liburan',
                'icon' => 'sports_esports',
                'color' => '#6366F1',
                'subs' => ['Bioskop & Game', 'Wisata & Staycation']
            ],
            [
                'name' => 'Pendidikan',
                'icon' => 'school',
                'color' => '#3B82F6',
                'subs' => ['Buku & Kursus', 'Uang Sekolah / Kuliah']
            ],
            [
                'name' => 'Amal & Donasi',
                'icon' => 'favorite',
                'color' => '#14B8A6',
                'subs' => ['Zakat / Sedekah', 'Hadiah Keluarga']
            ],
            [
                'name' => 'Pengeluaran Lainnya',
                'icon' => 'more_horiz',
                'color' => '#64748B',
                'subs' => []
            ],
        ];

        foreach ($expenseCategories as $item) {
            $parent = Category::firstOrCreate(
                ['user_id' => null, 'name' => $item['name'], 'type' => 'expense'],
                ['icon' => $item['icon'], 'color' => $item['color'], 'is_active' => true]
            );

            foreach ($item['subs'] as $subName) {
                Category::firstOrCreate(
                    ['user_id' => null, 'name' => $subName, 'type' => 'expense', 'parent_id' => $parent->id],
                    ['icon' => $item['icon'], 'color' => $item['color'], 'is_active' => true]
                );
            }
        }
    }
}

