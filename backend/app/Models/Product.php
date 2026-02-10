<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'sku',
        'barcode',
        'name',
        'description',
        'unit',
        'cost',
        'price',
        'tax_rate',
        'tax_id',
        'is_active',
        'track_stock',
        'min_stock',
    ];

    protected $casts = [
        'cost'=>'float',
        'price'=>'float',
        'tax_rate'=>'float',
        'is_active'=>'bool',
        'track_stock'=>'bool',
        'min_stock'=>'float',
    ];

    public function category(){ return $this->belongsTo(Category::class); }

    public function tax(){ return $this->belongsTo(Tax::class); }

    public function variants(){ return $this->hasMany(ProductVariant::class); }
}
