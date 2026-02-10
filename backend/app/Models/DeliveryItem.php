<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryItem extends Model
{
    protected $fillable = ['delivery_id','variant_id','qty'];

    public function delivery(){ return $this->belongsTo(Delivery::class); }
    public function variant(){ return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
