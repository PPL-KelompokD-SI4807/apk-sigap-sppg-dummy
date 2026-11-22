// Catatan: bernama layar.js (bukan index.js) agar tidak ikut import.meta.glob registry Mini App.
// Layar Masuk web (R04-7): identitas + sandi -> kode sekali pakai dari bot -> sesi (cookie HttpOnly).
// Sandi dan kode hanya hidup di kolom formulir. Tidak ada storage, tidak ada token di JS (lihat core/api-web.js).
import { h, clear } from '../../core/dom.js';
import { klienWeb as klienBawaan, statusKirimUlang } from '../../core/api-web.js';
import { hideMainButton, toast } from '../../core/ui.js';
import { mapValidationErrors } from '../../core/errors.js';
import { formatMenit, kodeValid, sisaDetik } from '../../core/kredensial.js';
import {
  bacaHasilMasuk, pesanSelesai, klasifikasiGalat,
  PERINGATAN_INGAT, CATATAN_ADMIN, CATATAN_IDLE, PETUNJUK_LUPA_SANDI,
} from './logika.js';

/**
 * tampilkanMasukWeb(el, {onSelesai(data), pesanAwal, klien})
 * onSelesai dipanggil dengan respons langkah `selesai` (data.user, data.browser_tepercaya). Mengembalikan {destroy}.
 */
