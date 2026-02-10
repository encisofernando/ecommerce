<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'number',
        'channel',
        'payment_method',
        'status',
        'payment_status',
        'fulfillment_status',
        'reservation_expires_at',
        'paid_at',
        'payment_reference',
        'mp_preference_id',
        'mp_init_point',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'currency',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'tax_total' => 'float',
        'discount_total' => 'float',
        'total'=>'float',
        'reservation_expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function items(){ return $this->hasMany(OrderItem::class); }

    public function customer(){ return $this->belongsTo(Customer::class); }
}
