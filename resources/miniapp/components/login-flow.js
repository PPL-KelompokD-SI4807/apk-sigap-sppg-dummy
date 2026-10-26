// Alur masuk kredensial (R-03): masuk, aktivasi, lupa password, token 6 digit, terkunci.
// Password dan kode token hanya hidup di kolom formulir; tidak disimpan di storage maupun variabel modul.
import { h, clear } from '../core/dom.js';
import { masuk, aktivasi, resetPassword, verifikasiToken, kirimUlangToken } from '../core/auth.js';
import { hideMainButton, toast } from '../core/ui.js';
import { mapValidationErrors } from '../core/errors.js';
import {
  aturanPassword, passwordValid, sisaDetik, formatMenit, durasiRamah, detikTerkunci, kodeValid, LABEL_TUJUAN, ALASAN_ULANG,
} from '../core/kredensial.js';

/**
 * showLoginFlow(el, {langkah: 'masuk'|'aktivasi', akun, onDone(user), onRestart()})
 * onRestart: dipanggil bila alur harus diulang dari awal (token pra-login habis).
 */
export function showLoginFlow(el, { langkah, akun = {}, onDone, onRestart }) {
  let timer = null;
  const stopTimer = () => { if (timer) { clearInterval(timer); timer = null; } };
  const tick = (fn) => { stopTimer(); fn(); timer = setInterval(fn, 1000); };

  function screen(title, ...children) {
    stopTimer();
    hideMainButton();
    clear(el);
    el.append(h('section', { class: 'panel stack login' }, h('h2', { class: 'panel-title' }, title), ...children));
    document.title = title;
  }

  function field(label, input, errorBox) {
    return h('label', { class: 'field' }, h('span', { class: 'label' }, label), input, errorBox);
  }
  const errBox = () => h('p', { class: 'field-error', role: 'alert' });
  const input = (attrs) => h('input', { required: true, ...attrs });
  const showErrors = (boxes, map) => {
    let shown = false;
    for (const [k, box] of Object.entries(boxes)) {
      box.textContent = map[k] || '';
      box.closest('.field')?.classList.toggle('invalid', !!map[k]);
      shown = shown || !!map[k];
    }
    return shown;
  };
  const formError = () => h('p', { class: 'notice', role: 'alert', hidden: true });
  const setFormError = (box, text) => { box.textContent = text || ''; box.hidden = !text; };

  /** Jalankan aksi dengan tombol terkunci selama proses. */
  async function guarded(btn, label, fn) {
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    try { return await fn(); } finally { btn.disabled = false; btn.textContent = label; }
  }

  function checklist(getPw, getUser) {
    const ul = h('ul', { class: 'pw-rules small', 'aria-live': 'polite' });
    const update = () => {
      clear(ul);
      for (const r of aturanPassword(getPw(), getUser())) ul.append(h('li', { class: r.ok ? 'ok' : 'no' }, `${r.ok ? '✓' : '○'} ${r.teks}`));
    };
    update();
    return { ul, update };
  }

  // Hasil panggilan kredensial -> layar token / selesai / galat umum.
  function lanjut(hasil) {
    if (hasil.selesai) return onDone(hasil.user);
    return layarToken(hasil.tantangan, hasil.message);
  }

  /** Galat umum dari pemanggilan kredensial. Mengembalikan true bila sudah ditangani. */
  function galatUmum(e, formErr) {
    const alasan = e.data?.alasan;
    if (e.status === 401 || alasan === 'bukan_pra_login') { onRestart?.(); return true; }
    if (e.status === 429 && alasan === 'terkunci') { layarTerkunci(detikTerkunci(e.data)); return true; }
    if (e.status === 409 && alasan === 'perlu_aktivasi') { toast('Akun Anda belum diaktivasi.', 'info'); layarAktivasi(); return true; }
    if (e.status === 409 && alasan === 'sudah_aktivasi') { toast('Akun sudah diaktivasi. Silakan masuk.', 'info'); layarMasuk(); return true; }
    if (alasan === 'telegram_belum_terhubung') { setFormError(formErr, 'Akun Telegram Anda belum terhubung. Hubungi admin SPPG.'); return true; }
    if (e.status === 503 || alasan === 'gagal_kirim') {
      setFormError(formErr, e.data?.message || 'Token tidak dapat dikirim. Buka chat bot SiGAP di Telegram, tekan Mulai (/start), lalu coba lagi.');
      return true;
    }
    if (e.status === 429) { setFormError(formErr, e.data?.message || 'Terlalu sering mencoba. Tunggu sebentar lalu coba lagi.'); return true; }
    if (e.status === 0) { setFormError(formErr, 'Tidak ada koneksi internet.'); return true; }
    return false;
  }

  // ---------- Masuk ----------
  function layarMasuk() {
    const idEl = input({ type: 'text', name: 'identitas', autocomplete: 'username', autocapitalize: 'none', autocorrect: 'off', placeholder: 'Username atau no telepon' });
    const pwEl = input({ type: 'password', name: 'password', autocomplete: 'current-password' });
    const e1 = errBox(); const e2 = errBox(); const fe = formError();
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Masuk');
    const form = h('form', { class: 'form', novalidate: true },
      field('Username atau no telepon', idEl, e1), field('Password', pwEl, e2), fe, btn,
      h('button', { class: 'link', type: 'button', onclick: layarLupa }, 'Lupa password?'));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setFormError(fe, '');
      const identitas = idEl.value.trim();
      if (showErrors({ identitas: e1, password: e2 }, { identitas: identitas ? '' : 'Wajib diisi.', password: pwEl.value ? '' : 'Wajib diisi.' })) return;
      await guarded(btn, 'Masuk', async () => {
        try {
          lanjut(await masuk({ identitas, password: pwEl.value }));
        } catch (e) {
          pwEl.value = '';
          if (galatUmum(e, fe)) return;
          if (e.status === 422) {
            const sisa = e.data?.sisa_percobaan;
            const msg = e.data?.alasan === 'kredensial_salah'
              ? `Username/no telepon atau password salah.${Number.isInteger(sisa) ? ` Sisa percobaan: ${sisa}.` : ''}`
              : null;
            if (msg) return setFormError(fe, msg);
            return showErrors({ identitas: e1, password: e2 }, mapValidationErrors(e.errors));
          }
          setFormError(fe, e.message || 'Gagal masuk.');
        }
      });
    });
    screen(akun.nama ? `Masuk, ${akun.nama}` : 'Masuk', h('p', { class: 'muted small' }, 'Masukkan username atau no telepon dan password. Kode verifikasi akan dikirim lewat bot Telegram.'), form);
    idEl.focus();
  }

  // ---------- Aktivasi ----------
  function layarAktivasi() {
    const perluUser = !!akun.perlu_username;
    const unEl = perluUser ? input({ type: 'text', name: 'username', autocomplete: 'username', autocapitalize: 'none', minlength: 3, maxlength: 50 }) : null;
    const phEl = h('input', { type: 'tel', name: 'phone', autocomplete: 'tel', inputmode: 'tel', placeholder: 'Contoh 081234567890 (opsional)' });
    const pwEl = input({ type: 'password', name: 'password', autocomplete: 'new-password' });
    const pcEl = input({ type: 'password', name: 'password_confirmation', autocomplete: 'new-password' });
    const boxes = { username: errBox(), phone: errBox(), password: errBox(), password_confirmation: errBox() };
    const fe = formError();
    const cl = checklist(() => pwEl.value, () => unEl?.value);
    pwEl.addEventListener('input', cl.update);
    unEl?.addEventListener('input', cl.update);
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Aktifkan akun');
    const form = h('form', { class: 'form', novalidate: true },
      perluUser ? field('Username', unEl, boxes.username) : null,
      field('No telepon (opsional)', phEl, boxes.phone),
      field('Password baru', pwEl, boxes.password), cl.ul,
      field('Ulangi password', pcEl, boxes.password_confirmation), fe, btn);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setFormError(fe, '');
      const username = unEl?.value.trim();
      const local = {};
      if (perluUser && !username) local.username = 'Wajib diisi.';
      if (!passwordValid(pwEl.value, username)) local.password = 'Password belum memenuhi aturan di atas.';
      if (pcEl.value !== pwEl.value) local.password_confirmation = 'Konfirmasi password tidak sama.';
      if (showErrors(boxes, local)) return;
      const body = { password: pwEl.value, password_confirmation: pcEl.value };
      if (perluUser) body.username = username;
      if (phEl.value.trim()) body.phone = phEl.value.trim();
      await guarded(btn, 'Aktifkan akun', async () => {
        try {
          lanjut(await aktivasi(body));
        } catch (e) {
          if (galatUmum(e, fe)) return;
          if (e.status === 422 && !showErrors(boxes, mapValidationErrors(e.errors))) setFormError(fe, e.message);
          else if (e.status !== 422) setFormError(fe, e.message || 'Aktivasi gagal.');
        }
      });
    });
    screen('Aktivasi akun', h('p', { class: 'muted small' }, `Halo${akun.nama ? `, ${akun.nama}` : ''}. Atur password untuk mengaktifkan akun Anda.`), form);
    (unEl || phEl).focus();
  }

  // ---------- Lupa password / reset ----------
  function layarLupa() {
    const idEl = input({ type: 'text', name: 'identitas', autocomplete: 'username', autocapitalize: 'none', placeholder: 'Username atau no telepon' });
    const pwEl = input({ type: 'password', name: 'password', autocomplete: 'new-password' });
    const pcEl = input({ type: 'password', name: 'password_confirmation', autocomplete: 'new-password' });
    const boxes = { identitas: errBox(), password: errBox(), password_confirmation: errBox() };
    const fe = formError();
    const cl = checklist(() => pwEl.value, () => idEl.value);
    pwEl.addEventListener('input', cl.update);
    idEl.addEventListener('input', cl.update);
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Kirim kode verifikasi');
    const form = h('form', { class: 'form', novalidate: true },
      field('Username atau no telepon', idEl, boxes.identitas),
      field('Password baru', pwEl, boxes.password), cl.ul,
      field('Ulangi password', pcEl, boxes.password_confirmation), fe, btn,
      h('button', { class: 'link', type: 'button', onclick: layarMasuk }, 'Kembali ke halaman masuk'));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setFormError(fe, '');
      const identitas = idEl.value.trim();
      const local = {};
      if (!identitas) local.identitas = 'Wajib diisi.';
      if (!passwordValid(pwEl.value, identitas)) local.password = 'Password belum memenuhi aturan di atas.';
      if (pcEl.value !== pwEl.value) local.password_confirmation = 'Konfirmasi password tidak sama.';
      if (showErrors(boxes, local)) return;
      await guarded(btn, 'Kirim kode verifikasi', async () => {
        try {
          lanjut(await resetPassword({ identitas, password: pwEl.value, password_confirmation: pcEl.value }));
        } catch (e) {
          if (galatUmum(e, fe)) return;
          if (e.status === 422 && !showErrors(boxes, mapValidationErrors(e.errors))) setFormError(fe, e.message);
          else if (e.status !== 422) setFormError(fe, e.message || 'Gagal mengatur ulang password.');
        }
      });
    });
    screen('Lupa password', h('p', { class: 'muted small' }, 'Tentukan password baru. Password baru berlaku setelah Anda memasukkan kode verifikasi dari bot Telegram.'), form);
    idEl.focus();
  }

  // ---------- Token 6 digit ----------
  function layarToken(tantangan, pesan) {
    let t = tantangan;
    const kodeEl = h('input', {
      type: 'text', name: 'kode', inputmode: 'numeric', pattern: '[0-9]*', maxlength: 6, autocomplete: 'one-time-code', 'aria-label': 'Kode 6 digit', class: 'otp',
    });
    const eKode = errBox(); const info = h('p', { class: 'muted small' }); const fe = formError();
    const sisaEl = h('p', { class: 'small', role: 'status' });
    const expEl = h('p', { class: 'small muted' });
    const resendBtn = h('button', { class: 'btn btn-block', type: 'button' }, 'Kirim ulang kode');
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Verifikasi');
    let kedaluwarsa = false;
    let sisaPercobaan = null;

    const refresh = () => {
      const sisaExp = sisaDetik(t.kedaluwarsa_pada);
      kedaluwarsa = sisaExp === 0;
      expEl.textContent = kedaluwarsa ? 'Kode sudah kedaluwarsa. Minta kode baru.' : `Kode berlaku ${formatMenit(sisaExp)} lagi.`;
      btn.disabled = kedaluwarsa;
      const tunggu = sisaDetik(t.kirim_ulang_tersedia_pada);
      const habis = t.sisa_kirim_ulang <= 0;
      resendBtn.disabled = tunggu > 0 || habis;
      resendBtn.textContent = habis ? 'Batas kirim ulang tercapai' : tunggu > 0 ? `Kirim ulang kode (${formatMenit(tunggu)})` : 'Kirim ulang kode';
      sisaEl.textContent = [
        Number.isInteger(sisaPercobaan) ? `Sisa percobaan: ${sisaPercobaan}.` : (t.maks_percobaan ? `Maksimal ${t.maks_percobaan} percobaan.` : ''),
        !habis && Number.isInteger(t.sisa_kirim_ulang) ? `Sisa kirim ulang: ${t.sisa_kirim_ulang}.` : '',
      ].filter(Boolean).join(' ');
    };
    const mulaiUlang = () => { toast('Mulai lagi dari awal.', 'info'); onRestart ? onRestart() : layarMasuk(); };

    const form = h('form', { class: 'form', novalidate: true },
      field('Kode 6 digit', kodeEl, eKode), expEl, sisaEl, fe, btn, resendBtn,
      h('button', { class: 'link', type: 'button', onclick: layarMasuk }, 'Batal'));

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setFormError(fe, '');
      const kode = kodeEl.value.trim();
      if (showErrors({ kode: eKode }, { kode: kodeValid(kode) ? '' : 'Masukkan 6 digit angka.' })) return;
      btn.disabled = true; btn.textContent = 'Memproses...';
      try {
        const user = await verifikasiToken(t.id, kode);
        stopTimer();
        return onDone(user);
      } catch (e) {
        btn.textContent = 'Verifikasi';
        kodeEl.value = '';
        const alasan = e.data?.alasan;
        if (e.status === 401 || alasan === 'bukan_pra_login') return onRestart?.();
        if (ALASAN_ULANG.has(alasan)) {
          toast(e.data?.message || 'Kode tidak berlaku lagi. Ulangi dari awal.', 'error');
          return layarMasuk();
        }
        if (e.status === 429 && alasan === 'terkunci') return layarTerkunci(detikTerkunci(e.data));
        if (alasan === 'token_salah') {
          if (Number.isInteger(e.data?.sisa_percobaan)) sisaPercobaan = e.data.sisa_percobaan;
          showErrors({ kode: eKode }, { kode: 'Kode salah.' });
        } else if (alasan === 'token_kedaluwarsa') {
          t = { ...t, kedaluwarsa_pada: new Date(0).toISOString() };
          showErrors({ kode: eKode }, { kode: 'Kode kedaluwarsa. Minta kirim ulang.' });
        } else if (e.status === 0) setFormError(fe, 'Tidak ada koneksi internet.');
        else if (!showErrors({ kode: eKode }, mapValidationErrors(e.errors))) setFormError(fe, e.message || 'Verifikasi gagal.');
        refresh();
        if (!kedaluwarsa) btn.disabled = false;
        kodeEl.focus();
      }
    });

    resendBtn.addEventListener('click', async () => {
      setFormError(fe, '');
      resendBtn.disabled = true;
      try {
        t = await kirimUlangToken(t.id);
        sisaPercobaan = null;
        showErrors({ kode: eKode }, {});
        toast('Kode baru dikirim ke Telegram Anda.', 'success');
      } catch (e) {
        const alasan = e.data?.alasan;
        if (e.status === 401 || alasan === 'bukan_pra_login') return onRestart?.();
        if (ALASAN_ULANG.has(alasan) || (e.status === 422 && !alasan)) return mulaiUlang();
        if (e.status === 429 && alasan === 'terkunci') return layarTerkunci(detikTerkunci(e.data));
        setFormError(fe, e.status === 429 ? (e.data?.message || 'Terlalu sering meminta kode. Tunggu sebentar.') : e.status === 0 ? 'Tidak ada koneksi internet.' : e.message || 'Gagal mengirim ulang kode.');
      }
      refresh();
    });

    screen(`Verifikasi ${LABEL_TUJUAN[t.tujuan] || 'akun'}`,
      info, form);
    info.textContent = pesan || 'Kami mengirim kode 6 digit ke chat bot SiGAP di Telegram. Masukkan kode tersebut di sini.';
    tick(refresh);
    kodeEl.focus();
  }

  // ---------- Terkunci ----------
  function layarTerkunci(detik) {
    const akhir = Date.now() + Math.max(0, detik) * 1000;
    const hitung = h('p', { class: 'lock-count', role: 'timer' });
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button', disabled: true, onclick: layarMasuk }, 'Coba masuk lagi');
    screen('Akun terkunci sementara',
      h('p', {}, 'Terlalu banyak percobaan yang gagal. Demi keamanan, akun dikunci sementara.'), hitung, btn);
    tick(() => {
      const sisa = Math.max(0, Math.ceil((akhir - Date.now()) / 1000));
      hitung.textContent = sisa > 0 ? `Coba lagi dalam ${durasiRamah(sisa)} (${formatMenit(sisa)}).` : 'Waktu tunggu selesai.';
      btn.disabled = sisa > 0;
      if (sisa === 0) stopTimer();
    });
  }

  if (langkah === 'aktivasi') layarAktivasi(); else layarMasuk();
  return { destroy: stopTimer, layarTerkunci };
}
