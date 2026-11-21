// Layar WEB modul Data Pengguna (PR-W3). Dipasang lewat registerWeb (rute-web.js: BACA_WEB + TULIS_WEB).
// Bernama web.js (bukan index.js) agar tidak ikut import.meta.glob registry.
//
// Aturan keamanan (T3):
// - Hak tombol HANYA dari peta server (permissions/aksi pada GET /auth/me); server tetap menegakkan (403).
// - Peran R* (profil sendiri) tidak pernah memanggil daftar GET /pengguna: daftar dialihkan ke detail milik sendiri.
// - Tanpa draf AI, antrean offline, atau berbagi Telegram (t.me/share). Undangan web = tombol Salin tautan.
// - Tautan undangan memuat token sekali pakai: hanya hidup di dialog (teks), tidak pernah ke URL/hash, storage, console,
//   toast, atau pesan galat; dikosongkan saat dialog ditutup. Respons undangan diringkas (ringkasUndangan) sehingga
//   kolom `token` mentah tidak disimpan di variabel mana pun.
// - Reset password oleh Admin tidak menghasilkan password (server mengosongkan password; pengguna mengatur sendiri
//   lewat Mini App + kode bot). Respons server tidak dirender: klien memakai teks tetap.
import { h, clear, formatValue } from '../../core/dom.js';
import { request } from '../../core/transport.js';
import { getUser, daftarSesi } from '../../core/auth.js';
import { userPerms, bolehAksi } from '../../core/permissions.js';
import { route, navigate } from '../../core/router.js';
import { showConfirm, toast } from '../../core/ui.js';
import { ringkasGalatAksi } from '../../core/errors.js';
import { isTelegramLink, hoursLeft } from '../../core/share.js';
import { elemenLabelSesi } from '../../core/sesi-label.js';
import { createListView } from '../../components/list-view.js';
import { createFormView } from '../../components/form-view.js';
import { renderPermintaanReset } from '../../components/permintaan-reset-view.js';
import { renderSesiPengguna } from '../../components/sesi-view.js';
import { kepemilikanSppgPanel } from '../../components/kepemilikan-sppg.js';
import {
  STATUS_UNDANGAN, undanganCls, undanganLabel, undanganTarget, undanganFields, buildInvitePayload,
} from './schema.js';

const bolehUndang = () => bolehAksi(getUser(), 'undang_pengguna');
const bolehResetSesi = () => bolehAksi(getUser(), 'reset_password_pengguna');

export const PESAN_RESET_BERHASIL = 'Password direset dan semua sesi pengguna dicabut. Pengguna mengatur password baru lewat Mini App SiGAP SPPG dengan kode dari bot.';
export const PESAN_UBAH_PASSWORD = 'Ubah password lewat Mini App SiGAP SPPG di Telegram (menu Perangkat & Sesi).';
export const PESAN_TANPA_TAUTAN = 'Undangan dibuat, tetapi tautan Telegram belum tersedia karena nama bot belum diatur di server. Hubungi admin sistem lalu buat undangan ulang.';

// ---- Undangan (murni + dialog) ----

/**
 * Ringkas respons POST /pengguna/undangan menjadi {link, jam}. HANYA tautan t.me yang sah (core/share.js) dan sisa jam
 * yang diambil; `token` mentah dan kolom lain dibuang agar tidak tertahan di memori pemanggil.
 */
export function ringkasUndangan(res) {
  const inv = res && typeof res === 'object' && res.data && typeof res.data === 'object' && !Array.isArray(res.data) ? res.data : res;
  const link = isTelegramLink(inv?.deep_link) ? inv.deep_link : null;
  return { link, jam: link ? hoursLeft(inv?.expires_at) : null };
}

/** Salin ke papan klip (Clipboard API, konteks aman). false bila tidak didukung/ditolak: pengguna menyalin manual. */
export async function salinTeks(teks) {
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) { await navigator.clipboard.writeText(teks); return true; }
  } catch { /* ditolak peramban: salin manual */ }
  return false;
}

