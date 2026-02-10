<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

class MercadoPagoService
{
    private string $accessToken;
    private string $baseUrl;

    public function __construct()
    {
        $this->accessToken = (string) config('mercadopago.access_token');
        $this->baseUrl = rtrim((string) config('mercadopago.base_url', 'https://api.mercadopago.com'), '/');
        if (!$this->accessToken) {
            throw new \RuntimeException('Falta configurar MERCADOPAGO_ACCESS_TOKEN');
        }
    }

    public function createPreference(array $payload): array
    {
        $res = Http::withToken($this->accessToken)
            ->acceptJson()
            ->post($this->baseUrl.'/checkout/preferences', $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('MercadoPago error creando preference: '.$res->status().' '.$res->body());
        }
        return $res->json();
    }

    public function getPayment(string $paymentId): array
    {
        $res = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get($this->baseUrl.'/v1/payments/'.$paymentId);

        if (!$res->successful()) {
            throw new \RuntimeException('MercadoPago error obteniendo payment: '.$res->status().' '.$res->body());
        }
        return $res->json();
    }

    /**
     * Valida firma de Webhook según docs: x-signature contiene ts y v1.
     * Template: id:[data.id_url];request-id:[x-request-id];ts:[ts];
     */
    public function validateWebhookSignature(?string $xSignature, ?string $xRequestId, ?string $dataIdFromUrl): bool
    {
        $secret = (string) config('mercadopago.webhook_secret');
        if (!$secret) {
            // Si no hay secret configurado, no podemos validar.
            return false;
        }
        if (!$xSignature || !$xRequestId || !$dataIdFromUrl) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $kv) {
            [$k,$v] = array_pad(explode('=', trim($kv), 2), 2, null);
            if ($k && $v !== null) $parts[$k] = $v;
        }
        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if (!$ts || !$v1) return false;

        $template = "id:{$dataIdFromUrl};request-id:{$xRequestId};ts:{$ts};";
        $calc = hash_hmac('sha256', $template, $secret);

        return hash_equals($v1, $calc);
    }
}
