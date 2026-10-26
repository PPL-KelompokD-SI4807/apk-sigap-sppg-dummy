// Admin: kelola SPPG yang dimiliki pengguna ber-peran investor (tautkan / lepas).
import { h, clear } from '../core/dom.js';
import { api, fetchList } from '../core/api.js';
import { showConfirm, toast } from '../core/ui.js';
import { errorMessage } from '../core/errors.js';
import { sppgBelumDimiliki } from '../core/sppg-aktif.js';

const opts = { silent: true };
const unwrap = (r) => r?.data ?? r;

export function kepemilikanSppgPanel(rec) {
  const base = `/pengguna/${encodeURIComponent(rec.id)}/kepemilikan-sppg`;
  const body = h('div', { class: 'stack' }, h('p', { class: 'muted small' }, 'Memuat...'));
  const root = h('div', { class: 'panel stack' }, h('h2', { class: 'panel-title' }, 'SPPG yang dimiliki'), body);
  let dimiliki = [];

  async function muat() {
    try {
      dimiliki = unwrap(await api.get(base, undefined, opts))?.sppg ?? [];
    } catch (e) {
      clear(body);
      body.append(h('p', { class: 'notice' }, errorMessage(e, 'Gagal memuat kepemilikan SPPG.')));
      return;
    }
    gambar();
  }

  function gambar() {
    clear(body);
    if (!dimiliki.length) body.append(h('p', { class: 'muted small' }, 'Belum memiliki SPPG. Tautkan minimal satu agar investor dapat melihat data.'));
    for (const s of dimiliki) {
      const lepas = h('button', { class: 'btn btn-danger', type: 'button', 'aria-label': `Lepas ${s.nama}` }, 'Lepas');
      lepas.addEventListener('click', async () => {
        if (!(await showConfirm({ title: 'Lepas SPPG', message: `Lepas ${s.nama} dari ${rec.name}? Investor tidak lagi dapat melihat data SPPG ini.`, okText: 'Lepas', destructive: true }))) return;
        lepas.disabled = true;
        try { await api.del(`${base}/${encodeURIComponent(s.id)}`, opts); toast('SPPG dilepas.', 'success'); await muat(); }
        catch (e) { lepas.disabled = false; if (e?.status !== 401) toast(errorMessage(e, 'Gagal melepas SPPG.'), 'error'); }
      });
      body.append(h('div', { class: 'row-card' },
        h('span', { class: 'rc-main' }, h('span', { class: 'rc-title' }, s.nama), h('div', { class: 'rc-sub' }, s.kode || '')), lepas));
    }
    const pilih = h('select', { 'aria-label': 'Pilih SPPG untuk ditautkan' }, h('option', { value: '' }, 'Memuat daftar SPPG...'));
    const tambah = h('button', { class: 'btn btn-primary', type: 'button', disabled: true }, 'Tautkan');
    fetchList('/sppg', { perPage: 100 }).then(({ items }) => {
      const sisa = sppgBelumDimiliki(items, dimiliki);
      pilih.replaceChildren(h('option', { value: '' }, sisa.length ? 'Pilih SPPG...' : 'Semua SPPG sudah ditautkan'),
        ...sisa.map((s) => h('option', { value: String(s.id) }, s.nama)));
      tambah.disabled = !sisa.length;
    }).catch(() => pilih.replaceChildren(h('option', { value: '' }, 'Gagal memuat daftar SPPG')));
    tambah.addEventListener('click', async () => {
      const id = Number(pilih.value);
      if (!id) return toast('Pilih SPPG terlebih dahulu.', 'error');
      tambah.disabled = true;
      try { await api.post(base, { sppg_id: id }, opts); toast('SPPG ditautkan.', 'success'); await muat(); }
      catch (e) { tambah.disabled = false; if (e?.status !== 401) toast(errorMessage(e, 'Gagal menautkan SPPG.'), 'error'); }
    });
    body.append(h('div', { class: 'stack' }, pilih, tambah));
  }

  muat();
  return root;
}
