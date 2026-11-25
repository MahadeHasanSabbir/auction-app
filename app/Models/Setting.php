<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A runtime setting platform staff can change. config/auction.php holds the
 * default for every key.
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
