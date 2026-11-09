// Penjaga kirim formulir. Murni (semua efek DOM disuntik) agar bisa diuji dengan node --test.
import { galatDraf, PESAN_DRAF_TIDAK_COCOK } from './errors.js';

/**
 * buatPenjagaSubmit({validate, kirim, setLoading, setDisabled, onGalatDraf, onGalat}) -> {jalankan, terkunci}
 *  - Satu permintaan pada satu waktu: panggilan saat permintaan berjalan diabaikan (tombol juga dinonaktifkan lewat setLoading).
 *  - Tidak ada kirim ulang otomatis: setiap permintaan berasal dari satu ketukan pengguna.
 *  - 422 pada `ai_draft_id` (draf tidak cocok/terpakai/kedaluwarsa): formulir TERKUNCI. Draf yang sama pasti ditolak lagi,
 *    jadi tombol simpan tetap nonaktif dan pengguna diminta membuka ulang dari bot (onGalatDraf menampilkan alert).
 *  - Galat lain diteruskan ke onGalat (mis. galat per kolom); tombol aktif lagi.
 */
export function buatPenjagaSubmit({ validate = () => true, kirim, setLoading, setDisabled, onGalatDraf, onGalat }) {
  let berjalan = false;
  let terkunci = false;
  return {
    get terkunci() { return terkunci; },
    get berjalan() { return berjalan; },
    async jalankan() {
      if (berjalan || terkunci || !validate()) return false;
      berjalan = true;
      setLoading(true);
      try {
        await kirim();
        return true;
      } catch (e) {
        if (galatDraf(e)) {
          terkunci = true;
          onGalatDraf?.(PESAN_DRAF_TIDAK_COCOK, e);
        } else onGalat?.(e);
        return false;
      } finally {
        berjalan = false;
        setLoading(false);
        if (terkunci) setDisabled(true);
      }
    },
  };
}
