<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) return;

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'subtotal')) {
                $table->decimal('subtotal', 14, 2)->default(0)->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'tax_total')) {
                $table->decimal('tax_total', 14, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('orders', 'discount_total')) {
                $table->decimal('discount_total', 14, 2)->default(0)->after('tax_total');
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('channel'); // mercadopago|cash|bank_transfer
            }
            if (!Schema::hasColumn('orders', 'fulfillment_status')) {
                $table->string('fulfillment_status', 20)->default('pending')->after('status');
            }
            if (!Schema::hasColumn('orders', 'reservation_expires_at')) {
                $table->dateTime('reservation_expires_at')->nullable()->after('fulfillment_status');
            }
            if (!Schema::hasColumn('orders', 'paid_at')) {
                $table->dateTime('paid_at')->nullable()->after('reservation_expires_at');
            }
            if (!Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference', 191)->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('orders', 'mp_preference_id')) {
                $table->string('mp_preference_id', 128)->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('orders', 'mp_init_point')) {
                $table->text('mp_init_point')->nullable()->after('mp_preference_id');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['company_id','payment_status']);
            $table->index(['company_id','payment_method']);
            $table->index(['company_id','fulfillment_status']);
            $table->index(['reservation_expires_at']);
        });
    }

    public function down(): void
    {
        // no-op
    }
};
