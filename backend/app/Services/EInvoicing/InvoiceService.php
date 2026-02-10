<?php

namespace App\Services\EInvoicing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Sale;
use App\Services\TaxCalculator;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function issueFromSale(
        int $companyId,
        int $saleId,
        string $invoiceTypeCode,
        int $posNumber,
        int $docTipo = 99,
        float $docNro = 0
    ): Invoice {
        $wsfe = app(ArcaWsfeService::class);
        $taxCalc = app(TaxCalculator::class);

        /** @var Sale $sale */
        $sale = Sale::where('company_id', $companyId)->where('id', $saleId)->with('items.variant')->firstOrFail();

        if ($sale->status === 'invoiced') {
            throw new \RuntimeException('La venta ya está facturada');
        }

        $invType = InvoiceType::where('company_id', $companyId)->where('code', $invoiceTypeCode)->first();
        if (!$invType) {
            $invType = InvoiceType::create([
                'company_id' => $companyId,
                'code' => $invoiceTypeCode,
                'name' => 'Factura '.strtoupper(trim($invoiceTypeCode)),
            ]);
        }

        $cbteTipo = match (strtoupper(trim($invoiceTypeCode))) {
            'A' => 1,
            'B' => 6,
            'C' => 11,
            'M' => 51,
            default => 11,
        };

        return DB::transaction(function () use ($companyId, $sale, $invType, $posNumber, $docTipo, $docNro, $wsfe, $taxCalc, $cbteTipo) {
            $issueDate = now();

            // Si por alguna razón la venta no tiene totales calculados, los reconstruimos desde ítems.
            $sum = $taxCalc->summarizeFromItems($sale->items->map(function ($it) {
                return [
                    'gross' => (float)$it->line_total,
                    'tax_rate' => (float)($it->tax_rate ?? 0),
                ];
            })->all());

            $subtotalNet = (float)($sale->subtotal ?? $sum['total_net']);
            $taxTotalIva = (float)($sale->tax_total ?? $sum['total_iva']);
            $totalGross  = (float)($sale->total ?? $sum['total_gross']);

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'invoice_type_id' => $invType->id,
                'pos_number' => $posNumber,
                'issue_date' => $issueDate,
                'customer_name' => optional($sale->customer)->name ?? null,
                'subtotal' => $subtotalNet,
                'tax_total' => $taxTotalIva,
                'total' => $totalGross,
                'status' => 'pending',
            ]);

            foreach ($sale->items as $it) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $it->product_id,
                    'description' => $it->description,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'tax_rate' => $it->tax_rate,
                    'line_total' => $it->line_total,
                ]);
            }

            $resp = $wsfe->createVoucher([
                'cbte_tipo' => $cbteTipo,
                'concepto' => 1,
                'doc_tipo' => $docTipo,
                'doc_nro' => $docNro,
                'imp_total' => $totalGross,
                'imp_neto' => $subtotalNet,
                'imp_iva' => $taxTotalIva,
                'iva_alicuotas' => $sum['iva_alicuotas'],
                'cbte_fch' => $issueDate->format('Ymd'),
            ]);

            if (($resp['resultado'] ?? null) !== 'A' || empty($resp['cae'])) {
                $invoice->update([
                    'status' => 'rejected',
                    'arca_response' => is_array($resp['raw'] ?? null) ? ($resp['raw'] ?? []) : (json_decode(json_encode($resp['raw'] ?? []), true) ?: []),
                ]);
                throw new \RuntimeException('ARCA rechazó el comprobante');
            }

            $invoice->update([
                'status' => 'authorized',
                'invoice_number' => $resp['cbte_nro'],
                'cae' => $resp['cae'],
                'cae_due_date' => $resp['cae_vto'] ?? null,
                'arca_response' => is_array($resp['raw'] ?? null) ? ($resp['raw'] ?? []) : (json_decode(json_encode($resp['raw'] ?? []), true) ?: []),
            ]);

            $sale->update(['status' => 'invoiced']);

            return $invoice;
        });
    }
}
