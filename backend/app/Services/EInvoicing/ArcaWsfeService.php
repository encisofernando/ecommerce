<?php

namespace App\Services\EInvoicing;

use SoapClient;

class ArcaWsfeService
{
    private string $env;
    private string $cuit;
    private string $puntoVenta;
    private string $certPath;
    private string $keyPath;
    private ?string $caInfo;

    public function __construct()
    {
        $this->env = config('arca.env', 'homologacion');
        $this->cuit = (string) config('arca.cuit');
        $this->puntoVenta = (string) config('arca.punto_venta');

        $this->certPath = (string) config('arca.cert_path');
        $this->keyPath  = (string) config('arca.key_path');
        $this->caInfo   = config('arca.cainfo') ?: null;

        if (!$this->cuit || !$this->puntoVenta) {
            throw new \RuntimeException('Falta configurar AFIP_CUIT y/o AFIP_PTO_VTA');
        }
        if (!$this->certPath || !$this->keyPath) {
            throw new \RuntimeException('Falta configurar AFIP_CERT_PATH y/o AFIP_KEY_PATH');
        }
    }

    private function wsaaWsdl(): string
    {
        return $this->env === 'produccion'
            ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms?wsdl'
            : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?wsdl';
    }

    private function wsfeWsdl(): string
    {
        return $this->env === 'produccion'
            ? 'https://wsfev1.afip.gov.ar/wsfev1/service.asmx?WSDL'
            : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
    }

    /**
     * Devuelve ['token'=>..., 'sign'=>..., 'expirationTime'=>...]
     */
    public function getWsaaToken(string $service = 'wsfe'): array
    {
        $traXml = $this->buildTra($service);
        $cms = $this->signTra($traXml);

        $client = new SoapClient($this->wsaaWsdl(), [
            'trace' => 0,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,
        ]);

        $res = $client->loginCms(['in0' => $cms]);

        $xml = simplexml_load_string($res->loginCmsReturn ?? '');
        if (!$xml) throw new \RuntimeException('WSAA: respuesta inválida');

        $token = (string) $xml->credentials->token;
        $sign  = (string) $xml->credentials->sign;
        $exp   = (string) $xml->header->expirationTime;

        if (!$token || !$sign) throw new \RuntimeException('WSAA: token/sign vacío');

        return ['token'=>$token,'sign'=>$sign,'expirationTime'=>$exp];
    }

    private function buildTra(string $service): string
    {
        $uniqueId = (string) time();
        $genTime = now()->subMinutes(5)->toIso8601String();
        $expTime = now()->addMinutes(30)->toIso8601String();

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"
            ."<loginTicketRequest version=\"1.0\">\n"
            ."  <header>\n"
            ."    <uniqueId>{$uniqueId}</uniqueId>\n"
            ."    <generationTime>{$genTime}</generationTime>\n"
            ."    <expirationTime>{$expTime}</expirationTime>\n"
            ."  </header>\n"
            ."  <service>{$service}</service>\n"
            ."</loginTicketRequest>";
    }

    private function signTra(string $traXml): string
    {
        $cert = file_get_contents($this->certPath);
        $pkey = file_get_contents($this->keyPath);

        if ($cert === false || $pkey === false) {
            throw new \RuntimeException('No se pudo leer cert/key para WSAA');
        }

        $tmpTra = tempnam(sys_get_temp_dir(), 'tra_').'.xml';
        $tmpCms = tempnam(sys_get_temp_dir(), 'tra_').'.cms';
        file_put_contents($tmpTra, $traXml);

        $ok = openssl_pkcs7_sign(
            $tmpTra,
            $tmpCms,
            $cert,
            $pkey,
            [],
            PKCS7_BINARY | PKCS7_DETACHED
        );
        if (!$ok) {
            throw new \RuntimeException('openssl_pkcs7_sign falló: '.openssl_error_string());
        }

        $cms = file_get_contents($tmpCms);
        @unlink($tmpTra);
        @unlink($tmpCms);

        if ($cms === false) throw new \RuntimeException('No se pudo leer CMS');

        // El CMS viene en formato PEM, extraer solo contenido base64
        $cms = preg_replace('/-----BEGIN PKCS7-----(.*)-----END PKCS7-----/s', '$1', $cms);
        $cms = str_replace(["\r","\n"], '', trim($cms));

        return $cms;
    }

