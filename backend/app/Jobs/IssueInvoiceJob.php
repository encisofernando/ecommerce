<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Sale;
use App\Services\EInvoicing\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IssueInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public int $companyId, public int $orderId) {}

    public function handle(InvoiceService $svc): void
    {
        /** @var Order|null $order */
        $order = Order::where('company_id', $this->companyId)->where('id', $this->orderId)->first();
        if (!$order) return;

        /** @var Sale|null $sale */
        $sale = Sale::where('company_id', $this->companyId)->where('order_id', $order->id)->first();
        if (!$sale) return;

        // Si ya está facturada, no hacemos nada
        if ($sale->status === 'invoiced') return;

        // Configuración mínima (puedes parametrizar por company/branch)
        $invoiceType = env('AFIP_INVOICE_TYPE', 'B');
        $pos = (int) env('AFIP_PTO_VTA', 1);

        // Consumidor Final por defecto (99)
        $svc->issueFromSale($this->companyId, (int)$sale->id, $invoiceType, $pos, 99, 0);
    }
}
