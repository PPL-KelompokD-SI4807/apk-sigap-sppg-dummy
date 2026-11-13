// Label sesi/perangkat (issue #77). Label berasal dari User-Agent klien dan disimpan server di kolom `name` token,
// jadi TIDAK tepercaya: selalu dirender sebagai teks (text node/textContent), tidak pernah sebagai HTML.
// strip_tags di backend bukan jaminan; yang menjamin adalah cara render ini.
import { h } from './dom.js';

export const LABEL_SESI_KOSONG = 'Perangkat tidak dikenal';
const MAKS_LABEL = 120;

/** Teks label siap tampil: string biasa (bukan HTML), tanpa karakter kontrol, dipangkas. Objek sesi atau string. */
export function teksLabelSesi(sesiAtauLabel) {
  const mentah = sesiAtauLabel != null && typeof sesiAtauLabel === 'object' ? sesiAtauLabel.perangkat : sesiAtauLabel;
  const bersih = String(mentah ?? '').replace(/[\u0000-\u001f\u007f-\u009f\u202a-\u202e\u2066-\u2069\u2028\u2029]+/g, ' ').replace(/\s+/g, ' ').trim();
  if (!bersih) return LABEL_SESI_KOSONG;
  return bersih.length > MAKS_LABEL ? `${bersih.slice(0, MAKS_LABEL - 1)}\u2026` : bersih;
}

/**
 * Elemen berisi label sesi. Isi diberikan lewat textContent, sehingga "<img onerror=...>" tampil apa adanya.
 * (h() juga memakai text node; helper ini menjadikan aturannya eksplisit dan dapat diuji.)
 */
export function elemenLabelSesi(sesiAtauLabel, { tag = 'span', kelas = '' } = {}) {
  const el = h(tag, kelas ? { class: kelas } : {});
  el.textContent = teksLabelSesi(sesiAtauLabel);
  return el;
}
