<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingSync extends Model
{
    protected $table = 'accounting_syncs';

    protected $guarded = [];

    protected $casts = ['request' => 'array', 'lines' => 'array', 'documents_read_at' => 'datetime'];
}