/** Pilih isi elemen agar pengguna dapat menekan Ctrl+C (cadangan bila Clipboard API tidak tersedia). */
function pilihIsi(el) {
  try {
    const sel = typeof window !== 'undefined' ? window.getSelection?.() : null;
    const r = typeof document.createRange === 'function' ? document.createRange() : null;
    if (!sel || !r) return;
    r.selectNodeContents(el);
    sel.removeAllRanges();
    sel.addRange(r);
  } catch { /* abaikan */ }
}

/**
 * Dialog tautan undangan versi web: tampil SEKALI, tombol "Salin tautan" (tanpa berbagi Telegram). Tautan dihapus dari DOM
 * dan variabel saat ditutup (tombol Tutup, Escape, atau pindah halaman). Mengembalikan Promise yang selesai saat ditutup.
 */
export function tampilkanTautanWeb({ link, jam } = {}, { title = 'Tautan undangan' } = {}) {
  return new Promise((selesai) => {
    let tautan = typeof link === 'string' && isTelegramLink(link) ? link : null;
    let tertutup = false;
    const pembuka = document.activeElement;
    const linkEl = h('p', { class: 'invite-link', id: 'invite-link' }, tautan || '');
    const onKey = (e) => { if (e.key === 'Escape') tutup(); };
    function tutup() {
      if (tertutup) return;
      tertutup = true;
      tautan = null;
      linkEl.textContent = '';
      document.removeEventListener?.('keydown', onKey);
      window.removeEventListener?.('hashchange', tutup);
      overlay.remove();
      pembuka?.focus?.();
      selesai();
    }
    const salin = h('button', { class: 'btn btn-primary', type: 'button', id: 'invite-salin' }, 'Salin tautan');
    salin.addEventListener('click', async () => {
      if (!tautan) return;
      const ok = await salinTeks(tautan);
      if (!ok) pilihIsi(linkEl);
      toast(ok ? 'Tautan disalin. Tempel ke chat Telegram calon pengguna.' : 'Gagal menyalin otomatis. Tautan sudah dipilih, tekan Ctrl+C untuk menyalin.', ok ? 'success' : 'info');
    });
    const isi = tautan
      ? [
        h('p', {}, 'Salin tautan ini lalu kirim ke calon pengguna lewat Telegram. Tautan hanya berlaku sekali pakai', jam != null ? ` dan kedaluwarsa dalam sekitar ${jam} jam.` : '.'),
        linkEl,
        h('p', { class: 'muted small' }, 'Tautan hanya tampil sekarang dan tidak disimpan. Setelah ditutup, buat undangan baru bila perlu.'),
        h('div', { class: 'row-actions' }, salin),
      ]
      : [h('p', {}, PESAN_TANPA_TAUTAN)];
    const overlay = h('div', { class: 'modal-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'invite-title' },
      h('div', { class: 'modal invite' }, h('h2', { id: 'invite-title' }, title), isi,
        h('div', { class: 'modal-actions' }, h('button', { class: 'btn', type: 'button', id: 'invite-close', onclick: tutup }, 'Tutup'))));
    document.addEventListener?.('keydown', onKey);
    window.addEventListener?.('hashchange', tutup);
    document.body.append(overlay);
    (tautan ? salin : overlay.querySelector?.('#invite-close'))?.focus?.();
  });
}

/** POST /pengguna/undangan lalu tampilkan dialog. Tanpa Idempotency-Key: respons memuat token mentah, jadi tidak boleh tersimpan di cache idempotensi server. */
async function terbitkanUndanganWeb(body, judul) {
  const ringkas = ringkasUndangan(await request('POST', '/pengguna/undangan', { body, silent: true }));
  await tampilkanTautanWeb(ringkas, { title: judul });
}

const alertGalat = (pesan) => h('div', { class: 'alert-danger', role: 'alert' }, pesan);

// ---- Bagian daftar dan detail ----

/** Di atas daftar (Admin): tautan undangan dan permintaan reset, hanya bila aksi.* server mengizinkan. */
export function listTopPenggunaWeb() {
  const undang = bolehUndang();
  const reset = bolehResetSesi();
  if (!undang && !reset) return null;
  return h('div', { class: 'stack' },
    undang ? h('a', { class: 'btn btn-block', href: '#/pengguna/undangan' }, 'Kelola tautan undangan') : null,
    reset ? h('a', { class: 'btn btn-block', href: '#/pengguna/permintaan-reset' }, 'Permintaan reset password') : null,
    undang ? h('p', { class: 'notice' }, 'Tautan undangan Telegram berlaku sekali pakai dan kedaluwarsa dalam 72 jam. Buat akun, lalu buka detailnya untuk menerbitkan tautan dan menyalinnya.') : null);
}

/** Akun login milik sendiri (Profil Saya): username dan status password. Password tidak pernah tampil. */
function panelAkunSaya(rec) {
  return h('div', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, 'Akun login'),
    h('dl', { class: 'detail' },
      h('div', { class: 'detail-item' }, h('dt', {}, 'Username'), h('dd', {}, rec.username || '-')),
      h('div', { class: 'detail-item' }, h('dt', {}, 'Password'), h('dd', {}, rec.password_diatur ? 'Sudah diatur' : 'Belum diatur')),
      h('div', { class: 'detail-item' }, h('dt', {}, 'Telegram'), h('dd', {}, rec.telegram_terhubung ? 'Terhubung' : 'Belum terhubung'))),
    h('p', { class: 'muted small' }, PESAN_UBAH_PASSWORD));
}

