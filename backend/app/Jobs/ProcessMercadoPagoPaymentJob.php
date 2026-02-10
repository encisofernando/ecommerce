<?php

namespace App\Jobs;

use App\Services\Payments\MercadoPagoService;
use App\Services\ECommerce\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessMercadoPagoPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public string $paymentId) {}

    public function handle(MercadoPagoService $mp, OrderService $orders): void
    {
        $payment = $mp->getPayment($this->paymentId);
        $orderId = (int)($payment['external_reference'] ?? 0);
        if ($orderId <= 0) return;

        // Obtener company_id desde el pedido
        $companyId = (int) DB::table('orders')->where('id', $orderId)->value('company_id');
        if ($companyId <= 0) return;

        if (($payment['status'] ?? '') !== 'approved') {
            // Podés extender: rejected/pending => actualizar estado
            return;
        }

        $orders->markOrderPaidFromMercadoPago($companyId, $orderId, $payment);

        // Disparar trabajos posteriores
        dispatch(new IssueInvoiceJob($companyId, $orderId))->onQueue('ecommerce');
        dispatch(new CreateDeliveryJob($companyId, $orderId))->onQueue('ecommerce');
    }
}
