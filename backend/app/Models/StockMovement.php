<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'company_id','warehouse_id','variant_id','movement_date','type','qty','reference_type','reference_id','note'
    ];

    protected $casts = [
        'movement_date'=>'datetime',
        'qty'=>'float',
    ];

    public function warehouse(){ return $this->belongsTo(Warehouse::class); }

    public function variant(){ return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
