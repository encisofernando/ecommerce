<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'description',
        'qty',
        'unit_price',
        'discount',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'line_total'
    ];

    protected $casts = [
        'qty'=>'float',
        'unit_price'=>'float',
        'discount'=>'float',
        'tax_rate'=>'float',
        'tax_amount'=>'float',
        'line_total'=>'float',
    ];

    public function order(){ return $this->belongsTo(Order::class); }

    public function variant(){ return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
