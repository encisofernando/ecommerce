<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    protected $table = 'sale_payments';

    protected $fillable = [
        'company_id','sale_id','order_id','provider','provider_payment_id','status','amount','currency','raw'
    ];

    protected $casts = [
        'amount'=>'float',
        'raw'=>'array',
    ];

    public function sale(){ return $this->belongsTo(Sale::class); }

    public function order(){ return $this->belongsTo(Order::class); }
}
