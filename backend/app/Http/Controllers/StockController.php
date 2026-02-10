<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Stock;
use App\Models\ProductVariant;

class StockController extends Controller
{
    /**
     * Stock total por variante (qty, reserved, available) en todos los depósitos de la empresa.
     */
    public function summary(Request $r){
        $cid = (int)$r->user()->company_id;

        $q = Stock::query()
            ->where('company_id', $cid)
            ->select('variant_id')
            ->selectRaw('SUM(qty) as qty_total')
            ->selectRaw('SUM(reserved_qty) as reserved_total')
            ->selectRaw('SUM(qty - reserved_qty) as available_total')
            ->groupBy('variant_id');

        return $q->with('variant.product')->get();
    }

    public function byVariant(Request $r, int $variantId){
        $cid = (int)$r->user()->company_id;

        $variant = ProductVariant::where('company_id',$cid)->where('id',$variantId)->firstOrFail();

        return Stock::where('company_id',$cid)
            ->where('variant_id',$variant->id)
            ->with('warehouse')
            ->get();
    }
}
