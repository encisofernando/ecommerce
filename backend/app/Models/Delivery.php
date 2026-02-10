<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $fillable = [
        'company_id','order_id','sale_id','warehouse_id','method','status','tracking_code','address','note'
    ];

    public function items(){ return $this->hasMany(DeliveryItem::class); }
    public function order(){ return $this->belongsTo(Order::class); }
    public function sale(){ return $this->belongsTo(Sale::class); }
}
