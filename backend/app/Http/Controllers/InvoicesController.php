<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EInvoicing\InvoiceService;

class InvoicesController extends Controller
{
    /**
     * Crea factura desde una venta y autoriza en ARCA/AFIP (WSFEv1).
     * Requiere: sale_id, invoice_type_code (A|B|C|M), pos_number (Punto de venta)
     */
    public function store(Request $r, InvoiceService $svc)
    {
        $cid = (int)$r->user()->company_id;

        $data = $r->validate([
            'sale_id' => 'required|integer',
            'invoice_type_code' => 'required|string|max:5',
            'pos_number' => 'required|integer',
            'doc_tipo' => 'sometimes|integer', // 80 CUIT, 96 DNI, 99 CF
            'doc_nro' => 'sometimes|numeric',
        ]);

        try {
            $invoice = $svc->issueFromSale(
                $cid,
                (int)$data['sale_id'],
                (string)$data['invoice_type_code'],
                (int)$data['pos_number'],
                (int)($data['doc_tipo'] ?? 99),
                (float)($data['doc_nro'] ?? 0)
            );
            return $invoice->load('items');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'ya está facturada')) {
                return response()->json(['message'=>'La venta ya está facturada'], 409);
            }
            throw $e;
        }
    }
}
