// Catatan: bernama layar.js (bukan index.js) agar tidak ikut import.meta.glob registry Mini App.
// Layar Sesi Aktif & Browser Tepercaya (R04-9), mode web. Label perangkat/browser berasal dari User-Agent klien,
// jadi hanya dirender sebagai teks (core/sesi-label.js; h() memakai text node, bukan innerHTML).
import { h, clear, formatValue } from '../../core/dom.js';
import { klienWeb as klienBawaan } from '../../core/api-web.js';
import { showConfirm, toast } from '../../core/ui.js';
import { elemenLabelSesi } from '../../core/sesi-label.js';
import {
  urutkanSesi, ringkasKuota, teksKuota, labelJenis, konfirmasiCabutSesi, konfirmasiCabutBrowser, sisaHari,
} from './logika.js';

/**
 * renderSesiWeb(ctx, {klien, onKeluar}). onKeluar dipanggil setelah sesi ini berakhir (logout, cabut sesi/browser ini,
 * keluar semua) sehingga pemakai mengarahkan ke layar Masuk.
 */
export async function renderSesiWeb(ctx, { klien = klienBawaan, onKeluar } = {}) {
  const kuotaEl = h('p', { class: 'notice', 'aria-live': 'polite' });
  const listSesi = h('div', { class: 'cards' });
  const listBrowser = h('div', { class: 'cards' });
  const aksi = h('div', { class: 'stack' });
  ctx.el.append(
    h('p', { class: 'muted small' }, 'Perangkat dan browser yang saat ini masuk ke akun Anda. Bila ada yang tidak dikenal, hapus lalu ganti sandi Anda.'),
    kuotaEl,
    h('h2', { class: 'section-title' }, 'Sesi aktif'), listSesi,
    h('h2', { class: 'section-title' }, 'Browser tepercaya'),
    h('p', { class: 'muted small' }, 'Browser yang diingat 30 hari: login di sana cukup sandi, tanpa kode Telegram. Admin tidak memakai fitur ini.'),
    listBrowser, aksi);

  function kartuSesi(s) {
    const btn = h('button', { class: 'btn btn-danger', type: 'button', 'aria-label': `Hapus sesi ${s.perangkat || ''}` }, 'Hapus');
    btn.addEventListener('click', () => cabutSesi(s, btn));
    return h('div', { class: 'row-card' },
      h('span', { class: 'rc-main' },
        elemenLabelSesi(s, { kelas: 'rc-title' }),
        h('div', { class: 'rc-sub' }, `${labelJenis(s.jenis)}, terakhir dipakai ${formatValue(s.terakhir_dipakai_pada, 'datetime')}`),
        h('div', { class: 'rc-sub' }, `Masuk ${formatValue(s.diverifikasi_pada || s.dibuat_pada, 'datetime')}`),
        s.berlaku_sampai ? h('div', { class: 'rc-sub' }, `Berlaku sampai ${formatValue(s.berlaku_sampai, 'datetime')}`) : null),
      s.sesi_ini ? h('span', { class: 'badge badge-ok' }, 'Sesi ini') : null,
      s.browser_tepercaya_id ? h('span', { class: 'badge badge-info' }, 'Browser tepercaya') : null,
      btn);
  }

  function kartuBrowser(b) {
    const btn = h('button', { class: 'btn btn-danger', type: 'button', 'aria-label': `Cabut browser tepercaya ${b.label || ''}` }, 'Cabut');
    btn.addEventListener('click', () => cabutBrowser(b, btn));
    return h('div', { class: 'row-card' },
      h('span', { class: 'rc-main' },
        elemenLabelSesi(b.label, { kelas: 'rc-title' }),
        h('div', { class: 'rc-sub' }, `Diverifikasi ${formatValue(b.diverifikasi_pada, 'datetime')}`),
        h('div', { class: 'rc-sub' }, `Terakhir dipakai ${formatValue(b.terakhir_dipakai_pada, 'datetime')}`),
        h('div', { class: 'rc-sub' }, `Berlaku sampai ${formatValue(b.berlaku_sampai, 'datetime')} (${sisaHari(b.berlaku_sampai)} hari lagi)`)),
      b.browser_ini ? h('span', { class: 'badge badge-ok' }, 'Browser ini') : null,
      btn);
  }

  const isi = (box, items, kartu, kosong) => {
    clear(box);
    if (!items.length) box.append(h('p', { class: 'muted pad center' }, kosong));
    for (const x of items) box.append(kartu(x));
  };

  const muat = async () => {
    const [rs, rb] = await Promise.all([klien.daftarSesi(), klien.daftarBrowserTepercaya()]);
    if (ctx.isStale()) return;
    const sesi = urutkanSesi(rs?.data ?? []);
    const browser = rb?.data ?? [];
    kuotaEl.textContent = `Kuota terpakai: ${teksKuota(ringkasKuota(sesi, browser, rs?.meta, rb?.meta))}.`;
    isi(listSesi, sesi, kartuSesi, 'Tidak ada sesi aktif.');
    isi(listBrowser, browser, kartuBrowser, 'Belum ada browser tepercaya.');
  };

  const galatAksi = (e, fallback, btn) => {
    if (btn) btn.disabled = false;
    if (e?.status === 404) { toast('Sudah tidak ada.', 'info'); return muat().catch(() => {}); }
    if (e?.status !== 401) toast(e?.message || fallback, 'error'); // 401: klien web sudah memicu event sesi-habis
    return null;
  };

  async function cabutSesi(s, btn) {
    if (!(await showConfirm({ title: 'Hapus sesi', message: konfirmasiCabutSesi(s), okText: 'Hapus', destructive: true }))) return;
    btn.disabled = true;
    try {
      const r = await klien.cabutSesi(s.id);
      if (s.sesi_ini || r?.sesi_ini) { onKeluar?.(); return; }
      toast('Sesi dihapus.', 'success');
      await muat();
    } catch (e) { galatAksi(e, 'Gagal menghapus sesi.', btn); }
  }

  async function cabutBrowser(b, btn) {
    if (!(await showConfirm({ title: 'Cabut browser tepercaya', message: konfirmasiCabutBrowser(b), okText: 'Cabut', destructive: true }))) return;
    btn.disabled = true;
    try {
      const r = await klien.cabutBrowserTepercaya(b.id);
      if (b.browser_ini || r?.browser_ini) { onKeluar?.(); return; }
      toast('Browser tepercaya dicabut.', 'success');
      await muat(); // sesi web turunan ikut berakhir: muat ulang kedua daftar
    } catch (e) { galatAksi(e, 'Gagal mencabut browser tepercaya.', btn); }
  }

  const keluarIni = h('button', { class: 'btn btn-block', type: 'button' }, 'Keluar dari browser ini');
  keluarIni.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Keluar', message: 'Keluar dari browser ini? Browser tepercaya tidak dicabut.', okText: 'Keluar' }))) return;
    keluarIni.disabled = true;
    try { await klien.logout(); onKeluar?.(); } catch (e) { keluarIni.disabled = false; toast(e?.message || 'Gagal keluar.', 'error'); }
  });
  const keluarSemuaBtn = h('button', { class: 'btn btn-danger btn-block', type: 'button' }, 'Keluar dari semua perangkat');
  keluarSemuaBtn.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Keluar dari semua perangkat', message: 'Semua sesi (Mini App dan web) dan semua browser tepercaya dicabut, termasuk di sini. Lanjutkan?', okText: 'Keluar semua', destructive: true }))) return;
    keluarSemuaBtn.disabled = true;
    try {
      const r = await klien.keluarSemua();
      toast(`${r?.jumlah_sesi ?? 0} sesi dikeluarkan.`, 'success');
      onKeluar?.();
    } catch (e) { galatAksi(e, 'Gagal keluar dari semua perangkat.', keluarSemuaBtn); }
  });
  aksi.append(keluarIni, keluarSemuaBtn);

  await muat();
}
