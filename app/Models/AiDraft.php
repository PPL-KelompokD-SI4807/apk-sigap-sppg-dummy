<?php

namespace App\Models;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AiDraft extends Model
{
    use BelongsToSppg, HasFactory, SoftDeletes;

    protected $table = 'ai_drafts';

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'user_id',
        'ai_interaction_id',
        'module',
        'action',
        'target_id',
        'payload',
        'confidence',
        'status',
        'expires_at',
        'confirmed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AksiAiDraft::class,
            'payload' => 'array',
            'confidence' => 'array',
            'status' => StatusAiDraft::class,
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiInteraction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $draf) {
            $draf->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', StatusAiDraft::Pending->value);
    }

    public function scopeSudahKedaluwarsa(Builder $query): Builder
    {
        return $query->where('status', StatusAiDraft::Pending->value)->where('expires_at', '<=', now());
    }

    public function isKedaluwarsa(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Draf masih dapat dikonfirmasi: pending dan belum lewat 30 menit. */
    public function bisaDikonfirmasi(): bool
    {
        return $this->status === StatusAiDraft::Pending && ! $this->isKedaluwarsa();
    }
}
