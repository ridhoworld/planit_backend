<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'allocation_group')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('allocation_group', 20)->default('needs')->after('type');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'monthly_income_target')) {
                $table->decimal('monthly_income_target', 15, 2)->default(0)->after('primary_currency');
            }
            if (!Schema::hasColumn('users', 'needs_ratio')) {
                $table->unsignedTinyInteger('needs_ratio')->default(50)->after('monthly_income_target');
            }
            if (!Schema::hasColumn('users', 'wants_ratio')) {
                $table->unsignedTinyInteger('wants_ratio')->default(30)->after('needs_ratio');
            }
            if (!Schema::hasColumn('users', 'savings_ratio')) {
                $table->unsignedTinyInteger('savings_ratio')->default(20)->after('wants_ratio');
            }
        });

        // Auto-classify existing categories into needs (50%), wants (30%), savings (20%)
        $categories = DB::table('categories')->get();
        foreach ($categories as $cat) {
            $group = \App\Models\Category::inferAllocationGroup($cat->name, $cat->type);
            DB::table('categories')->where('id', $cat->id)->update(['allocation_group' => $group]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('categories', 'allocation_group')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('allocation_group');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $cols = [];
            foreach (['monthly_income_target', 'needs_ratio', 'wants_ratio', 'savings_ratio'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $cols[] = $col;
                }
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

