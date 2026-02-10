<?php

namespace App\Http\Controllers\ECommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Services\ECommerce\OrderService;

class OrdersController extends Controller
{
    public function store(Request $r, OrderService $svc){
        $cid = (int)$r->user()->company_id;

        $data = $r->validate([
            'customer_id' => 'nullable|integer',
            'payment_method' => 'nullable|string|in:mercadopago,cash,bank_transfer',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'sometimes|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
            'items.*.description' => 'sometimes|nullable|string|max:191',
        ]);

        return $svc->createOrderAndReserve(
            $cid,
            $data['customer_id'] ?? null,
            $data['items'],
            30,
            $data['payment_method'] ?? null
        );
    }

    public function show(Request $r, Order $order){
        $cid = (int)$r->user()->company_id;
        abort_unless($order->company_id == $cid, 403, 'Forbidden');
        return $order->load('items');
    }

    public function preference(Request $r, Order $order, OrderService $svc){
        $cid = (int)$r->user()->company_id;
        abort_unless($order->company_id == $cid, 403, 'Forbidden');

        $data = $r->validate([
            'success_url' => 'required|url',
            'failure_url' => 'required|url',
            'pending_url' => 'required|url',
            'webhook_url' => 'required|url',
        ]);

        return $svc->createMercadoPagoPreference(
            $order->load('items'),
            $data['success_url'],
            $data['failure_url'],
            $data['pending_url'],
            $data['webhook_url']
        );
    }
}
