<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Payments\MercadoPagoService;
use App\Jobs\ProcessMercadoPagoPaymentJob;

class MercadoPagoWebhookController extends Controller
{
    /**
     * Webhook (topic payment). Mercado Pago envía data.id con el payment id.
     * Se debe responder 200/201 rápidamente y luego consultar el pago por API.
     */
    public function handle(Request $r, MercadoPagoService $mp)
    {
        // Validación opcional de firma (recomendado)
        $dataId = (string)($r->input('data.id') ?? '');
        $xSig = $r->header('x-signature');
        $xReq = $r->header('x-request-id');

        $valid = $mp->validateWebhookSignature($xSig, $xReq, $dataId);

        if (config('mercadopago.webhook_secret') && !$valid) {
            return response()->json(['message'=>'Invalid signature'], 401);
        }

        // Solo procesamos pagos
        if (($r->input('type') ?? $r->input('topic')) !== 'payment') {
            return response()->json(['ok'=>true], 200);
        }

        if (!$dataId) {
            return response()->json(['ok'=>true], 200);
        }

        // Encolar procesamiento para responder rápido al webhook.
        dispatch(new ProcessMercadoPagoPaymentJob($dataId))->onQueue('webhooks');

        return response()->json(['ok'=>true], 200);
    }
}
