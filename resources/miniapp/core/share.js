// Pembantu tautan undangan (murni, tanpa DOM).

/** URL berbagi Telegram: https://t.me/share/url?url=...&text=... */
export function buildShareUrl(link, text = '') {
  const q = [`url=${encodeURIComponent(link)}`];
  if (text) q.push(`text=${encodeURIComponent(text)}`);
  return `https://t.me/share/url?${q.join('&')}`;
}

/** Hanya terima tautan t.me https (cegah skema lain dari respons yang tak terduga). */
export function isTelegramLink(link) {
  return typeof link === 'string' && /^https:\/\/t\.me\/[A-Za-z0-9_]+\?start=[A-Za-z0-9_-]+$/.test(link);
}

/** Sisa masa berlaku dalam jam (dibulatkan ke atas), minimal 0. */
export function hoursLeft(expiresAt, now = Date.now()) {
  const t = Date.parse(expiresAt);
  if (Number.isNaN(t)) return null;
  return Math.max(0, Math.ceil((t - now) / 3600000));
}
