<?php

namespace App\Jobs;

use App\Services\ECommerce\DeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public int $companyId, public int $orderId) {}

    public function handle(DeliveryService $svc): void
    {
        // Por defecto: retiro en sucursal (puede parametrizarse)
        $svc->createForOrder($this->companyId, $this->orderId, 'pickup', null);
    }
}
