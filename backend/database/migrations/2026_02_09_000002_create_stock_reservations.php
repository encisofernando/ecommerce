<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('variant_id');
                $table->decimal('qty', 14, 3);
                $table->string('status', 20)->default('active'); // active|released|committed|expired
                $table->dateTime('expires_at')->nullable();
                $table->string('reference_type', 64)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->timestamps();

                $table->index(['company_id','warehouse_id','variant_id','status']);
                $table->index(['reference_type','reference_id']);

                $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onUpdate('cascade');
                $table->foreign('variant_id')->references('id')->on('product_variants')->onUpdate('cascade');
            });
        }

        if (Schema::hasTable('stocks') && !Schema::hasColumn('stocks','reserved_qty')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->decimal('reserved_qty', 14, 3)->default(0)->after('qty');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        // reserved_qty queda (no se elimina por seguridad)
    }
};
