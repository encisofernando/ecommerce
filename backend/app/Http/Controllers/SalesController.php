<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ProductVariant;
use App\Services\StockService;
use App\Services\TaxCalculator;

class SalesController extends Controller
{
    /**
     * Venta presencial / POS.
     * items: [{variant_id, qty, unit_price?}]
     */
    public function store(Request $r, StockService $stock){
        $cid = (int)$r->user()->company_id;

        $data = $r->validate([
            'warehouse_id' => 'required|integer',
            'customer_id'  => 'nullable|integer',
            'items'        => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer',
            'items.*.qty'        => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'sometimes|numeric|min:0',
            'items.*.description'=> 'sometimes|nullable|string|max:191',
        ]);

        $sale = DB::transaction(function() use($cid,$data,$stock,$r){
            $sale = Sale::create([
                'company_id'  => $cid,
                'warehouse_id'=> $data['warehouse_id'],
                'cashier_id'  => $r->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date'   => now(),
                'channel'     => 'pos',
                'status'      => 'confirmed',
                'currency'    => 'ARS',
            ]);

            $taxCalc = app(TaxCalculator::class);
            $grossTotal = 0;
            $netTotal = 0;
            $ivaTotal = 0;

            foreach($data['items'] as $it){
                $variant = ProductVariant::where('company_id',$cid)->where('id',$it['variant_id'])->firstOrFail();

                $qty = (float)$it['qty'];
                $unit = array_key_exists('unit_price',$it) ? (float)$it['unit_price'] : (float)$variant->price;
                $discount = isset($it['discount']) ? (float)$it['discount'] : 0.0;
                $lineGross = ($qty * $unit) - $discount;
                $rate = (float)($variant->tax_rate ?? 0);
                $split = $taxCalc->splitGross($lineGross, $rate);
                $grossTotal += $lineGross;
                $netTotal += $split['net'];
                $ivaTotal += $split['iva'];

                $si = SaleItem::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'description'=> $it['description'] ?? null,
                    'qty'        => $qty,
                    'unit_price' => $unit,
                    'discount'   => $discount,
                    'tax_id'     => $variant->tax_id,
                    'tax_rate'   => $rate,
                    'tax_amount' => $split['iva'],
                    'line_total' => $lineGross,
                ]);

                $stock->moveOut($cid,$data['warehouse_id'],$variant->id,$qty,'sale_item',$si->id);
            }

            $sale->update([
                'subtotal' => round($netTotal,2),
                'tax_total' => round($ivaTotal,2),
                'total' => round($grossTotal,2),
            ]);

            return $sale;
        });

        return $sale->load('items.variant');
    }

    public function show(Request $r, Sale $sale){
        $cid = (int)$r->user()->company_id;
        abort_unless($sale->company_id == $cid, 403, 'Forbidden');
        return $sale->load('items.variant');
    }
}
