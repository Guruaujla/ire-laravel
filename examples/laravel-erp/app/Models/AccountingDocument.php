<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingDocument extends Model
{
    protected $table = 'accounting_documents';

    protected $guarded = [];

    protected $casts = ['provider_updated_at' => 'datetime'];
}
