// Catatan: bernama layar.js (bukan index.js) agar tidak ikut import.meta.glob registry Mini App.
// Layar Masuk web (R04-7): identitas + sandi -> kode sekali pakai dari bot -> sesi (cookie HttpOnly).
// Sandi dan kode hanya hidup di kolom formulir. Tidak ada storage, tidak ada token di JS (lihat core/api-web.js).
import { h, clear } from '../../core/dom.js';
import { klienWeb as klienBawaan, statusKirimUlang } from '../../core/api-web.js';
import { hideMainButton, toast } from '../../core/ui.js';
import { mapValidationErrors } from '../../core/errors.js';
import { logoBgn, penandaAkademik } from '../../core/merek-web.js';
import { formatMenit, kodeValid } from '../../core/kredensial.js';
import {
  bacaHasilMasuk, pesanSelesai, klasifikasiGalat, tantanganMati, bacaSisaPercobaan, teksSisaPercobaan, bacaTantangan,
  PERINGATAN_INGAT, CATATAN_ADMIN, CATATAN_IDLE, PETUNJUK_LUPA_SANDI,
  PESAN_KODE_DIKIRIM, PESAN_KODE_BARU_DIKIRIM, PESAN_KIRIM_ULANG_BELUM,
  JUDUL_PANDUAN_KODE, PENGANTAR_PANDUAN_KODE, SEBAB_KODE_TIDAK_DATANG, PESAN_KUOTA_HABIS, PESAN_TANTANGAN_MATI, PESAN_PERCOBAAN_HABIS,
} from './logika.js';

/**
 * tampilkanMasukWeb(el, {onSelesai(data), pesanAwal, klien})
 * onSelesai dipanggil dengan respons langkah `selesai` (data.user, data.browser_tepercaya). Mengembalikan {destroy}.
 */
