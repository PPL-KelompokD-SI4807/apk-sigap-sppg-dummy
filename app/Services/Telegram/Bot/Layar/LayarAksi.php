<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;

/**
 * Layar baca yang pada dasarnya berisi aksi tulis (mis. "Mulai Perjalanan"). Tombol berandanya hanya tampil bila
 * tampilUntuk() benar untuk peran SAAT INI (selain hak baca RBAC). Tidak memberi hak apa pun: klik tetap dicek ulang
 * BotNavigasi (layar) dan BotAksiTulisService (aksi).
 */
interface LayarAksi
{
    public function tampilUntuk(User $user): bool;
}
