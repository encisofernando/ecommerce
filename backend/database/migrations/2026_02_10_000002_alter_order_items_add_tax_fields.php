<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('order_items')) return;

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'discount')) {
                $table->decimal('discount', 14, 2)->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('order_items', 'tax_id')) {
                $table->unsignedBigInteger('tax_id')->nullable()->after('discount');
            }
            if (!Schema::hasColumn('order_items', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_id');
            }
            if (!Schema::hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 2)->default(0)->after('tax_rate');
            }
        });

        // FK opcional si existe tabla taxes
        if (Schema::hasTable('taxes') && !Schema::hasColumn('order_items', 'tax_id')) {
            // no-op
        }
    }

    public function down(): void
    {
        // no-op
    }
};
