// Pemformat murni "Pesan ke PIC" (notifikasi_pic jadwal distribusi).
import test from 'node:test';
import assert from 'node:assert/strict';
import { barisPesanPic, statusPicInfo } from '../modules/jadwal/schema.js';

test('null: tidak ada baris', () => {
  assert.deepEqual(barisPesanPic(null), []);
  assert.deepEqual(barisPesanPic(undefined), []);
});

test('riwayat: satu baris per entri dengan jenis, badge, jam HH:MM', () => {
  const b = barisPesanPic({
    jenis: 'diterima', status_kirim: 'terkirim', waktu: '2026-11-02T09:10:04+07:00', dialihkan_ke_kepala: false,
    riwayat: [
      { jenis: 'berangkat', status_kirim: 'dialihkan_ke_kepala', waktu: '2026-11-02T08:30:03+07:00' },
      { jenis: 'diterima', status_kirim: 'terkirim', waktu: '2026-11-02T09:10:04+07:00' },
    ],
  });
  assert.equal(b.length, 2);
  assert.deepEqual(b[0], { jenis: 'Berangkat', label: 'Dialihkan ke Kepala SPPG', cls: 'badge-info', jam: '08:30' });
  assert.deepEqual(b[1], { jenis: 'Diterima', label: 'Terkirim', cls: 'badge-ok', jam: '09:10' });
});

test('tanpa riwayat: entri teratas jadi satu baris; status dikenal', () => {
  const b = barisPesanPic({ jenis: 'berangkat', status_kirim: 'menunggu', waktu: '2026-11-02T08:30:03+07:00' });
  assert.equal(b.length, 1);
  assert.equal(b[0].label, 'Menunggu');
  assert.equal(statusPicInfo('gagal').label, 'Gagal');
  assert.equal(statusPicInfo('gagal').cls, 'badge-danger');
});
