<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('deliveries')) {
            Schema::create('deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable(); // retiro / origen
                $table->string('method', 20)->default('pickup'); // pickup|shipping
                $table->string('status', 20)->default('pending'); // pending|preparing|ready_for_pickup|shipped|delivered|cancelled
                $table->string('tracking_code', 191)->nullable();
                $table->text('address')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['company_id','status']);
                $table->index(['company_id','order_id']);
                $table->index(['company_id','sale_id']);
                $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade');
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete()->onUpdate('cascade');
                $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete()->onUpdate('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete()->onUpdate('cascade');
            });
        }

        if (!Schema::hasTable('delivery_items')) {
            Schema::create('delivery_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delivery_id');
                $table->unsignedBigInteger('variant_id');
                $table->decimal('qty', 14, 3);
                $table->timestamps();

                $table->index(['delivery_id']);
                $table->index(['variant_id']);
                $table->foreign('delivery_id')->references('id')->on('deliveries')->cascadeOnDelete()->onUpdate('cascade');
                $table->foreign('variant_id')->references('id')->on('product_variants')->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
    }
};
