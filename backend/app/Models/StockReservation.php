<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    public $timestamps = true;

    protected $table = 'stock_reservations';

    protected $fillable = [
        'company_id','warehouse_id','variant_id','qty','status','expires_at','reference_type','reference_id'
    ];

    protected $casts = [
        'qty'=>'float',
        'expires_at'=>'datetime',
    ];

    public function variant(){ return $this->belongsTo(ProductVariant::class, 'variant_id'); }

    public function warehouse(){ return $this->belongsTo(Warehouse::class); }
}
