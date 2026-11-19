// Layar Ajukan Menu ke Investor di web (R04-8 PR-W4): ahli gizi memilih menu lalu mengajukannya (POST /menu-gizi/{id}/ajukan).
// Modul Menu dan Gizi belum ada di web, jadi pengajuan dilakukan dari sini. Hak tombol dari peta hak server (aksi.ajukan_menu) dan
// status menu di record; harga/biaya/porsi dihitung server (muatan hanya versi + catatan_pengaju). Tanpa antrean offline.
import { h } from '../../core/dom.js';
import { request, newKey } from '../../core/transport.js';
import { getUser } from '../../core/auth.js';
import { navigate, resolve } from '../../core/router.js';
import { toast, showConfirm } from '../../core/ui.js';
import { createListView } from '../../components/list-view.js';
import { SLUG, bolehAjukan, payloadAjukan, ringkasGalat, kunciPerIsian, keteranganTakBisaDiajukan, statusBadge, statusLabel, MAKS_CATATAN } from './logika.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

function formAjukan(menu) {
  const catatan = h('textarea', { class: 'input', rows: '2', maxlength: String(MAKS_CATATAN), 'aria-label': `Catatan untuk investor (${menu.nama_menu})`, placeholder: 'Catatan untuk investor (opsional)' });
  const hasil = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  const btn = h('button', { class: 'btn btn-primary', type: 'button' }, 'Ajukan ke Investor');
  const kunciUntuk = kunciPerIsian(newKey);
  btn.addEventListener('click', async () => {
    const body = payloadAjukan({ versi: menu.versi, catatan: catatan.value });
    if (!(await showConfirm({ title: 'Ajukan ke Investor', message: `Ajukan "${menu.nama_menu}" (versi ${menu.versi}) ke investor? Harga dan porsi dihitung otomatis oleh sistem.`, okText: 'Ya, ajukan' }))) return;
    btn.disabled = true;
    hasil.replaceChildren();
    try {
      const res = await request('POST', `/menu-gizi/${encodeURIComponent(menu.id)}/ajukan`, { body, key: kunciUntuk(body), silent: true });
      toast('Menu diajukan ke investor.', 'success');
      const id = unwrap(res)?.id;
      if (id) navigate(`/${SLUG}/${id}`, { replace: true }); else resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalat(e, 'Pengajuan gagal.');
      toast(g.pesan, 'error', 6000);
      if (g.muatUlang) { resolve(); return; }
      if (g.bahan.length) {
        hasil.append(h('div', { class: 'alert-danger', role: 'alert' }, 'Bahan berikut belum punya harga berlaku atau konversi satuan:',
          h('ul', { class: 'stack-tight' }, g.bahan.map((b) => h('li', {}, h('strong', {}, b.nama), `: ${b.masalah}`)))));
      } else if (Object.keys(g.fields).length) hasil.append(h('div', { class: 'alert-danger', role: 'alert' }, Object.values(g.fields).join(' ')));
      btn.disabled = false;
    }
  });
  return h('div', { class: 'stack-tight' }, catatan, btn, hasil);
}

function kartuMenu(menu) {
  const status = menu.status_persetujuan;
  return h('div', { class: 'panel stack-tight' },
    h('div', { class: 'card-title' }, h('strong', {}, menu.nama_menu ?? `Menu #${menu.id}`),
      h('span', { class: `badge ${statusBadge(status)}`.trim() }, menu.status_persetujuan_label || statusLabel(status))),
    h('p', { class: 'muted small' }, `Versi ${menu.versi ?? '-'}`),
    bolehAjukan(getUser(), status) ? formAjukan(menu) : h('p', { class: 'muted small' }, keteranganTakBisaDiajukan(status)));
}

/** Layar #/persetujuan-menu/baru (guard: aksi.ajukan_menu dari server). */
export function ajukanScreen(ctx) {
  ctx.setTitle('Ajukan Menu ke Investor');
  ctx.el.append(
    h('div', { class: 'notice' }, 'Pilih menu yang akan diajukan. Hanya menu yang disetujui investor yang dapat dipakai pada Jadwal Produksi.'),
    createListView({
      endpoint: '/menu-gizi',
      columns: [{ key: 'nama_menu', primary: true }],
      searchPlaceholder: 'Cari nama menu...',
      emptyText: 'Belum ada menu.',
      itemHref: () => `#/${SLUG}`,
      renderCard: (m) => kartuMenu(m),
    }).el);
}
