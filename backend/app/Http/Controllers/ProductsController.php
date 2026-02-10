<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductsController extends Controller
{
    public function index(Request $r){
        $cid = (int)$r->user()->company_id;

        $q = Product::query()->where('company_id',$cid);

        if($search = $r->get('q')){
            $q->where(function($w) use($search){
                $w->where('name','like',"%$search%")
                  ->orWhere('sku','like',"%$search%")
                  ->orWhere('barcode','like',"%$search%");
            });
        }

        return $q->with('variants')->orderBy('id','desc')->paginate(25);
    }

    public function show(Request $r, Product $product){
        $cid = (int)$r->user()->company_id;
        abort_unless($product->company_id == $cid, 403, 'Forbidden');
        return $product->load('variants');
    }

    public function store(Request $r){
        $cid = (int)$r->user()->company_id;

        $data = $r->validate([
            'category_id' => 'nullable|integer',
            'sku' => 'nullable|string|max:64',
            'barcode' => 'nullable|string|max:64',
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:32',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'track_stock' => 'nullable|boolean',
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function() use($cid,$data){
            $product = Product::create(array_merge($data, [
                'company_id' => $cid,
                'unit' => $data['unit'] ?? 'UN',
                'cost' => $data['cost'] ?? 0,
                'price' => $data['price'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'track_stock' => $data['track_stock'] ?? true,
                'min_stock' => $data['min_stock'] ?? 0,
            ]));

            // Crear variante default (SKU único por empresa)
            $candidateSku = $data['sku'] ?? ('P-'.$product->id);
            $sku = $this->makeUniqueVariantSku($cid, $candidateSku, $product->id);

            ProductVariant::create([
                'company_id' => $cid,
                'product_id' => $product->id,
                'sku' => $sku,
                'barcode' => $data['barcode'] ?? null,
                'price' => $product->price ?? 0,
                'cost' => $product->cost ?? 0,
                'tax_id' => $product->tax_id ?? null,
                'tax_rate' => $product->tax_rate ?? 0,
                'is_active' => $product->is_active ?? true,
            ]);

            return $product->load('variants');
        });
    }

    public function update(Request $r, Product $product){
        $cid = (int)$r->user()->company_id;
        abort_unless($product->company_id == $cid, 403, 'Forbidden');

        $data = $r->validate([
            'category_id' => 'nullable|integer',
            'barcode' => 'nullable|string|max:64',
            'name' => 'sometimes|required|string|max:191',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:32',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'track_stock' => 'nullable|boolean',
            'min_stock' => 'nullable|numeric|min:0',
        ]);

        $product->update($data);

        // No tocamos SKU de variantes automáticamente.
        return $product->fresh()->load('variants');
    }

    private function makeUniqueVariantSku(int $companyId, string $candidate, int $productId): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') $candidate = 'P-'.$productId;

        $sku = $candidate;
        $exists = ProductVariant::where('company_id',$companyId)->where('sku',$sku)->exists();
        if (!$exists) return $sku;

        // colisión: sufijo -{productId}
        $sku = $candidate.'-'.$productId;

        // si aún colisiona, sumar contador
        $i = 2;
        while (ProductVariant::where('company_id',$companyId)->where('sku',$sku)->exists()) {
            $sku = $candidate.'-'.$productId.'-'.$i;
            $i++;
        }

        return $sku;
    }
}
