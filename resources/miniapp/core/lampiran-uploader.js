// Status unggah foto untuk form modul (keluhan, mutu, log produksi): murni, tanpa DOM, agar dapat diuji dengan node --test.
//
// Alur kontrak (docs/api/paths/lampiran.yaml): tiap foto langsung diunggah ke POST /lampiran TANPA lampiranable_id
// (unggahan "tertunda"), server membalas {data:{id,...}}; saat Simpan, id terunggah dikirim sebagai `lampiran_ids`
// pada store/update modul (PengikatLampiran). Unggahan yang gagal/belum selesai memblokir Simpan.
import { firstError, PESAN_413, PESAN_429, PESAN_409 } from './errors.js';

// Cermin config/unggahan.php (server tetap otoritas): jenis diterima, batas ukuran, jumlah per record.
export const TIPE_UNGGAH = ['image/jpeg', 'image/png', 'image/webp'];
export const MAKS_BYTE = 5 * 1024 * 1024;
export const MAKS_LAMPIRAN = 5;

export const STATUS = { mengunggah: 'mengunggah', terunggah: 'terunggah', gagal: 'gagal' };

const MB = (b) => `${Math.round(b / 1024 / 1024)} MB`;

/** Pemeriksaan berkas yang akan dikirim (hasil kompres): jenis & ukuran sesuai config unggahan. '' bila lolos. */
export function validasiBerkasUnggah(file, { tipe = TIPE_UNGGAH, maks = MAKS_BYTE } = {}) {
  if (!file) return 'Tidak ada berkas dipilih.';
  if (!(file.size > 0)) return 'Berkas kosong (0 byte).';
  if (!tipe.includes(file.type)) return 'Hanya foto JPEG, PNG, atau WebP yang diterima.';
  if (file.size > maks) return `Ukuran foto maksimal ${MB(maks)}.`;
  return '';
}

/** Pesan galat unggah (offline/413/422/429/409/403/404/5xx) berbahasa Indonesia. */
export function pesanGalatUnggah(e) {
  const s = e?.status;
  if (s === 0) return 'Tidak ada koneksi internet. Foto belum terunggah; coba lagi saat online.';
  if (s === 413) return PESAN_413;
  if (s === 429) return PESAN_429;
  if (s === 409) return PESAN_409;
  if (s === 422) return firstError(e.errors) || 'Berkas ditolak. Gunakan foto JPEG, PNG, atau WebP maksimal 5 MB.';
  if (s === 403) return 'Anda tidak punya hak mengunggah foto untuk data ini.';
  if (s === 404) return 'Data induk tidak ditemukan atau sudah dihapus.';
  if (s >= 500) return 'Terjadi gangguan pada server. Coba lagi nanti.';
  return e?.message || 'Unggahan gagal.';
}

/** Pesan yang memblokir Simpan bila ada foto belum selesai/gagal. '' bila aman. */
export function pesanBlokirSimpan({ mengunggah = 0, gagal = 0 } = {}) {
  if (mengunggah > 0) return `Tunggu ${mengunggah} foto selesai diunggah.`;
  if (gagal > 0) return `${gagal} foto gagal diunggah. Coba lagi atau hapus foto tersebut sebelum menyimpan.`;
  return '';
}

/**
 * createUploadState({modul, kategori, max, tersimpan, sppgId, upload, newKey})
 *  - tersimpan: jumlah lampiran yang sudah ada di record (mengurangi kapasitas; server menegakkan total <= max).
 *  - sppgId(): ID SPPG untuk Admin (server mewajibkan sppg_id pada unggahan tertunda oleh Admin); null untuk peran lain.
 *  - upload({modul, kategori, file, key, sppgId, onProgress}) -> Promise<{data:{id}}> | {id}; melempar ApiError.
 * Item: {key, file, url, status, progress, error, id}.
 * Setiap foto punya `key` tetap = Idempotency-Key: "Coba lagi" memakai kunci yang sama sehingga tidak ganda.
 */
export function createUploadState({ modul, kategori = 'foto', max = MAKS_LAMPIRAN, tersimpan = 0, sppgId = () => null, upload, newKey = () => Math.random().toString(16).slice(2) }) {
  const items = [];
  const listeners = new Set();
  const emit = () => listeners.forEach((fn) => fn(items));

  const tersedia = () => Math.max(0, max - tersimpan - items.filter((i) => i.status !== STATUS.gagal).length);

  async function kirim(item) {
    item.status = STATUS.mengunggah;
    item.progress = 0;
    item.error = '';
    emit();
    try {
      const res = await upload({
        modul, kategori, file: item.file, key: item.key, sppgId: sppgId(),
        onProgress: (p) => { item.progress = p; emit(); },
      });
      const id = Number(res?.data?.id ?? res?.id);
      if (!Number.isInteger(id) || id < 1) throw new Error('Respons unggahan tidak valid.');
      item.id = id;
      item.status = STATUS.terunggah;
      item.progress = 100;
    } catch (e) {
      item.status = STATUS.gagal;
      item.error = pesanGalatUnggah(e);
    }
    emit();
    return item;
  }

  return {
    items,
    max,
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    tersedia,
    /** Tambah foto terpilih. -> {ok:true, item, selesai:Promise} | {ok:false, error}. Foto ditolak tidak masuk daftar. */
    add({ file, key = newKey(), url = null }) {
      const salah = validasiBerkasUnggah(file);
      if (salah) return { ok: false, error: salah };
      if (tersedia() < 1) return { ok: false, error: `Batas ${max} foto tercapai.` };
      const item = { key, file, url, status: STATUS.mengunggah, progress: 0, error: '', id: null };
      items.push(item);
      return { ok: true, item, selesai: kirim(item) };
    },
    retry(key) {
      const item = items.find((i) => i.key === key);
      return item && item.status === STATUS.gagal ? kirim(item) : Promise.resolve(null);
    },
    remove(key) {
      const i = items.findIndex((x) => x.key === key);
      if (i < 0) return null;
      const [gone] = items.splice(i, 1);
      emit();
      return gone;
    },
    /** ID lampiran terunggah untuk `lampiran_ids`. */
    ids: () => items.filter((i) => i.status === STATUS.terunggah).map((i) => i.id),
    hitung: () => ({
      mengunggah: items.filter((i) => i.status === STATUS.mengunggah).length,
      gagal: items.filter((i) => i.status === STATUS.gagal).length,
      terunggah: items.filter((i) => i.status === STATUS.terunggah).length,
    }),
    blokirSimpan() { return pesanBlokirSimpan(this.hitung()); },
  };
}

/** Pemanggil POST /lampiran tertunda: body kontrak LampiranInput tanpa lampiranable_id (+ sppg_id untuk Admin). */
export function bodyUnggahTertunda({ modul, kategori, file, sppgId }) {
  const body = { modul, kategori, berkas: file };
  if (sppgId != null && sppgId !== '') body.sppg_id = Number(sppgId);
  return body;
}

/** `lampiran_ids` untuk payload store/update: undefined bila kosong (tidak dikirim). */
export const lampiranIdsPayload = (ids) => (Array.isArray(ids) && ids.length ? [...new Set(ids.map(Number))] : undefined);