const labelJenisSesi = (j) => (j === 'web' ? 'Web' : j === 'miniapp' ? 'Mini App' : 'Perangkat');

/** Perangkat/sesi milik sendiri (GET /auth/sesi, ringkas). Pencabutan di layar Sesi dan Browser (#/sesi). */
function panelSesiSaya(ctx) {
  const isi = h('div', { class: 'cards' }, h('p', { class: 'muted small' }, 'Memuat...'));
  const root = h('div', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, 'Perangkat dan sesi'),
    isi,
    h('a', { class: 'btn btn-block', href: '#/sesi' }, 'Kelola sesi dan browser'));
  (async () => {
    let sesi;
    try { sesi = await daftarSesi(); } catch (e) {
      if (ctx.isStale() || e?.status === 401) return;
      isi.replaceChildren(h('p', { class: 'notice' }, ringkasGalatAksi(e, 'Daftar sesi gagal dimuat.').pesan));
      return;
    }
    if (ctx.isStale()) return;
    clear(isi);
    const daftar = Array.isArray(sesi) ? sesi : [];
    if (!daftar.length) isi.append(h('p', { class: 'muted small' }, 'Tidak ada sesi aktif.'));
    for (const s of daftar) {
      isi.append(h('div', { class: 'row-card' },
        h('span', { class: 'rc-main' },
          elemenLabelSesi(s, { kelas: 'rc-title' }),
          h('div', { class: 'rc-sub' }, `${labelJenisSesi(s.jenis)}, terakhir dipakai ${formatValue(s.terakhir_dipakai_pada, 'datetime')}`)),
        s.sesi_ini ? h('span', { class: 'badge badge-ok' }, 'Sesi ini') : null));
    }
  })();
  return root;
}

