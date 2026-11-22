// Kerangka WEB setelah masuk: bar atas melayang (lambang, nav pil, sakelar tema, chip pengguna) + rel ikon lengket.
// Dimuat lazy oleh web-main.js (layar Masuk tidak memuatnya). Isi nav dan rel dibangun dari menuFor(user) + pintasanWeb(user)
// lewat susunButir(); tombol Kembali (#back) dan judul (#apptitle) tetap dipegang router (core/router.js).
import { h } from './core/dom.js';
import { ikon, ikonAtauEmoji } from './core/ikon-web.js';
import { roleLabel } from './core/permissions.js';
import { showConfirm, toast } from './core/ui.js';
import { susunButir, butirAktif, pathDariHash, inisial } from './core/navigasi-web.js';
import { buatSakelarTema } from './components/sakelar-tema-web.js';

const kosongkan = (el) => el.replaceChildren();

function lencanaMerek() {
  return h('a', { class: 'brand', href: '#/', 'aria-label': 'SiGAP SPPG, ke beranda' },
    h('span', { class: 'logo', 'aria-hidden': 'true' }, ikon('merek', { ukuran: 16, tebal: 2 })),
    h('b', {}, 'SiGAP SPPG'));
}

/**
 * pasangKerangka({user, menu, pintasan, klien, onKeluar}) -> {perbarui, hancurkan}.
 * Mengisi #topbar dan #rail yang sudah ada di web.html, menyalakan #appbar (judul + Kembali), dan menandai halaman aktif.
 */