    private function wsfeClient(): SoapClient
    {
        return new SoapClient($this->wsfeWsdl(), [
            'trace' => 0,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,
        ]);
    }

    public function getLastAuthorized(int $cbteTipo): int
    {
        $auth = $this->getWsaaToken('wsfe');
        $client = $this->wsfeClient();

        $params = [
            'Auth' => [
                'Token' => $auth['token'],
                'Sign' => $auth['sign'],
                'Cuit' => (float) $this->cuit,
            ],
            'PtoVta' => (int) $this->puntoVenta,
            'CbteTipo' => (int) $cbteTipo,
        ];

        $res = $client->FECompUltimoAutorizado($params);
        return (int) ($res->FECompUltimoAutorizadoResult->CbteNro ?? 0);
    }

    /**
     * Emite comprobante WSFEv1 (CAE).
     * $invoice debe contener campos mínimos: cbte_tipo, concepto, doc_tipo, doc_nro, imp_total, imp_neto, imp_iva, iva_alicuotas[]
     */
    public function createVoucher(array $invoice): array
    {
        $auth = $this->getWsaaToken('wsfe');
        $client = $this->wsfeClient();

        $cbteTipo = (int)$invoice['cbte_tipo'];
        $last = $this->getLastAuthorized($cbteTipo);
        $nro = $last + 1;

        $fecha = $invoice['cbte_fch'] ?? now()->format('Ymd');

        $det = [
            'Concepto' => (int)($invoice['concepto'] ?? 1),
            'DocTipo' => (int)($invoice['doc_tipo'] ?? 99),
            'DocNro' => (float)($invoice['doc_nro'] ?? 0),
            'CbteDesde' => $nro,
            'CbteHasta' => $nro,
            'CbteFch' => $fecha,
            'ImpTotal' => (float)$invoice['imp_total'],
            'ImpTotConc' => (float)($invoice['imp_tot_conc'] ?? 0),
            'ImpNeto' => (float)($invoice['imp_neto'] ?? 0),
            'ImpOpEx' => (float)($invoice['imp_op_ex'] ?? 0),
            'ImpIVA' => (float)($invoice['imp_iva'] ?? 0),
            'ImpTrib' => (float)($invoice['imp_trib'] ?? 0),
            'MonId' => (string)($invoice['mon_id'] ?? 'PES'),
            'MonCotiz' => (float)($invoice['mon_cotiz'] ?? 1),
        ];

        if (!empty($invoice['iva_alicuotas'])) {
            $det['Iva'] = ['AlicIva' => $invoice['iva_alicuotas']];
        }

        $req = [
            'Auth' => [
                'Token' => $auth['token'],
                'Sign' => $auth['sign'],
                'Cuit' => (float) $this->cuit,
            ],
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => 1,
                    'PtoVta' => (int)$this->puntoVenta,
                    'CbteTipo' => $cbteTipo,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [$det],
                ],
            ],
        ];

        $res = $client->FECAESolicitar($req);
        $out = $res->FECAESolicitarResult ?? null;

        $detRes = $out->FeDetResp->FECAEDetResponse[0] ?? null;
        $cabRes = $out->FeCabResp ?? null;

        return [
            'cbte_nro' => $nro,
            'resultado' => $cabRes->Resultado ?? null,
            'cae' => $detRes->CAE ?? null,
            'cae_vto' => $detRes->CAEFchVto ?? null,
            'observaciones' => $detRes->Observaciones ?? null,
            'raw' => $out,
        ];
    }
}