/** Kredensial pengguna lain (Admin, aksi.reset_password_pengguna): reset password, sesi, cabut semua sesi. */
function panelKredensial(rec) {
  const galat = h('div', { 'aria-live': 'assertive' });
  const aksi = (label, { title, message, okText, run, sukses }) => {
    const b = h('button', { class: 'btn btn-danger btn-block', type: 'button' }, label);
    b.addEventListener('click', async () => {
      if (!(await showConfirm({ title, message, okText, destructive: true }))) return;
      galat.replaceChildren();
      b.disabled = true;
      try { await run(); toast(sukses, 'success'); } // teks tetap: respons server tidak dirender
      catch (e) { if (e?.status !== 401) galat.replaceChildren(alertGalat(ringkasGalatAksi(e, 'Aksi gagal.').pesan)); }
      finally { b.disabled = false; }
    });
    return b;
  };
  const id = encodeURIComponent(rec.id);
  return h('div', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, 'Kredensial login'),
    h('p', { class: 'muted small' }, `Username: ${rec.username || '-'} · Password: ${rec.password_diatur ? 'sudah diatur' : 'belum diatur'}`),
    h('p', { class: 'muted small' }, 'Admin tidak pernah melihat atau menentukan password pengguna.'),
    galat,
    aksi('Reset password', {
      title: 'Reset password', okText: 'Reset', sukses: PESAN_RESET_BERHASIL,
      message: `Reset password ${rec.name}? Semua sesinya dicabut dan ia wajib mengatur password baru lewat Mini App.`,
      run: () => request('POST', `/pengguna/${id}/reset-password`, { body: {}, silent: true }),
    }),
    h('a', { class: 'btn btn-block', href: `#/pengguna/${id}/sesi` }, 'Lihat sesi & perangkat'),
    aksi('Cabut semua sesi', {
      title: 'Cabut sesi', okText: 'Cabut', sukses: 'Semua sesi pengguna dicabut.',
      message: `Keluarkan ${rec.name} dari semua perangkat? Password tidak berubah.`,
      run: () => request('DELETE', `/pengguna/${id}/sesi`, { silent: true }),
    }));
}

/** Tombol terbitkan undangan untuk akun yang belum menautkan Telegram (aksi.undang_pengguna). */
function tombolUndangan(rec) {
  const galat = h('div', { 'aria-live': 'assertive' });
  const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button' }, 'Buat tautan undangan Telegram');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    galat.replaceChildren();
    try { await terbitkanUndanganWeb({ user_id: rec.id }, `Undangan untuk ${rec.name}`); }
    catch (e) { if (e?.status !== 401) galat.replaceChildren(alertGalat(ringkasGalatAksi(e, 'Gagal membuat undangan.').pesan)); }
    finally { btn.disabled = false; }
  });
  return h('div', { class: 'stack' }, galat, btn);
}

/**
 * detailExtrasWeb(rec, ctx): Profil Saya (record sendiri) = akun login + perangkat/sesi; Admin pada pengguna lain =
 * kredensial (aksi.reset_password_pengguna), kepemilikan SPPG investor (aksi.kelola_kepemilikan_sppg), undangan
 * (aksi.undang_pengguna, status menunggu_telegram). Kepala SPPG (R tanpa aksi) hanya melihat rincian.
 */
export function detailPenggunaWeb(rec, ctx) {
  const saya = getUser();
  const sendiri = rec?.id != null && rec.id === saya?.id;
  if (userPerms(saya, 'pengguna').selfOnly) ctx.setTitle('Profil Saya');
  const out = [];
  if (sendiri) out.push(panelAkunSaya(rec), panelSesiSaya(ctx));
  else if (bolehResetSesi()) out.push(panelKredensial(rec));
  if (rec.role === 'investor' && bolehAksi(saya, 'kelola_kepemilikan_sppg')) out.push(kepemilikanSppgPanel(rec));
  if (!sendiri && bolehUndang() && rec.status === 'menunggu_telegram') out.push(tombolUndangan(rec));
  return out;
}

