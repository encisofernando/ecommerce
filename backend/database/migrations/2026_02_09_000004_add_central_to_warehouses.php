<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('warehouses') && !Schema::hasColumn('warehouses','is_central_for_web')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->boolean('is_central_for_web')->default(false)->after('is_active');
                $table->index(['company_id','is_central_for_web'], 'idx_wh_central');
            });
        }
    }

    public function down(): void
    {
        // No se elimina por seguridad
    }
};
