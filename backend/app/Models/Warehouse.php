<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id','branch_id','name','code','is_active','is_central_for_web'
    ];

    protected $casts = [
        'is_active'=>'bool',
        'is_central_for_web'=>'bool',
    ];

    public function stocks(){ return $this->hasMany(Stock::class); }
}
