<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    use HasFactory;

    protected $table = 'telegram_updates';

    /** @var list<string> */
    protected $fillable = [
        'update_id',
        'payload',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
