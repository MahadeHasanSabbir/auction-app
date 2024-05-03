<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Auction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'host',
        'name',
        'start_time',
        'end_time',
        'final_price',
        'no_of_bid',
        'owner',
        'status',
    ];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function payment(){
        return $this->hasOne(Payment::class);
    }
}
