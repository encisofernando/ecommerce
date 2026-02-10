<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductVariantsController extends Controller
{
    public function store(Request $r, Product $product){
        $cid = (int)$r->user()->company_id;
        abort_unless($product->company_id == $cid, 403, 'Forbidden');

        $data = $r->validate([
            'sku' => 'required|string|max:64',
            'barcode' => 'nullable|string|max:64',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        // sku único por empresa (constraint uq_var_company_sku)
        $exists = ProductVariant::where('company_id',$cid)->where('sku',$data['sku'])->exists();
        if ($exists) {
            return response()->json(['message'=>'SKU ya existe en la empresa'], 422);
        }

        $v = ProductVariant::create(array_merge($data, [
            'company_id' => $cid,
            'product_id' => $product->id,
            'cost' => $data['cost'] ?? 0,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]));

        return $v;
    }

    public function update(Request $r, ProductVariant $variant){
        $cid = (int)$r->user()->company_id;
        abort_unless($variant->company_id == $cid, 403, 'Forbidden');

        $data = $r->validate([
            'sku' => 'sometimes|required|string|max:64',
            'barcode' => 'nullable|string|max:64',
            'price' => 'sometimes|required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($data['sku'])) {
            $exists = ProductVariant::where('company_id',$cid)->where('sku',$data['sku'])->where('id','<>',$variant->id)->exists();
            if ($exists) return response()->json(['message'=>'SKU ya existe en la empresa'], 422);
        }

        $variant->update($data);
        return $variant->fresh();
    }
}
