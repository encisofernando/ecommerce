<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('number', 64)->nullable();
                $table->string('channel', 10)->default('web'); // web|pos
                $table->string('status', 20)->default('pending'); // pending|paid|cancelled
                $table->string('payment_status', 20)->default('unpaid'); // unpaid|approved|rejected
                $table->decimal('total', 14, 2)->default(0);
                $table->string('currency', 8)->default('ARS');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id','status']);
                $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade');
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete()->onUpdate('cascade');
            });
        }

        if (!Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id');
                $table->string('description', 191)->nullable();
                $table->decimal('qty', 14, 3);
                $table->decimal('unit_price', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();

                $table->index(['order_id']);
                $table->index(['variant_id']);
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete()->onUpdate('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onUpdate('cascade');
                $table->foreign('variant_id')->references('id')->on('product_variants')->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
