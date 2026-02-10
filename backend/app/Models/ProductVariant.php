<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    public $timestamps = true;

    protected $table = 'product_variants';

    protected $fillable = [
        'company_id','product_id','sku','barcode','price','cost','tax_id','tax_rate','is_active'
    ];

    protected $casts = [
        'price'=>'float',
        'cost'=>'float',
        'tax_rate'=>'float',
        'is_active'=>'bool',
    ];

    public function product(){ return $this->belongsTo(Product::class); }

    public function tax(){ return $this->belongsTo(Tax::class); }

    public function stocks(){ return $this->hasMany(Stock::class, 'variant_id'); }
}