export function tampilkanMasukWeb(el, { onSelesai, pesanAwal = '', klien = klienBawaan } = {}) {
  let timer = null;
  const stopTimer = () => { if (timer) { clearInterval(timer); timer = null; } };
  const tick = (fn) => { stopTimer(); fn(); timer = setInterval(fn, 1000); };

  function layar(judul, ...anak) {
    stopTimer();
    hideMainButton();
    clear(el);
    el.append(h('section', { class: 'panel stack login' }, h('h2', { class: 'panel-title' }, judul), ...anak));
    document.title = judul;
  }
  const field = (label, input, galat) => h('label', { class: 'field' }, h('span', { class: 'label' }, label), input, galat);
  const kotakGalat = () => h('p', { class: 'field-error', role: 'alert' });
  const kotakForm = () => h('p', { class: 'notice', role: 'alert', hidden: true });
  const setForm = (box, teks) => { box.textContent = teks || ''; box.hidden = !teks; };

  async function terjaga(btn, label, fn) {
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    try { return await fn(); } finally { btn.disabled = false; btn.textContent = label; }
  }

  // ---------- Langkah 1: identitas + sandi ----------
  function layarMasuk(pesan = '') {
    const idEl = h('input', { type: 'text', name: 'identitas', required: true, maxlength: 32, autocomplete: 'username', autocapitalize: 'none', autocorrect: 'off', placeholder: 'Username atau no telepon' });
    const pwEl = h('input', { type: 'password', name: 'password', required: true, maxlength: 64, autocomplete: 'current-password' });
    const e1 = kotakGalat(); const e2 = kotakGalat(); const fe = kotakForm();
    setForm(fe, pesan);
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Masuk');
    const form = h('form', { class: 'form', novalidate: true },
      field('Username atau no telepon', idEl, e1), field('Sandi', pwEl, e2), fe, btn,
      h('p', { class: 'muted small' }, PETUNJUK_LUPA_SANDI),
      h('p', { class: 'muted small' }, CATATAN_IDLE));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setForm(fe, '');
      const identitas = idEl.value.trim();
      e1.textContent = identitas ? '' : 'Wajib diisi.';
      e2.textContent = pwEl.value ? '' : 'Wajib diisi.';
      if (!identitas || !pwEl.value) return;
      await terjaga(btn, 'Masuk', async () => {
        try {
          const hasil = bacaHasilMasuk(await klien.masuk(identitas, pwEl.value));
          pwEl.value = '';
          if (hasil.langkah === 'selesai') { toast(pesanSelesai(hasil.data) || 'Berhasil masuk.', 'success'); return onSelesai?.(hasil.data); }
          if (hasil.langkah === 'kode') return layarKode(hasil.tantangan, hasil.pesan);
          setForm(fe, 'Respons server tidak dikenali. Coba lagi.');
        } catch (e) {
          pwEl.value = '';
          const g = klasifikasiGalat(e);
          if (g.tipe === 'validasi') {
            const m = mapValidationErrors(e.errors);
            e1.textContent = m.identitas || '';
            e2.textContent = m.password || '';
            if (!m.identitas && !m.password) setForm(fe, g.pesan);
            return;
          }
          setForm(fe, g.pesan);
        }
      });
    });
    layar('Masuk SiGAP SPPG', h('p', { class: 'muted small' }, 'Masukkan username atau no telepon dan sandi. Kode verifikasi dikirim lewat bot Telegram.'), form);
    idEl.focus();
  }

  // ---------- Langkah 2: kode sekali pakai ----------
  function layarKode(tantangan, pesanServer) {
    let terkirimUlang = 0;
    let terakhirMs = Date.now(); // kode pertama baru dikirim; jeda 60 detik dihitung dari jam klien
    let kedaluwarsaMs = Date.parse(tantangan.kedaluwarsa_pada);
    if (Number.isNaN(kedaluwarsaMs)) kedaluwarsaMs = null;
    const maksKirim = Number.isInteger(tantangan.maks_kirim_ulang) ? tantangan.maks_kirim_ulang : 3;

    const kodeEl = h('input', { type: 'text', name: 'kode', required: true, inputmode: 'numeric', pattern: '[0-9]*', maxlength: 6, autocomplete: 'one-time-code', placeholder: '000000', class: 'otp' });
    const ingatEl = h('input', { type: 'checkbox', name: 'ingat_browser', id: 'ingat-browser' }); // bawaan mati
    const eKode = kotakGalat(); const fe = kotakForm();
    const info = h('p', { class: 'muted small' }, pesanServer || 'Kami mengirim kode 6 digit ke chat bot SiGAP di Telegram. Masukkan kode tersebut di sini.');
    const sisaWaktu = h('p', { class: 'muted small' });
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Verifikasi');
    const ulang = h('button', { class: 'link', type: 'button' }, 'Kirim ulang kode');
    const kembali = h('button', { class: 'link', type: 'button', onclick: () => layarMasuk() }, 'Kembali ke awal');

    const form = h('form', { class: 'form', novalidate: true },
      field('Kode 6 digit', kodeEl, eKode),
      h('label', { class: 'check-row', for: 'ingat-browser' }, ingatEl, h('span', {}, 'Ingat browser ini 30 hari')),
      h('p', { class: 'notice' }, PERINGATAN_INGAT),
      h('p', { class: 'muted small' }, CATATAN_ADMIN),
      fe, btn, sisaWaktu, ulang, kembali);

    function perbarui() {
      const st = statusKirimUlang({ terkirimSebanyak: terkirimUlang, terakhirMs, sekarangMs: Date.now() });
      const sisa = Math.max(0, maksKirim - terkirimUlang);
      ulang.disabled = !(sisa > 0 && st.tungguDetik === 0);
      ulang.textContent = sisa === 0 ? 'Batas kirim ulang tercapai'
        : st.tungguDetik > 0 ? `Kirim ulang kode (${formatMenit(st.tungguDetik)})` : 'Kirim ulang kode';
      if (kedaluwarsaMs != null) {
        const s = sisaDetik(new Date(kedaluwarsaMs).toISOString());
        sisaWaktu.textContent = s > 0 ? `Kode berlaku ${formatMenit(s)} lagi.` : 'Kode sudah kedaluwarsa. Kirim ulang kode atau mulai dari awal.';
      }
    }

    kodeEl.addEventListener('input', () => { kodeEl.value = kodeEl.value.replace(/\D/g, '').slice(0, 6); });
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setForm(fe, '');
      const kode = kodeEl.value.trim();
      if (!kodeValid(kode)) { eKode.textContent = 'Kode harus 6 digit angka.'; return; }
      eKode.textContent = '';
      const ingat = ingatEl.checked;
      await terjaga(btn, 'Verifikasi', async () => {
        try {
          const data = await klien.kirimKode(tantangan.id, kode, ingat);
          if (data?.langkah !== 'selesai') { setForm(fe, 'Respons server tidak dikenali. Coba lagi.'); return; }
          const catatan = pesanSelesai(data, { ingatDiminta: ingat });
          if (catatan) toast(catatan, 'info', 5200);
          onSelesai?.(data);
        } catch (e) {
          kodeEl.value = '';
          if (e?.perluMasukUlang) return layarMasuk('Pemeriksaan keamanan berakhir. Masukkan sandi lagi.');
          const g = klasifikasiGalat(e);
          if (g.tipe === 'kode') { eKode.textContent = g.pesan; return kodeEl.focus(); }
          setForm(fe, g.pesan);
        }
      });
    });
    ulang.addEventListener('click', async () => {
      ulang.disabled = true;
      try {
        const data = await klien.kirimUlangKode(tantangan.id);
        terkirimUlang += 1;
        terakhirMs = Date.now();
        const baru = Date.parse(data?.tantangan?.kedaluwarsa_pada);
        if (!Number.isNaN(baru)) kedaluwarsaMs = baru;
        toast('Bila data benar, kode baru dikirim ke Telegram Anda.', 'info');
      } catch (e) {
        if (e?.perluMasukUlang) return layarMasuk('Pemeriksaan keamanan berakhir. Masukkan sandi lagi.');
        setForm(fe, klasifikasiGalat(e).pesan);
      }
      perbarui();
    });

    layar('Masukkan kode', info, form);
    tick(perbarui);
    kodeEl.focus();
  }

  layarMasuk(pesanAwal);
  return { destroy: stopTimer };
}
