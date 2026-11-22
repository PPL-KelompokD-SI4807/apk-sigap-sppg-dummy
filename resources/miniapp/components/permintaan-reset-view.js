// Admin: permintaan reset password dari pengguna dalam scope. Admin tidak melihat atau menetapkan password.
import { h, clear, formatValue } from '../core/dom.js';
import { daftarPermintaanReset, putuskanReset } from '../core/auth.js';
import { showConfirm, toast } from '../core/ui.js';
import { errorMessage } from '../core/errors.js';
import { infoStatusReset, pesanGalatAkun } from '../core/kredensial.js';

export async function renderPermintaanReset(ctx) {
  const list = h('div', { class: 'cards' });
  ctx.el.append(
    h('p', { class: 'notice' }, 'Pastikan identitas pemohon di luar aplikasi (mis. telepon atau bertemu langsung) sebelum menyetujui. Pemohon menetapkan password barunya sendiri dengan kode dari bot Telegram.'),
    list);

  async function muat() {
    const data = await daftarPermintaanReset('menunggu');
    if (ctx.isStale()) return;
    clear(list);
    if (!data.length) list.append(h('p', { class: 'muted pad center' }, 'Tidak ada permintaan menunggu.'));
    for (const p of data) list.append(kartu(p));
  }

  function kartu(p) {
    const u = p.pengguna || {};
    const setuju = h('button', { class: 'btn btn-primary', type: 'button' }, 'Setujui');
    const tolak = h('button', { class: 'btn btn-danger', type: 'button' }, 'Tolak');
    const putus = async (ya) => {
      const nama = u.name || 'pengguna ini';
      if (!(await showConfirm({
        title: ya ? 'Setujui permintaan' : 'Tolak permintaan',
        okText: ya ? 'Setujui' : 'Tolak',
        destructive: !ya,
        message: ya ? `Setujui reset password untuk ${nama}? Pastikan Anda sudah memastikan identitasnya.` : `Tolak permintaan reset password ${nama}?`,
      }))) return;
      setuju.disabled = true;
      tolak.disabled = true;
      try {
        await putuskanReset(p.id, ya);
        toast(ya ? 'Permintaan disetujui.' : 'Permintaan ditolak.', 'success');
        await muat();
      } catch (e) {
        setuju.disabled = false;
        tolak.disabled = false;
        if (e?.status === 409) { toast('Permintaan sudah diputuskan atau kedaluwarsa.', 'info'); return muat().catch(() => {}); }
        if (e?.status !== 401) toast(pesanGalatAkun(e) || errorMessage(e, 'Aksi gagal.'), 'error');
      }
    };
    setuju.addEventListener('click', () => putus(true));
    tolak.addEventListener('click', () => putus(false));
    const info = infoStatusReset(p.status);
    return h('div', { class: 'panel stack' },
      h('div', { class: 'row-card' },
        h('span', { class: 'rc-main' },
          h('span', { class: 'rc-title' }, u.name || 'Pengguna'),
          h('div', { class: 'rc-sub' }, [u.username ? `@${u.username}` : null, u.role_label].filter(Boolean).join(' · ')),
          h('div', { class: 'rc-sub' }, `Diminta ${formatValue(p.dibuat_pada, 'datetime')} · berlaku hingga ${formatValue(p.kedaluwarsa_pada, 'datetime')}`)),
        h('span', { class: `badge ${info.cls}` }, info.label)),
      h('div', { class: 'stack' }, setuju, tolak));
  }

  await muat();
}
