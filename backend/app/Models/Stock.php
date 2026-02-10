<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    // Tabla tiene created_at/updated_at
    public $timestamps = true;

    protected $fillable = [
        'company_id','warehouse_id','variant_id','qty','reserved_qty'
    ];

    protected $casts = [
        'qty' => 'float',
        'reserved_qty' => 'float',
    ];

    public function warehouse(){ return $this->belongsTo(Warehouse::class); }

    public function variant(){ return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
