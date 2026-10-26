<?php

namespace App\Models;

use App\Enums\HasilAi;
use App\Enums\JenisInputAi;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiInteraction extends Model
{
    use HasFactory;

    protected $table = 'ai_interactions';

    /** @var list<string> */
    protected $fillable = [
        'sppg_id',
        'user_id',
        'jenis_input',
        'tool_name',
        'argumen',
        'model',
        'latensi_ms',
        'hasil',
        'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jenis_input' => JenisInputAi::class,
            'argumen' => 'array',
            'latensi_ms' => 'integer',
            'hasil' => HasilAi::class,
        ];
    }

    public function sppg(): BelongsTo
    {
        return $this->belongsTo(Sppg::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiDrafts(): HasMany
    {
        return $this->hasMany(AiDraft::class);
    }
}
