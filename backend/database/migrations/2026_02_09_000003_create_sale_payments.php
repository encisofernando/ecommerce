<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sale_payments')) {
            Schema::create('sale_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('provider', 30); // mercadopago
                $table->string('provider_payment_id', 64)->nullable();
                $table->string('status', 30)->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 8)->default('ARS');
                $table->json('raw')->nullable();
                $table->timestamps();

                $table->unique(['provider','provider_payment_id']);
                $table->index(['company_id','sale_id']);
                $table->index(['company_id','order_id']);

                $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade');
                $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete()->onUpdate('cascade');
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete()->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
