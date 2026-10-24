<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tool baca (cari_*/lihat_*/cek_*): dijalankan langsung atas nama pengguna login.
 *
 * - AiAgentService menjalankan tool di dalam ActingUser::run() sehingga SppgScope berlaku;
 *   subkelas WAJIB memakai query Eloquent biasa (jangan withoutGlobalScopes) dan
 *   $this->allows($user, 'view', $record) untuk detail record.
 * - Defense in depth: handle() dijalankan di transaksi yang SELALU di-rollback, sehingga tool
 *   baca tidak dapat menyimpan perubahan apa pun walau keliru ditulis.
 * - Kembalikan data minimal (tanpa nomor telepon/NIK/token) karena hasil dikirim ke LLM.
 */
abstract class ReadTool extends BaseAiTool
{
    protected string $action = 'R';

    final public function isWrite(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    abstract protected function handle(User $user, array $arguments): array;

    final public function execute(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (! $this->isAvailableFor($user)) {
            return ToolResult::denied();
        }

        DB::beginTransaction();

        try {
            $data = $this->handle($user, $arguments);
        } finally {
            DB::rollBack();
        }

        return ToolResult::ok($data);
    }
}
