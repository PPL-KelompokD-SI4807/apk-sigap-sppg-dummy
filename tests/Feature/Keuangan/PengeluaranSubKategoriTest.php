<?php

namespace Tests\Feature\Keuangan;

use App\Enums\Role;
use App\Events\PengeluaranDiajukan;
use App\Models\AuditLog;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Scopes\SppgScope;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pembelian\PembelianTestCase;

/**
 * ADR 2026-11-18: ubah HANYA sub_kategori pada pengeluaran `disetujui` tidak mengajukan ulang ke investor.
 * Ubah nominal/tanggal/kategori (atau nota baru) tetap mengajukan ulang; status lain tidak berubah perilaku.
 */
class PengeluaranSubKategoriTest extends PembelianTestCase
{
    /** @param array<string, mixed> $o */
    private function body(array $o = []): array
    {
        return array_merge(['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'sub_kategori' => 'listrik', 'uraian' => 'Token listrik dapur', 'nominal' => 750_000], $o);
    }

    private function raw(int $id): Pengeluaran
    {
        return Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }

    /** Catat sebagai akuntan lalu setujui sebagai investor (alur nyata). Mengembalikan id. @param array<string, mixed> $o */
    private function disetujui(array $o = []): int
    {
        $id = (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->body($o))->assertCreated()->json('data.id');
        $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", ['keputusan' => 'disetujui', 'versi' => 1])
            ->assertOk()->assertJsonPath('data.status_persetujuan', 'disetujui');

        return $id;
    }

    /** @param array<string, mixed> $data */
    private function ubah(int $id, array $data, ?int $versi = null)
    {
        $versi ??= $this->raw($id)->versi;

        return $this->sebagai($this->akuntanA)->putJson("/api/v1/keuangan/pengeluaran/{$id}", $data + ['versi' => $versi]);
    }

    private function jumlahPengajuan(int $id): int
    {
        return PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->count();
    }

    public function test_hanya_sub_kategori_pada_disetujui_tetap_disetujui_tanpa_notifikasi_dan_tercatat_audit(): void
    {
        $id = $this->disetujui();
        $pengajuanSebelum = $this->jumlahPengajuan($id);
        $melebihiSebelum = $this->raw($id)->melebihi_anggaran;
        $versiSebelum = $this->raw($id)->versi;
        Event::fake([PengeluaranDiajukan::class]);
        $this->fakeTelegram();

        $this->ubah($id, ['sub_kategori' => 'gas'])->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'disetujui')
            ->assertJsonPath('data.sub_kategori', 'gas')
            ->assertJsonPath('data.versi', $versiSebelum + 1);

        Event::assertNotDispatched(PengeluaranDiajukan::class);
        $this->assertCount(0, $this->telegramCalls('sendMessage'));
        $baris = $this->raw($id);
        $this->assertSame('disetujui', $baris->status_persetujuan->value);
        $this->assertSame($melebihiSebelum, $baris->melebihi_anggaran);
        $this->assertSame($pengajuanSebelum, $this->jumlahPengajuan($id));
        $this->assertSame(1, PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->where('status', 'disetujui')->count());

        $audit = AuditLog::withoutGlobalScopes()->where('auditable_type', Pengeluaran::class)->where('auditable_id', $id)
            ->where('event', 'updated')->get()->first(fn (AuditLog $l) => array_key_exists('sub_kategori', (array) $l->new_values));
        $this->assertNotNull($audit);
        $this->assertSame('listrik', $audit->old_values['sub_kategori']);
        $this->assertSame('gas', $audit->new_values['sub_kategori']);
        $this->assertSame($this->akuntanA->id, $audit->user_id);
        $this->assertSame($this->sppgA, $audit->sppg_id);
        $this->assertNotNull($audit->created_at);
        $this->assertArrayNotHasKey('status_persetujuan', (array) $audit->new_values);
        $this->assertArrayNotHasKey('uraian', (array) $audit->new_values);
        $this->assertArrayNotHasKey('nominal', (array) $audit->new_values);
    }

    public function test_pengeluaran_tetap_dihitung_anggaran_dan_laporan_setelah_ubah_sub_kategori(): void
    {
        $this->pencairan($this->sppgA, 10_000_000);
        $id = $this->disetujui();

        $this->ubah($id, ['sub_kategori' => 'internet'])->assertOk()->assertJsonPath('data.status_persetujuan', 'disetujui');

        $this->sebagai($this->akuntanA)->getJson('/api/v1/keuangan/ringkasan')->assertOk()
            ->assertJsonPath('data.terpakai', 750_000)
            ->assertJsonPath('data.menunggu_persetujuan.jumlah', 0);
    }

    public function test_field_lain_bernilai_sama_dengan_sub_kategori_berubah_dianggap_hanya_sub_kategori(): void
    {
        $id = $this->disetujui();
        Event::fake([PengeluaranDiajukan::class]);

        $this->ubah($id, ['sub_kategori' => 'gas', 'nominal' => 750_000, 'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Token listrik dapur'])
            ->assertOk()->assertJsonPath('data.status_persetujuan', 'disetujui')->assertJsonPath('data.sub_kategori', 'gas');

        Event::assertNotDispatched(PengeluaranDiajukan::class);
        $audit = AuditLog::withoutGlobalScopes()->where('auditable_type', Pengeluaran::class)->where('auditable_id', $id)
            ->where('event', 'updated')->get()->first(fn (AuditLog $l) => array_key_exists('sub_kategori', (array) $l->new_values));
        $this->assertNotNull($audit);
        $this->assertEqualsCanonicalizing(['sub_kategori', 'versi'], array_keys((array) $audit->new_values));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function perubahanLainProvider(): array
    {
        return [
            'nominal' => [['nominal' => 800_000]],
            'tanggal' => [['tanggal' => '2026-01-05']],
            'kategori' => [['kategori' => 'transportasi', 'sub_kategori' => null]],
        ];
    }

    /** @param array<string, mixed> $tambahan */
    #[DataProvider('perubahanLainProvider')]
    public function test_sub_kategori_bersama_nominal_tanggal_atau_kategori_tetap_mengajukan_ulang(array $tambahan): void
    {
        $id = $this->disetujui();
        Event::fake([PengeluaranDiajukan::class]);
        $data = $tambahan + ['sub_kategori' => 'gas'];

        $this->ubah($id, $data)->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');

        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
        $this->assertSame('diajukan', $this->raw($id)->status_persetujuan->value);
    }

    public function test_nominal_berubah_tanpa_sub_kategori_tetap_mengajukan_ulang(): void
    {
        $id = $this->disetujui();
        Event::fake([PengeluaranDiajukan::class]);

        $this->ubah($id, ['nominal' => 900_000])->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');
        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
    }

    public function test_nota_baru_bersama_sub_kategori_tetap_mengajukan_ulang(): void
    {
        $id = $this->disetujui();
        Event::fake([PengeluaranDiajukan::class]);

        $this->sebagai($this->akuntanA)->post("/api/v1/keuangan/pengeluaran/{$id}", [
            '_method' => 'PUT', 'versi' => $this->raw($id)->versi, 'sub_kategori' => 'gas', 'nota' => $this->fotoJpeg('nota.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');

        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
    }

    public function test_pengeluaran_diajukan_ubah_sub_kategori_perilaku_lama_pengajuan_baru(): void
    {
        $id = (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->body())->assertCreated()->json('data.id');
        Event::fake([PengeluaranDiajukan::class]);

        $this->ubah($id, ['sub_kategori' => 'gas'])->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');

        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
        $this->assertSame(2, $this->jumlahPengajuan($id));
    }

    public function test_pengeluaran_ditolak_ubah_sub_kategori_kembali_diajukan(): void
    {
        $id = (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->body())->assertCreated()->json('data.id');
        $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", ['keputusan' => 'ditolak', 'catatan' => 'Nominal tidak wajar', 'versi' => 1])
            ->assertOk()->assertJsonPath('data.status_persetujuan', 'ditolak');
        Event::fake([PengeluaranDiajukan::class]);

        $this->ubah($id, ['sub_kategori' => 'gas'])->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');

        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
    }

    public function test_versi_basi_tetap_409_dan_sub_kategori_tidak_berubah(): void
    {
        $id = $this->disetujui();
        $versi = $this->raw($id)->versi;

        $this->ubah($id, ['sub_kategori' => 'gas'], $versi)->assertOk();
        $res = $this->ubah($id, ['sub_kategori' => 'bbm'], $versi)->assertStatus(409);

        $this->assertSame('versi_tidak_cocok', $res->json('alasan'));
        $this->assertSame('gas', $this->raw($id)->sub_kategori->value);
        $this->assertSame('disetujui', $this->raw($id)->status_persetujuan->value);
    }

    public function test_pengeluaran_hasil_pembelian_tetap_ditolak_409(): void
    {
        $id = $this->disetujui();
        $this->forgetActor();
        $pembelian = $this->catatPembelian();
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$pembelian}/verifikasi", ['keputusan' => 'disetujui', 'versi' => $this->pembelianRaw($pembelian)->versi])->assertOk();
        $dariPembelian = Pengeluaran::withoutGlobalScope(SppgScope::class)->where('pembelian_bahan_id', $pembelian)->firstOrFail();

        // Kategori bahan_baku: sub_kategori ditolak validasi (422); ubah lain ditolak penjaga pembelian (409).
        $this->ubah($dariPembelian->id, ['sub_kategori' => 'gas'])->assertStatus(422)->assertJsonValidationErrors(['sub_kategori']);
        $res = $this->ubah($dariPembelian->id, ['uraian' => 'Koreksi uraian'])->assertStatus(409);
        $this->assertSame('pengeluaran_dari_pembelian', $res->json('alasan'));
        $this->assertNotSame($id, $dariPembelian->id);
    }

    public function test_sub_kategori_tidak_berlaku_untuk_bahan_baku_dan_insentif_fasilitas_tetap_422(): void
    {
        $bahan = $this->disetujui(['kategori' => 'bahan_baku', 'sub_kategori' => null]);
        $this->ubah($bahan, ['sub_kategori' => 'gas'])->assertStatus(422)->assertJsonValidationErrors(['sub_kategori']);

        $insentif = $this->disetujui(['kategori' => 'insentif_fasilitas', 'sub_kategori' => null]);
        $this->ubah($insentif, ['sub_kategori' => 'gas'])->assertStatus(422)->assertJsonValidationErrors(['sub_kategori']);
        $this->assertSame('disetujui', $this->raw($insentif)->status_persetujuan->value);
    }

    public function test_peran_tanpa_hak_dan_lintas_sppg_tetap_ditolak(): void
    {
        $id = $this->disetujui();

        foreach ([$this->kepalaA, $this->admin, $this->ahliGiziA, $this->stafGudangA, $this->relawanA] as $u) {
            $this->sebagai($u)->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['sub_kategori' => 'gas', 'versi' => 2])->assertForbidden();
        }

        $this->sebagai($this->investorAB, $this->sppgA)->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['sub_kategori' => 'gas', 'versi' => 2])->assertForbidden();

        $akuntanB = $this->userOf(Role::Akuntan, $this->sppgB);
        $this->sebagai($akuntanB)->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['sub_kategori' => 'gas', 'versi' => 2])->assertNotFound();

        $this->assertSame('listrik', $this->raw($id)->sub_kategori->value);
        $this->assertSame('disetujui', $this->raw($id)->status_persetujuan->value);
    }
}