export function pasangKerangka({ user, menu = [], pintasan = [], klien, onKeluar } = {}) {
  const u = user ?? {};
  const topbar = document.getElementById('topbar');
  const rail = document.getElementById('rail');
  const butir = susunButir({ menu, pintasan });
  document.body.classList.add('dalam');
  const kepala = document.getElementById('appbar');
  if (kepala) kepala.hidden = false;

  // ---- nav pil di tengah bar atas ----
  // Semua butir dirender sebagai pil. Layar >= 1280: butir paling belakang yang tidak muat dipindah ke popover "Lainnya"
  // (fit() mengukur, jadi label panjang tidak pernah menimpa tombol di sebelahnya). Layar lebih sempit: semua pil sebaris dan bisa digeser.
  const nav = h('nav', { class: 'nav', 'aria-label': 'Menu utama' });
  const grup = h('div', { class: 'nav-grup' });
  const pil = butir.map((b) => h('a', { class: 'nav-pil', href: b.href, dataset: { kunci: b.kunci } }, b.label));
  const cermin = butir.map((b) => h('a', { class: 'nav-pop-item', href: b.href, hidden: true, dataset: { kunci: b.kunci } }, b.label));
  const rincian = h('details', { class: 'nav-lainnya', hidden: true },
    h('summary', { class: 'nav-pil' }, 'Lainnya'), h('div', { class: 'nav-pop' }, cermin));
  grup.append(...pil, rincian);
  nav.append(grup);
  // Fokus keyboard: peramban hanya menggulir bila elemen sama sekali tidak terlihat, jadi pil bisa tersisa beberapa px di tepi. Paksa terlihat utuh.
  grup.addEventListener('focusin', (e) => e.target?.scrollIntoView?.({ inline: 'nearest', block: 'nearest' }));
  const lebar = globalThis.matchMedia?.('(min-width: 1280px)');
  // Pudar kanan (mask) hanya selagi masih ada pil di kanan; di ujung gulir pil terakhir tampil utuh (CSS: .nav-bergulir:not(.nav-ujung)).
  const tandaiUjung = () => grup.classList.toggle('nav-ujung', grup.scrollLeft + grup.clientWidth >= grup.scrollWidth - 1);
  grup.addEventListener('scroll', tandaiUjung, { passive: true });
  function fit() {
    pil.forEach((p) => { p.hidden = false; });
    cermin.forEach((c) => { c.hidden = true; });
    rincian.hidden = true;
    if (!lebar?.matches) { grup.classList.toggle('nav-bergulir', grup.scrollWidth > grup.clientWidth + 1); tandaiUjung(); return; }
    grup.classList.remove('nav-bergulir');
    if (grup.offsetWidth <= nav.clientWidth) return;
    rincian.hidden = false;
    for (let i = pil.length - 1; i > 0 && grup.offsetWidth > nav.clientWidth; i--) { pil[i].hidden = true; cermin[i].hidden = false; }
    rincian.open = false;
  }

  // ---- chip pengguna ----
  const peran = u.role_label || roleLabel(u.role);
  const chip = h('a', { class: 'user', href: '#/sesi', 'aria-label': `Akun ${u.name || ''}, ${peran || ''}. Buka Sesi dan Browser` },
    h('span', { class: 'avatar', 'aria-hidden': 'true' }, inisial(u.name)),
    h('span', { class: 'user-meta' },
      h('b', {}, u.name || '-'),
      h('span', {}, [peran, u.sppg_name].filter(Boolean).join(' · '))));

  kosongkan(topbar);
  topbar.append(lencanaMerek(), nav, h('div', { class: 'tools' }, buatSakelarTema()), chip);
  topbar.hidden = false;

  // ---- rel ikon kiri ----
  const tautRel = (b) => h('a', { class: 'rail-btn', href: b.href, title: b.label, 'aria-label': b.label, dataset: { kunci: b.kunci } },
    ikonAtauEmoji(b.kunci || 'beranda', b.emoji, { ukuran: 19 }));
  const utama = butir.filter((b) => b.grup === 'utama');
  const modul = butir.filter((b) => b.grup === 'modul');
  const keluar = h('button', { class: 'rail-btn', type: 'button', title: 'Keluar', 'aria-label': 'Keluar' }, ikon('keluar', { ukuran: 19 }));
  keluar.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Keluar', message: 'Keluar dari browser ini? Browser tepercaya tidak dicabut.', okText: 'Keluar' }))) return;
    keluar.disabled = true;
    try { await klien?.logout?.(); onKeluar?.(); } catch (e) { keluar.disabled = false; toast(e?.message || 'Gagal keluar.', 'error'); }
  });
  kosongkan(rail);
  // Bagian atas (beranda, pintasan, modul) bisa digulir; bagian bawah (Sesi, Keluar) selalu terlihat.
  rail.append(
    h('div', { class: 'rail-gulir' }, ...[...utama.map(tautRel), modul.length ? h('hr', { class: 'rail-pisah' }) : null, ...modul.map(tautRel)].filter(Boolean)),
    h('div', { class: 'rail-bawah' },
      h('hr', { class: 'rail-pisah' }),
      h('a', { class: 'rail-btn', href: '#/sesi', title: 'Sesi dan Browser', 'aria-label': 'Sesi dan Browser', dataset: { kunci: 'sesi' } }, ikon('sesi', { ukuran: 19 })),
      keluar));
  rail.hidden = false;
  rail.addEventListener('focusin', (e) => { if (e.target?.closest?.('.rail-gulir')) e.target.scrollIntoView?.({ inline: 'nearest', block: 'nearest' }); }); // rel = baris geser di lebar sempit; masalahnya sama dengan nav

  // ---- status aktif ----
  const semua = () => [...topbar.querySelectorAll('[data-kunci]'), ...rail.querySelectorAll('[data-kunci]')];
  function perbarui() {
    const path = pathDariHash(location.hash);
    const aktif = butirAktif(path, butir);
    document.body.dataset.rute = path === '/' ? 'beranda' : 'halaman';
    for (const a of semua()) {
      if (aktif !== null && a.dataset.kunci === aktif) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    }
    fit();
    const sembunyi = pil.findIndex((p) => p.hidden);
    const diLainnya = aktif !== null && sembunyi > 0 && butir.slice(sembunyi).some((b) => b.kunci === aktif);
    const ringkas = rincian.querySelector('summary');
    if (diLainnya) ringkas.setAttribute('aria-current', 'page'); else ringkas.removeAttribute('aria-current');
    rincian.open = false;
    // Pil aktif tetap terlihat pada baris nav yang bisa digeser.
    const sel = grup.querySelector('.nav-pil[aria-current="page"]');
    if (sel?.scrollIntoView && grup.scrollWidth > grup.clientWidth) sel.scrollIntoView({ block: 'nearest', inline: 'center' });
  }
  const tutupLainnya = (e) => { if (rincian.open && !rincian.contains(e.target)) rincian.open = false; };
  const tutupEsc = (e) => { if (e.key === 'Escape' && rincian.open) { rincian.open = false; rincian.querySelector('summary')?.focus(); } };
  addEventListener('hashchange', perbarui);
  document.addEventListener('click', tutupLainnya);
  document.addEventListener('keydown', tutupEsc);
  perbarui();
  const pengamat = typeof ResizeObserver === 'function' ? new ResizeObserver(() => { fit(); }) : null;
  pengamat?.observe(nav);
  globalThis.document.fonts?.ready?.then(fit);

  return {
    perbarui,
    hancurkan() {
      pengamat?.disconnect();
      removeEventListener('hashchange', perbarui);
      document.removeEventListener('click', tutupLainnya);
      document.removeEventListener('keydown', tutupEsc);
    },
  };
}
