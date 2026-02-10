<?php

namespace App\Services\ECommerce;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Order;
use App\Models\Sale;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    /**
     * Crea un remito/entrega para un pedido web (si no existe).
     */
    public function createForOrder(int $companyId, int $orderId, string $method = 'pickup', ?string $address = null): Delivery
    {
        return DB::transaction(function () use ($companyId, $orderId, $method, $address) {
            $order = Order::where('company_id', $companyId)->where('id', $orderId)->with('items')->lockForUpdate()->firstOrFail();

            $existing = Delivery::where('company_id', $companyId)->where('order_id', $orderId)->first();
            if ($existing) return $existing->load('items');

            $sale = Sale::where('company_id', $companyId)->where('order_id', $orderId)->first();

            $stock = app(StockService::class);
            $warehouseId = $stock->getCentralWarehouseIdForWeb($companyId);

            $delivery = Delivery::create([
                'company_id' => $companyId,
                'order_id' => $orderId,
                'sale_id' => $sale?->id,
                'warehouse_id' => $warehouseId,
                'method' => $method,
                'status' => $method === 'pickup' ? 'ready_for_pickup' : 'preparing',
                'address' => $address,
            ]);

            foreach ($order->items as $it) {
                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'variant_id' => $it->variant_id,
                    'qty' => $it->qty,
                ]);
            }

            $order->update(['fulfillment_status' => $delivery->status]);

            return $delivery->load('items');
        });
    }
}
