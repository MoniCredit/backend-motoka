<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'revenue_head_id',
        'settlement_bank_id',
        'account_id',
        'percent',
        'settlement_batch',
        'split_amount',
        'settlement',
    ];
}
