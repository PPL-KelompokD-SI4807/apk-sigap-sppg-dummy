<?php

namespace App\Http\Requests\Auth;

use App\Rules\NomorTelepon;
use App\Rules\PasswordKuat;
use App\Rules\TeleponUnik;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Aturan validasi kredensial R-03, didefinisikan sekali dan dipakai semua Form Request auth.
 *
 * - username: 3-32 karakter, huruf kecil/angka/titik/garis bawah, diawali huruf (agar tidak
 *   tertukar dengan no telepon saat login), disimpan huruf kecil, unik (termasuk akun terhapus).
 * - device_id: acak dari klien (localStorage), hanya disimpan sebagai HMAC.
 * - password: min 8, huruf besar+kecil, angka, tidak sama dengan username, bukan sandi umum lokal.
 */
trait AturanKredensial
{
    public const USERNAME_REGEX = '/^[a-z][a-z0-9._]{2,31}$/';

    public const DEVICE_ID_REGEX = '/^[A-Za-z0-9_-]{22,128}$/';

    public const PASSWORD_MAX = 64;

    /** @return list<mixed> */
    protected function aturanDeviceId(): array
    {
        return ['required', 'string', 'regex:'.self::DEVICE_ID_REGEX];
    }

    /** @return list<mixed> */
    protected function aturanLabelPerangkat(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:120'];
    }

    /** @return list<mixed> */
    protected function aturanIdentitas(): array
    {
        return ['required', 'string', 'min:3', 'max:32'];
    }

    /** @return list<mixed> */
    protected function aturanUsername(bool $wajib, ?int $abaikanUserId): array
    {
        return [
            $wajib ? 'required' : 'sometimes',
            'string',
            'regex:'.self::USERNAME_REGEX,
            Rule::unique('users', 'username')->ignore($abaikanUserId),
        ];
    }

    /** @return list<mixed> */
    protected function aturanTelepon(?int $abaikanUserId): array
    {
        return ['sometimes', 'nullable', 'string', new NomorTelepon, new TeleponUnik($abaikanUserId)];
    }

    /** @return list<mixed> */
    protected function aturanPassword(?string $username): array
    {
        return [
            'required', 'string', 'max:'.self::PASSWORD_MAX, 'confirmed',
            Password::min(8)->mixedCase()->numbers(),
            new PasswordKuat($username),
        ];
    }

    /** Normalisasi input sebelum validasi (huruf kecil, trim, no telepon E.164 bila dikenali). */
    protected function normalisasiKredensial(): void
    {
        $data = [];

        foreach (['username', 'identitas'] as $field) {
            if (is_string($this->input($field))) {
                $nilai = trim($this->input($field));
                $data[$field] = $field === 'username' ? mb_strtolower(ltrim($nilai, '@')) : $nilai;
            }
        }

        if ($this->has('phone') && is_string($this->input('phone')) && trim($this->input('phone')) === '') {
            $data['phone'] = null;
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /** @return array<string, string> */
    protected function pesanKredensial(): array
    {
        return [
            'username.regex' => 'Username 3-32 karakter, diawali huruf, hanya huruf kecil, angka, titik, atau garis bawah.',
            'username.unique' => 'Username sudah dipakai. Pilih username lain.',
            'device_id.required' => 'device_id wajib (ID acak perangkat dari Mini App).',
            'device_id.regex' => 'device_id tidak valid.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.max' => 'Password maksimal '.self::PASSWORD_MAX.' karakter.',
            'identitas.required' => 'Isi username atau no telepon.',
        ];
    }

    /** Nama atribut berbahasa Indonesia untuk pesan bawaan Laravel. */
    public function attributes(): array
    {
        return ['identitas' => 'username atau no telepon', 'phone' => 'no telepon', 'kode' => 'token'];
    }
}
