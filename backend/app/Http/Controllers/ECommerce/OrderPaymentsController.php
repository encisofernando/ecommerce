<?php

namespace App\Http\Controllers\ECommerce;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ECommerce\OrderService;
use Illuminate\Http\Request;

class OrderPaymentsController extends Controller
{
    /**
     * Confirma pago manual para un pedido web.
     * method: cash|bank_transfer
     */
    public function confirmManual(Request $r, Order $order, OrderService $svc)
    {
        $cid = (int)$r->user()->company_id;
        abort_unless($order->company_id == $cid, 403, 'Forbidden');

        $data = $r->validate([
            'method' => 'required|string|in:cash,bank_transfer',
            'amount' => 'required|numeric|min:0',
            'reference' => 'sometimes|nullable|string|max:191',
            'note' => 'sometimes|nullable|string|max:191',
        ]);

        $sale = $svc->markOrderPaidManual(
            $cid,
            (int)$order->id,
            (string)$data['method'],
            (float)$data['amount'],
            $data['reference'] ?? null,
            $data['note'] ?? null
        );

        return $sale->load('items.variant');
    }
}
