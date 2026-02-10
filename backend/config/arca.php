<?php

return [
    'env' => env('AFIP_ENV', 'homologacion'), // homologacion|produccion
    'cuit' => env('AFIP_CUIT', ''),
    'punto_venta' => env('AFIP_PTO_VTA', ''),
    'cert_path' => env('AFIP_CERT_PATH', env('AFIP_CERT', '')),
    'key_path'  => env('AFIP_KEY_PATH',  env('AFIP_KEY',  '')),
    'cainfo'    => env('AFIP_CAINFO', ''),
    // WSAA / WSFE endpoints se manejan en AfipWsClient actual (app/Services/EInvoicing).
];
