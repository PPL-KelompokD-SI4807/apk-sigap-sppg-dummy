<?php

namespace Tests\Support;

/**
 * Menegaskan respons/array tidak memuat KUNCI bernama `nik` pada kedalaman mana pun (UU PDP).
 * Sengaja memeriksa kunci, bukan potongan teks isi: nama acak Faker (mis. "Damanik") memuat "nik".
 */
trait TanpaKunciNik
{
    /** @param  array<mixed>|null  $data hasil json_decode(..., true) */
    protected function assertTanpaKunciNik(?array $data, string $pesan = ''): void
    {
        $this->assertNotNull($data, 'Isi respons bukan JSON yang valid.');
        $this->assertSame([], $this->jalurKunciNik($data), $pesan !== '' ? $pesan : 'Kunci "nik" bocor pada jalur di atas.');
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string> jalur kunci `nik` (case-insensitive) yang ditemukan
     */
    protected function jalurKunciNik(array $data, string $awalan = ''): array
    {
        $temuan = [];
        foreach ($data as $kunci => $nilai) {
            $jalur = $awalan === '' ? (string) $kunci : $awalan.'.'.$kunci;
            if (is_string($kunci) && strtolower($kunci) === 'nik') {
                $temuan[] = $jalur;
            }
            if (is_array($nilai)) {
                $temuan = array_merge($temuan, $this->jalurKunciNik($nilai, $jalur));
            }
        }

        return $temuan;
    }
}