export function tampilkanMasukWeb(el, { onSelesai, pesanAwal = '', klien = klienBawaan } = {}) {
  let timer = null;
  let generasi = 0; // naik tiap layar diganti; tick() tidak memasang interval bila fn() sendiri mengganti layar
  const stopTimer = () => { if (timer) { clearInterval(timer); timer = null; } };
  const tick = (fn) => { stopTimer(); const g = generasi; fn(); if (g === generasi) timer = setInterval(fn, 1000); };
  const selesai = (data) => { stopTimer(); return onSelesai?.(data); }; // hitung mundur berhenti sebelum layar ditinggalkan

  function layar(judul, ...anak) {
    stopTimer();
    generasi += 1;
    hideMainButton();
    clear(el);
    el.append(h('div', { class: 'login-wrap' },
      h('div', { class: 'login-brand' }, logoBgn({ ukuran: 56, besar: true }), h('b', {}, 'SiGAP SPPG'), h('span', { class: 'muted small' }, 'Sistem Informasi Dapur MBG'), penandaAkademik()),
      h('section', { class: 'panel stack login' }, h('h2', { class: 'panel-title' }, judul), ...anak)));
    document.title = judul;
  }
  const field = (label, input, galat) => h('label', { class: 'field' }, h('span', { class: 'label' }, label), input, galat);
  const kotakGalat = () => h('p', { class: 'field-error', role: 'alert' });
  // Galat tingkat form (mis. 422 tanpa `errors`): kotak menonjol, bukan teks kecil di bawah kolom.
  const kotakForm = () => h('p', { class: 'notice notice-tegas', role: 'alert', hidden: true });
  const setForm = (box, teks) => { box.textContent = teks || ''; box.hidden = !teks; };

  async function terjaga(btn, label, fn) {
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    try { return await fn(); } finally { btn.disabled = false; btn.textContent = label; }
  }

  // ---------- Langkah 1: identitas + sandi ----------
  function layarMasuk(pesan = '') {
    // aria-label: nama kolom tidak ikut memuat teks galat yang berada di dalam <label>, sehingga galat diumumkan sekali (role=alert).
    const idEl = h('input', { type: 'text', name: 'identitas', required: true, maxlength: 32, autocomplete: 'username', autocapitalize: 'none', autocorrect: 'off', placeholder: 'Username atau no telepon', 'aria-label': 'Username atau no telepon' });
    const pwEl = h('input', { type: 'password', name: 'password', required: true, maxlength: 64, autocomplete: 'current-password', 'aria-label': 'Sandi' });
    const e1 = kotakGalat(); const e2 = kotakGalat(); const fe = kotakForm();
    setForm(fe, pesan);
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Masuk');
    const fPw = field('Sandi', pwEl, e2);
    const tandaiSandi = (salah) => { fPw.className = salah ? 'field invalid' : 'field'; if (salah) pwEl.setAttribute('aria-invalid', 'true'); else pwEl.removeAttribute?.('aria-invalid'); };
    const form = h('form', { class: 'form', novalidate: true },
      field('Username atau no telepon', idEl, e1), fPw, fe, btn,
      h('p', { class: 'muted small' }, PETUNJUK_LUPA_SANDI),
      h('p', { class: 'muted small' }, CATATAN_IDLE));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setForm(fe, '');
      tandaiSandi(false);
      const identitas = idEl.value.trim();
      e1.textContent = identitas ? '' : 'Wajib diisi.';
      e2.textContent = pwEl.value ? '' : 'Wajib diisi.';
      if (!identitas || !pwEl.value) return;
      let fokusTombol = false;
      await terjaga(btn, 'Masuk', async () => {
        try {
          const hasil = bacaHasilMasuk(await klien.masuk(identitas, pwEl.value));
          pwEl.value = '';
          if (hasil.langkah === 'selesai') { toast(pesanSelesai(hasil.data) || 'Berhasil masuk.', 'success'); return selesai(hasil.data); }
          if (hasil.langkah === 'kode') return layarKode(hasil.tantangan, hasil.pesan);
          setForm(fe, 'Respons server tidak dikenali. Coba lagi.');
        } catch (e) {
          pwEl.value = '';
          const g = klasifikasiGalat(e);
          if (g.tipe === 'kredensial') {
            // Kredensial salah (lima sebab, satu pesan): tetap di layar ini, username dipertahankan, sandi dikosongkan dan difokuskan.
            e1.textContent = '';
            e2.textContent = g.pesan;
            tandaiSandi(true);
            pwEl.focus();
            return;
          }
          if (g.tipe === 'validasi') {
            const m = mapValidationErrors(e.errors);
            e1.textContent = m.identitas || '';
            e2.textContent = m.password || '';
            if (!m.identitas && !m.password) setForm(fe, g.pesan);
            return;
          }
          setForm(fe, g.pesan); // galat tingkat form: telegram_belum_terhubung, terlalu_sering, laju, offline, dll.
          fokusTombol = true;
        }
      });
      if (fokusTombol) btn.focus(); // tombol sempat nonaktif (fokus lepas); kembalikan agar pengguna dapat mencoba lagi dari keyboard
    });
    layar('Masuk SiGAP SPPG', h('p', { class: 'muted small' }, 'Masukkan username atau no telepon dan sandi. Kode verifikasi dikirim lewat bot Telegram.'), form);
    idEl.focus();
  }


  // ---------- Panduan "kode tidak datang": tampil menonjol, tiga sebab, netral untuk semua orang ----------
  function panduanKode() {
    return h('section', { class: 'panduan-kode', 'aria-labelledby': 'panduan-kode-judul' },
      h('h3', { class: 'panduan-kode-judul', id: 'panduan-kode-judul' }, JUDUL_PANDUAN_KODE),
      h('p', { class: 'panduan-kode-teks' }, PENGANTAR_PANDUAN_KODE),
      h('ul', { class: 'panduan-kode-daftar' }, ...SEBAB_KODE_TIDAK_DATANG.map((t) => h('li', {}, t))));
  }

  // ---------- Tantangan mati (kedaluwarsa / tidak berlaku): jangan biarkan pengguna mengetik kode sia-sia ----------
  function layarKodeMati(pesan = PESAN_TANTANGAN_MATI) {
    const mulai = h('button', { class: 'btn btn-primary btn-block', type: 'button', onclick: () => layarMasuk() }, 'Mulai dari awal');
    const kotak = h('p', { class: 'notice', role: 'alert', tabindex: '-1' }, pesan);
    layar('Kode tidak berlaku lagi', kotak, mulai, panduanKode());
    mulai.focus();
  }

  // ---------- Langkah 2: kode sekali pakai ----------
  function layarKode(tantanganAwal, pesanServer) {
    let tantangan = tantanganAwal;
    const jam = () => (typeof klien.waktuServer === 'function' ? klien.waktuServer() : Date.now()); // jam server (header Date), bukan jam perangkat
    if (tantanganMati(tantangan, jam())) return layarKodeMati();

    const maksKirim = Number.isInteger(tantangan.maks_kirim_ulang) ? tantangan.maks_kirim_ulang : null;
    const maksPercobaan = Number.isInteger(tantangan.maks_percobaan) ? tantangan.maks_percobaan : null;
    let percobaanHabis = false;
    let terakhir = { boleh: null, habis: null }; // untuk mengumumkan perubahan keadaan penting, bukan tiap detik

    const kodeEl = h('input', { type: 'text', name: 'kode', required: true, inputmode: 'numeric', pattern: '[0-9]*', maxlength: 6, autocomplete: 'one-time-code', placeholder: '000000', class: 'otp' });
    const ingatEl = h('input', { type: 'checkbox', name: 'ingat_browser', id: 'ingat-browser' }); // bawaan mati
    const eKode = kotakGalat(); const fe = kotakForm();
    const infoPercobaan = h('p', { class: 'muted small', role: 'status', hidden: true });
    const info = h('p', { class: 'muted small' }, pesanServer || PESAN_KODE_DIKIRIM);
    // #view memakai aria-live="polite" (pergantian layar diumumkan). Elemen yang berubah tiap detik dikecualikan dengan aria-live="off".
    const sisaWaktu = h('p', { class: 'muted small', 'aria-live': 'off' });
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Verifikasi');
    const ulang = h('button', { class: 'btn btn-block', type: 'button', 'aria-live': 'off' }, 'Kirim ulang kode');
    const kuota = h('p', { class: 'muted small', 'aria-live': 'off' });
    const kembali = h('button', { class: 'link', type: 'button', onclick: () => layarMasuk() }, 'Mulai dari awal');
    const habisKotak = h('p', { class: 'notice', role: 'alert', hidden: true }, PESAN_PERCOBAAN_HABIS);
    // Perubahan keadaan penting diumumkan lewat wilayah status sopan; hitung mundur per detik TIDAK pernah ke sini.
    const umum = h('p', { class: 'sr-only', role: 'status' });
    const umumkan = (teks) => { umum.textContent = teks; };

    const form = h('form', { class: 'form', novalidate: true },
      field('Kode 6 digit', kodeEl, eKode), infoPercobaan,
      h('label', { class: 'check-row', for: 'ingat-browser' }, ingatEl, h('span', {}, 'Ingat browser ini 30 hari')),
      h('p', { class: 'notice' }, PERINGATAN_INGAT),
      h('p', { class: 'muted small' }, CATATAN_ADMIN),
      fe, habisKotak, btn, sisaWaktu, ulang, kuota, panduanKode(), kembali, umum);

    function perbarui() {
      if (form.isConnected === false) return stopTimer(); // layar sudah ditinggalkan tanpa destroy()
      const sekarang = jam();
      if (tantanganMati(tantangan, sekarang)) return layarKodeMati();
      const st = statusKirimUlang({ tersediaPada: tantangan.kirim_ulang_tersedia_pada, sisaKirimUlang: tantangan.sisa_kirim_ulang, sekarangMs: sekarang });
      ulang.disabled = percobaanHabis || !st.boleh;
      ulang.textContent = st.habis ? 'Jatah kirim ulang habis'
        : st.tungguDetik > 0 ? `Kirim ulang kode dalam ${st.tungguDetik} detik` : 'Kirim ulang kode';
      kuota.textContent = st.habis ? PESAN_KUOTA_HABIS : `Sisa jatah kirim ulang: ${st.sisa}${maksKirim != null && maksKirim >= st.sisa ? ` dari ${maksKirim}` : ''}.`;
      const s = Math.max(0, Math.ceil((Date.parse(tantangan.kedaluwarsa_pada) - sekarang) / 1000));
      sisaWaktu.textContent = `Kode berlaku ${formatMenit(s)} lagi.`;
      if (terakhir.habis === false && st.habis) umumkan(PESAN_KUOTA_HABIS);
      else if (terakhir.boleh === false && st.boleh) umumkan('Tombol kirim ulang kode sudah aktif.');
      terakhir = { boleh: st.boleh, habis: st.habis };
    }

    function hentikanPercobaan() {
      percobaanHabis = true;
      stopTimer();
      kodeEl.disabled = true; btn.disabled = true; ulang.disabled = true; ingatEl.disabled = true;
      habisKotak.hidden = false;
      kembali.focus();
    }

    kodeEl.addEventListener('input', () => { kodeEl.value = kodeEl.value.replace(/\D/g, '').slice(0, 6); });
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (percobaanHabis) return;
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
          selesai(data);
        } catch (e) {
          kodeEl.value = '';
          if (e?.perluMasukUlang) return layarMasuk('Pemeriksaan keamanan berakhir. Masukkan sandi lagi.');
          const g = klasifikasiGalat(e);
          if (g.tipe === 'kode') {
            eKode.textContent = g.pesan; // teks seragam: tidak menyiratkan sandi benar atau salah
            const sisa = bacaSisaPercobaan(e);
            infoPercobaan.hidden = sisa == null;
            infoPercobaan.textContent = sisa == null ? '' : teksSisaPercobaan(sisa, maksPercobaan);
            if (sisa === 0) return hentikanPercobaan();
            return kodeEl.focus();
          }
          setForm(fe, g.pesan);
        }
      });
      if (percobaanHabis) btn.disabled = true; // terjaga() menyalakan lagi tombol di akhir
    });
    ulang.addEventListener('click', async () => {
      ulang.disabled = true;
      try {
        const sebelum = tantangan.sisa_kirim_ulang;
        const jawab = await klien.kirimUlangKode(tantangan.id);
        const baru = bacaTantangan(jawab);
        if (baru) tantangan = { ...tantangan, ...baru }; // nilai waktu dan kuota dipakai apa adanya dari server
        const terkirim = baru && Number.isInteger(baru.sisa_kirim_ulang) && baru.sisa_kirim_ulang < sebelum;
        // Server menjawab pesan yang sama untuk kirim ulang yang ditolak, jadi pesan server hanya dipakai bila pengiriman benar terjadi.
        toast(terkirim ? (jawab?.message || PESAN_KODE_BARU_DIKIRIM) : PESAN_KIRIM_ULANG_BELUM, 'info');
      } catch (e) {
        if (e?.perluMasukUlang) return layarMasuk('Pemeriksaan keamanan berakhir. Masukkan sandi lagi.');
        setForm(fe, klasifikasiGalat(e).pesan);
      }
      perbarui();
    });

    layar('Masukkan kode', info, form);
    tick(perbarui);
    if (!percobaanHabis) kodeEl.focus();
  }

  layarMasuk(pesanAwal);
  return { destroy: stopTimer };
}
