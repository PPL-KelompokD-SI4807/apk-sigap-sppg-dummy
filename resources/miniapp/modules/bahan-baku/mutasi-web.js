// Catat mutasi stok bahan baku di WEB (R04-8 PR-W1). Tanpa draf Agen AI (?draft=), tanpa antrean offline.
// Hanya untuk pemegang aksi.catat_mutasi_stok (guard rute). Idempotency-Key dibuat sekali per isian (kunciPerIsian): dipakai ulang
// pada pengulangan manual dengan isian sama, diganti bila isian berubah. Layar Telegram tetap di index.js (mutasiScreen).
import { createFormView } from '../../components/form-view.js';
import { h, clear } from '../../core/dom.js';
import { api, request, newKey } from '../../core/transport.js';
import { navigate, resolve } from '../../core/router.js';
import { toast } from '../../core/ui.js';
import { ringkasGalatAksi } from '../../core/errors.js';
import { kunciPerIsian } from '../../core/kunci-idempoten.js';
import { mutasiFields, cekMutasi, mutasiPayload, fmtQty } from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

/** Hasil permintaan tidak diketahui (jaringan putus / galat server): mutasi mungkin sudah tercatat, jadi arahkan periksa stok dulu. */
export const hasilTakPasti = (e) => e?.status === 0 || e?.status >= 500;

/**
 * Pesan galat mutasi -> {pesan, muatUlang}. Kunci idempotensi bentrok (422 pada Idempotency-Key) dan hasil tak pasti
 * tidak boleh mendorong kirim ulang buta: stok bisa bertambah dua kali.
 */
export function pesanGalatMutasi(e) {
  const g = ringkasGalatAksi(e, 'Mutasi gagal dicatat.');
  if (e?.status === 422 && e.errors && 'Idempotency-Key' in e.errors) {
    return { pesan: 'Permintaan sebelumnya mungkin sudah tercatat. Periksa stok dan riwayat mutasi sebelum mengirim ulang.', muatUlang: false };
  }
  if (hasilTakPasti(e)) return { pesan: `${g.pesan} Mutasi mungkin sudah tercatat: periksa stok di halaman bahan sebelum mengirim ulang.`, muatUlang: false };
  return { pesan: g.pesan, muatUlang: g.muatUlang };
}

export async function mutasiScreenWeb(ctx, slug = 'bahan-baku') {
  const rec = unwrap(await api.get(`/${slug}/${encodeURIComponent(ctx.params.id)}`));
  if (ctx.isStale()) return;
  ctx.setTitle(`Mutasi: ${rec.nama}`);
  clear(ctx.el);
  ctx.el.append(h('div', { class: 'panel' }, h('strong', {}, rec.nama), h('div', { class: 'muted' }, `Stok saat ini ${fmtQty(rec.stok, rec.satuan)} · minimum ${fmtQty(rec.stok_minimum)}`)));
  const kunciUntuk = kunciPerIsian(newKey);
  const konflik = h('div', { 'aria-live': 'assertive' });
  const fields = mutasiFields({ pemasokId: rec.pemasok_id }).map((f) => {
    if (f.name === 'jumlah') return { ...f, label: `Jumlah (${rec.satuan})`, validate: (v, all) => cekMutasi(all, rec.stok).jumlah || '' };
    if (f.name === 'catatan') return { ...f, validate: (v, all) => cekMutasi(all, rec.stok).catatan || '' };
    return f;
  });
  const fv = createFormView({
    fields,
    values: {},
    submitText: 'Catat mutasi',
    onSubmit: async (v) => {
      konflik.replaceChildren();
      const body = mutasiPayload(v);
      let res;
      try {
        res = await request('POST', `/${slug}/${rec.id}/mutasi`, { body, key: kunciUntuk(body), silent: true });
      } catch (e) {
        if (e?.status === 401) throw e;
        if (e?.status === 422 && e.errors && !('Idempotency-Key' in e.errors)) throw e; // galat per kolom ditampilkan form
        const g = pesanGalatMutasi(e);
        konflik.replaceChildren(h('div', { class: 'alert-danger', role: 'alert' }, g.pesan, ' ',
          g.muatUlang ? h('button', { class: 'btn', type: 'button', onclick: () => resolve() }, 'Muat ulang') : null));
        return;
      }
      toast(`Mutasi tercatat. Stok sekarang ${fmtQty(res?.bahan_baku?.stok, rec.satuan)}.`, 'success');
      navigate(`/${slug}/${rec.id}`, { replace: true });
    },
  });
  ctx.el.append(konflik, fv.el);
  return fv.destroy;
}
