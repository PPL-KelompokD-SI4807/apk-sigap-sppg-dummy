// Log Audit (fitur 1, temuan P13): Admin, hanya baca. Semua nilai dirender sebagai teks (h() memakai text node).
import { h, clear } from '../../core/dom.js';
import { api } from '../../core/transport.js';
import { getUser } from '../../core/auth.js';
import { route } from '../../core/router.js';
import { labelAksi, bolehLogAudit, waktuSingkat, namaPengguna, diffNilai, bangunQuery, pesanGalat } from './logika.js';

const SLUG = 'audit-log';
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

async function layarDaftar(ctx) {
  ctx.setTitle('Log Audit');
  const f = { modul: '', aksi: '', user_id: '', from: '', to: '' };
  let page = 1;
  const selModul = h('select', { id: 'f-modul', 'aria-label': 'Modul' }, h('option', { value: '' }, 'Semua modul'));
  const selAksi = h('select', { id: 'f-aksi', 'aria-label': 'Aksi' }, h('option', { value: '' }, 'Semua aksi'));
  const inUser = h('input', { type: 'number', inputmode: 'numeric', min: '1', id: 'f-user', placeholder: 'ID pengguna', 'aria-label': 'ID pengguna' });
  const inFrom = h('input', { type: 'date', id: 'f-from', 'aria-label': 'Dari tanggal' });
  const inTo = h('input', { type: 'date', id: 'f-to', 'aria-label': 'Sampai tanggal' });
  const info = h('p', { class: 'muted small', role: 'status', 'aria-live': 'polite' });
  const galat = h('p', { class: 'field-error', role: 'alert' });
  const daftar = h('div', { class: 'cards' });
  const btnLagi = h('button', { class: 'btn btn-block', type: 'button', hidden: true }, 'Muat lebih banyak');

  const kartu = (it) => h('a', { class: 'row-card', href: `#/${SLUG}/${it.id}` },
    h('span', { class: 'rc-main' },
      h('div', { class: 'rc-title' }, `${it.modul_label || it.modul} • ${labelAksi(it.aksi)}`),
      h('div', { class: 'rc-sub' }, `${waktuSingkat(it.waktu)} • ${namaPengguna(it)} • ${it.sumber || '-'}`)));

  async function muat(tambah) {
    galat.textContent = '';
    if (!tambah) { page = 1; clear(daftar); }
    btnLagi.disabled = true;
    try {
      const res = await api.get('/audit-log', bangunQuery(f, page), { silent: true });
      if (ctx.isStale?.()) return;
      for (const it of res?.data ?? []) daftar.append(kartu(it));
      const m = res?.meta ?? {};
      info.textContent = m.total ? `${m.total} catatan` : '';
      if (!daftar.children.length) daftar.append(h('p', { class: 'muted pad center' }, 'Tidak ada catatan.'));
      btnLagi.hidden = !(m.current_page < m.last_page);
      btnLagi.disabled = false;
    } catch (e) {
      if (e?.status === 401) throw e;
      btnLagi.hidden = true;
      galat.textContent = pesanGalat(e);
    }
  }
  btnLagi.addEventListener('click', () => { page += 1; muat(true); });
  const ubah = (k, el) => el.addEventListener('change', () => { f[k] = el.value; muat(false); });
  ubah('modul', selModul); ubah('aksi', selAksi); ubah('user_id', inUser); ubah('from', inFrom); ubah('to', inTo);

  ctx.el.append(h('div', { class: 'panel stack-tight' },
    h('div', { class: 'filter-row' }, selModul, selAksi),
    h('div', { class: 'filter-row' }, inUser),
    h('div', { class: 'filter-row' }, inFrom, inTo),
    info, galat, daftar, btnLagi));

  api.get('/audit-log/opsi', undefined, { silent: true }).then((r) => {
    const o = unwrap(r) ?? {};
    for (const m of o.modul ?? []) selModul.append(h('option', { value: m.kode }, m.label));
    for (const a of o.aksi ?? []) selAksi.append(h('option', { value: a }, labelAksi(a)));
  }).catch(() => {});
  await muat(false);
}

async function layarDetail(ctx) {
  ctx.setTitle('Detail Log Audit');
  const box = h('div', { class: 'panel stack-tight' });
  ctx.el.append(box, h('a', { class: 'btn btn-block', href: `#/${SLUG}` }, 'Kembali ke daftar'));
  try {
    const it = unwrap(await api.get(`/audit-log/${encodeURIComponent(ctx.params.id)}`, undefined, { silent: true }));
    if (ctx.isStale?.()) return;
    box.append(
      h('h2', { class: 'panel-title' }, `${it.modul_label || it.modul} • ${labelAksi(it.aksi)}`),
      h('p', { class: 'muted small' }, `${waktuSingkat(it.waktu)} • ${namaPengguna(it)} • ${it.sumber || '-'} • rekaman #${it.rekaman_id ?? '-'}`));
    const baris = diffNilai(it.nilai_lama, it.nilai_baru);
    if (!baris.length) { box.append(h('p', { class: 'muted' }, 'Tidak ada perubahan nilai.')); return; }
    box.append(h('div', { style: 'overflow-x:auto' }, h('table', { class: 'table', style: 'width:100%;word-break:break-word' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Kolom'), h('th', {}, 'Nilai lama'), h('th', {}, 'Nilai baru'))),
      h('tbody', {}, baris.map((r) => h('tr', {}, h('td', {}, r.kolom), h('td', {}, r.lama), h('td', {}, r.baru)))))));
  } catch (e) {
    if (e?.status === 401) throw e;
    box.append(h('p', { class: e?.status === 404 ? 'muted' : 'field-error', role: 'alert' }, e?.status === 404 ? 'Catatan tidak ditemukan.' : pesanGalat(e)));
  }
}

const daftarRute = () => {
  const opts = { guard: () => bolehLogAudit(getUser()) };
  route(`/${SLUG}`, layarDaftar, { title: 'Log Audit', ...opts });
  route(`/${SLUG}/:id`, layarDetail, { title: 'Detail Log Audit', ...opts });
};

export default {
  slug: SLUG,
  bolehMenu: bolehLogAudit,
  title: 'Log Audit',
  icon: '🧾',
  description: 'Riwayat perubahan data (hanya baca)',
  register: daftarRute,
  registerWeb: daftarRute,
};
