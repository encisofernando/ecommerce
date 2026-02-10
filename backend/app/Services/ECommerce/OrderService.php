<?php

namespace App\Services\ECommerce;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockReservation;
use App\Services\StockService;
use App\Services\TaxCalculator;
use App\Services\Payments\MercadoPagoService;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function createOrderAndReserve(int $companyId, ?int $customerId, array $items, int $reserveMinutes = 30, ?string $paymentMethod = null): Order
    {
        return DB::transaction(function () use ($companyId, $customerId, $items, $reserveMinutes, $paymentMethod) {
            $order = Order::create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'channel' => 'web',
                'payment_method' => $paymentMethod,
                // status extendido: pending|reserved|awaiting_payment|paid|cancelled|expired
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'pending',
                'currency' => 'ARS',
                'subtotal' => 0,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => 0,
                'reservation_expires_at' => now()->addMinutes($reserveMinutes),
            ]);

            $tax = app(TaxCalculator::class);
            $grossTotal = 0;
            $netTotal = 0;
            $ivaTotal = 0;
            $discountTotal = 0;
            foreach ($items as $it) {
                /** @var ProductVariant $variant */
                $variant = ProductVariant::where('company_id', $companyId)->where('id', $it['variant_id'])->firstOrFail();
                $qty = (float)$it['qty'];
                $unit = isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$variant->price;
                $discount = isset($it['discount']) ? (float)$it['discount'] : 0.0;
                $gross = ($qty * $unit) - $discount;

                $rate = (float)($variant->tax_rate ?? 0);
                $split = $tax->splitGross($gross, $rate);
                $grossTotal += $gross;
                $netTotal += $split['net'];
                $ivaTotal += $split['iva'];
                $discountTotal += $discount;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'description' => $it['description'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'discount' => $discount,
                    'tax_id' => $variant->tax_id,
                    'tax_rate' => $rate,
                    'tax_amount' => $split['iva'],
                    'line_total' => $gross,
                ]);
            }

            $order->update([
                'subtotal' => round($netTotal,2),
                'tax_total' => round($ivaTotal,2),
                'discount_total' => round($discountTotal,2),
                'total' => round($grossTotal,2),
                'status' => 'reserved',
            ]);

            // Reservar stock en depósito central para cada ítem
            $stock = app(StockService::class);
            foreach ($order->items as $item) {
                $stock->reserveForWeb($companyId, (int)$item->variant_id, (float)$item->qty, $reserveMinutes, 'order', $order->id);
            }

            // Si es pago manual, queda esperando confirmación. Si es MP, también (hasta webhook).
            $order->update(['status' => 'awaiting_payment']);

            return $order->load('items');
        });
    }

    public function createMercadoPagoPreference(Order $order, string $successUrl, string $failureUrl, string $pendingUrl, string $webhookUrl): array
    {
        $mp = app(MercadoPagoService::class);

        $items = [];
        foreach ($order->items as $it) {
            $items[] = [
                'title' => $it->description ?: ('Item '.$it->variant_id),
                'quantity' => (int) ceil($it->qty), // MP requiere integer en muchos casos; ajustar si vendés fraccionado.
                'unit_price' => (float) $it->unit_price,
                'currency_id' => $order->currency ?: 'ARS',
            ];
        }

        $payload = [
            'external_reference' => (string) $order->id,
            'items' => $items,
            'notification_url' => $webhookUrl,
            'back_urls' => [
                'success' => $successUrl,
                'failure' => $failureUrl,
                'pending' => $pendingUrl,
            ],
            'auto_return' => 'approved',
        ];

        $pref = $mp->createPreference($payload);

        // Persistir datos útiles para depuración y front
        $order->update([
            'payment_method' => 'mercadopago',
            'mp_preference_id' => $pref['id'] ?? null,
            'mp_init_point' => $pref['init_point'] ?? null,
        ]);

        return $pref;
    }

    /**
     * Finaliza un pedido pagado: convierte a Sale, confirma reservas y registra pago.
     */
    public function markOrderPaidFromMercadoPago(int $companyId, int $orderId, array $payment): Sale
    {
        return DB::transaction(function () use ($companyId, $orderId, $payment) {
            /** @var Order $order */
            $order = Order::where('company_id', $companyId)->where('id', $orderId)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === 'approved' || $order->status === 'paid') {
                // idempotencia
                $sale = Sale::where('order_id', $order->id)->first();
                if ($sale) return $sale->load('items');
            }

            $order->update([
                'payment_status' => 'approved',
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => 'mercadopago',
            ]);

            // Crear Sale (canal web)
            $stockService = app(StockService::class);
            $warehouseId = $stockService->getCentralWarehouseIdForWeb($companyId);

            $tax = app(TaxCalculator::class);

            $sale = Sale::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'channel' => 'web',
                'sale_date' => now(),
                'status' => 'confirmed',
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'total' => $order->total,
                'currency' => $order->currency ?: 'ARS',
            ]);

            foreach ($order->items as $it) {
                $split = $tax->splitGross((float)$it->line_total, (float)($it->tax_rate ?? 0));
                $si = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $it->product_id,
                    'variant_id' => $it->variant_id,
                    'description' => $it->description,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'discount' => $it->discount ?? 0,
                    'tax_id' => $it->tax_id,
                    'tax_rate' => $it->tax_rate,
                    'tax_amount' => $split['iva'],
                    'line_total' => $it->line_total,
                ]);

                // Confirmar reserva(s) del pedido para esa variante
                $reservations = StockReservation::where('company_id', $companyId)
                    ->where('reference_type', 'order')
                    ->where('reference_id', $order->id)
                    ->where('variant_id', $it->variant_id)
                    ->where('status', 'active')
                    ->get();

                foreach ($reservations as $res) {
                    $stockService->commitReservation($res, 'sale_item', $si->id);
                }
            }

            // Registrar pago
            SalePayment::create([
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'order_id' => $order->id,
                'provider' => 'mercadopago',
                'provider_payment_id' => (string)($payment['id'] ?? ''),
                'status' => (string)($payment['status'] ?? 'unknown'),
                'amount' => (float)($payment['transaction_amount'] ?? $order->total),
                'currency' => (string)($payment['currency_id'] ?? ($order->currency ?: 'ARS')),
                'raw' => $payment,
            ]);

            return $sale->load('items');
        });
    }

    /**
     * Confirmación manual de pago (efectivo en sucursal / transferencia).
     */
    public function markOrderPaidManual(int $companyId, int $orderId, string $method, float $amount, ?string $reference = null, ?string $note = null): Sale
    {
        $method = strtolower(trim($method));
        if (!in_array($method, ['cash','bank_transfer'], true)) {
            throw new \InvalidArgumentException('Método inválido');
        }

        return DB::transaction(function () use ($companyId, $orderId, $method, $amount, $reference, $note) {
            /** @var Order $order */
            $order = Order::where('company_id', $companyId)->where('id', $orderId)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === 'approved' || $order->status === 'paid') {
                $sale = Sale::where('order_id', $order->id)->first();
                if ($sale) return $sale->load('items');
            }

            $order->update([
                'payment_status' => 'approved',
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $method,
                'payment_reference' => $reference,
            ]);

            $stockService = app(StockService::class);
            $warehouseId = $stockService->getCentralWarehouseIdForWeb($companyId);
            $tax = app(TaxCalculator::class);

            $sale = Sale::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'channel' => 'web',
                'sale_date' => now(),
                'status' => 'confirmed',
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'total' => $order->total,
                'currency' => $order->currency ?: 'ARS',
            ]);

            foreach ($order->items as $it) {
                $split = $tax->splitGross((float)$it->line_total, (float)($it->tax_rate ?? 0));
                $si = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $it->product_id,
                    'variant_id' => $it->variant_id,
                    'description' => $it->description,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'discount' => $it->discount ?? 0,
                    'tax_id' => $it->tax_id,
                    'tax_rate' => $it->tax_rate,
                    'tax_amount' => $split['iva'],
                    'line_total' => $it->line_total,
                ]);

                $reservations = StockReservation::where('company_id', $companyId)
                    ->where('reference_type', 'order')
                    ->where('reference_id', $order->id)
                    ->where('variant_id', $it->variant_id)
                    ->where('status', 'active')
                    ->get();

                foreach ($reservations as $res) {
                    $stockService->commitReservation($res, 'sale_item', $si->id);
                }
            }

            SalePayment::create([
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'order_id' => $order->id,
                'provider' => $method,
                'provider_payment_id' => $reference ?: null,
                'status' => 'approved',
                'amount' => $amount > 0 ? $amount : (float)$order->total,
                'currency' => $order->currency ?: 'ARS',
                'raw' => [
                    'note' => $note,
                    'reference' => $reference,
                ],
            ]);

            return $sale->load('items');
        });
    }
}
