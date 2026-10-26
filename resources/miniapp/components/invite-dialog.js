import { h } from '../core/dom.js';
import { getTg } from '../core/telegram.js';
import { toast } from '../core/ui.js';
import { buildShareUrl, isTelegramLink, hoursLeft } from '../core/share.js';

export async function copyText(text) {
  try {
    if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(text); return true; }
  } catch { /* lanjut ke cadangan */ }
  try {
    const ta = h('textarea', { 'aria-hidden': 'true', style: 'position:fixed;opacity:0;left:-999px' });
    ta.value = text;
    document.body.append(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  } catch { return false; }
}

export async function shareLink(link, text) {
  const tg = getTg();
  if (typeof tg?.openTelegramLink === 'function') { tg.openTelegramLink(buildShareUrl(link, text)); return true; }
  if (navigator.share) {
    try { await navigator.share({ text, url: link }); return true; } catch { /* dibatalkan: lanjut salin */ }
  }
  return copyText(link);
}

/**
 * Tampilkan tautan undangan SEKALI. `inv` = data respons POST undangan ({deep_link, expires_at, ...}).
 * Token mentah tidak pernah ditampilkan/disimpan; tautan hilang dari DOM begitu dialog ditutup.
 * Mengembalikan Promise yang selesai saat dialog ditutup.
 */
export function showInviteLink(inv, { title = 'Tautan undangan', shareText = 'Undangan SiGAP SPPG: buka tautan ini di Telegram.' } = {}) {
  return new Promise((resolve) => {
    let link = isTelegramLink(inv?.deep_link) ? inv.deep_link : null;
    const jam = hoursLeft(inv?.expires_at);
    const opener = document.activeElement;
    const close = () => {
      link = null;
      linkEl.textContent = '';
      document.removeEventListener('keydown', onKey);
      overlay.remove();
      opener?.focus?.();
      resolve();
    };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const linkEl = h('p', { class: 'invite-link', id: 'invite-link' }, link || '');
    const body = link
      ? [
        h('p', {}, 'Kirim tautan ini ke calon pengguna. Tautan hanya berlaku sekali pakai', jam != null ? ` dan kedaluwarsa dalam sekitar ${jam} jam.` : '.'),
        linkEl,
        h('p', { class: 'muted small' }, 'Tautan hanya tampil sekarang. Setelah ditutup, buat undangan baru bila perlu.'),
        h('div', { class: 'row-actions' },
          h('button', { class: 'btn', type: 'button', onclick: async () => toast((await copyText(link)) ? 'Tautan disalin.' : 'Gagal menyalin. Tekan lama pada tautan untuk menyalin.', 'info') }, 'Salin'),
          h('button', { class: 'btn btn-primary', type: 'button', onclick: async () => { if (link && (await shareLink(link, shareText))) toast('Tautan dibagikan atau disalin.', 'info'); } }, 'Bagikan')),
      ]
      : [h('p', {}, 'Undangan dibuat, tetapi tautan Telegram belum tersedia karena nama bot belum diatur di server. Hubungi admin sistem lalu buat undangan ulang.')];
    const overlay = h('div', { class: 'modal-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'invite-title' },
      h('div', { class: 'modal invite' }, h('h2', { id: 'invite-title' }, title), body,
        h('div', { class: 'modal-actions' }, h('button', { class: 'btn', type: 'button', id: 'invite-close', onclick: close }, 'Tutup'))));
    document.addEventListener('keydown', onKey);
    document.body.append(overlay);
    overlay.querySelector('#invite-close').focus();
  });
}