// ---- Rute tambahan web (didaftarkan SEBELUM /pengguna/:id agar "undangan" dan "permintaan-reset" bukan id) ----
export function registerRuteWeb() {
  route('/pengguna/undangan', (ctx) => {
    const galat = h('div', { 'aria-live': 'assertive' });
    const lv = createListView({
      endpoint: '/pengguna/undangan',
      columns: [{ key: 'tipe', primary: true }],
      searchable: false,
      itemHref: () => '#/pengguna/undangan',
      createHref: '#/pengguna/undangan/baru',
      createLabel: '+ Undangan',
      emptyText: 'Belum ada undangan.',
      filters: { param: 'status', label: 'Status undangan', options: [{ value: '', label: 'Semua' }, ...STATUS_UNDANGAN] },
      renderCard: (inv) => {
        const exp = inv.expires_at ? formatValue(inv.expires_at, 'datetime') : '-';
        const card = h('div', { class: 'row-card' },
          h('span', { class: 'rc-main' },
            h('span', { class: 'rc-title' }, undanganTarget(inv)),
            h('div', { class: 'rc-sub' }, `${inv.tipe === 'pic' ? 'PIC titik layanan' : 'Pengguna'} · berlaku hingga ${exp}`)),
          h('span', { class: `badge ${undanganCls(inv.status)}` }, undanganLabel(inv.status)));
        if (inv.status === 'aktif') {
          card.append(h('button', {
            class: 'btn', type: 'button', 'aria-label': `Cabut undangan ${undanganTarget(inv)}`,
            onclick: async () => {
              if (!(await showConfirm({ title: 'Cabut undangan', message: 'Tautan undangan ini tidak akan berlaku lagi. Lanjutkan?', okText: 'Cabut', destructive: true }))) return;
              galat.replaceChildren();
              try { await request('DELETE', `/pengguna/undangan/${encodeURIComponent(inv.id)}`, { silent: true }); toast('Undangan dicabut.', 'success'); lv.reload(); }
              catch (e) { if (e?.status !== 401) galat.replaceChildren(alertGalat(ringkasGalatAksi(e, 'Gagal mencabut undangan.').pesan)); }
            },
          }, 'Cabut'));
        }
        return card;
      },
    });
    ctx.el.append(h('p', { class: 'muted small' }, 'Token tidak dapat ditampilkan ulang. Bila tautan hilang, buat undangan baru (undangan aktif sebelumnya untuk akun yang sama otomatis dicabut).'), galat, lv.el);
  }, { title: 'Undangan Telegram', guard: bolehUndang });

  route('/pengguna/permintaan-reset', renderPermintaanReset, { title: 'Permintaan Reset Password', guard: bolehResetSesi });
  route('/pengguna/:id/sesi', renderSesiPengguna, { title: 'Sesi Pengguna', guard: bolehResetSesi });

  // Formulir undangan web: tanpa draf AI (?draft= diabaikan; draf hanya dari bot Telegram).
  route('/pengguna/undangan/baru', (ctx) => {
    const galat = h('div', { 'aria-live': 'assertive' });
    const fv = createFormView({
      fields: undanganFields(),
      values: {},
      submitText: 'Buat tautan undangan',
      onSubmit: async (values) => {
        galat.replaceChildren();
        try {
          await terbitkanUndanganWeb(buildInvitePayload(values), 'Tautan undangan pengguna');
        } catch (e) {
          if (e?.status === 401 || (e?.status === 422 && e.errors)) throw e; // 422 -> galat per kolom oleh form-view
          galat.replaceChildren(alertGalat(ringkasGalatAksi(e, 'Gagal membuat undangan.').pesan));
          return;
        }
        if (!ctx.isStale()) navigate('/pengguna/undangan', { replace: true });
      },
    });
    ctx.el.append(h('p', { class: 'muted small' }, 'Tautan tampil sekali setelah dibuat. Salin lalu kirim ke calon pengguna lewat Telegram.'), galat, fv.el);
    return fv.destroy;
  }, { title: 'Buat Undangan', guard: bolehUndang });
}
