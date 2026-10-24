import { getTg } from './telegram.js';

const MAP = {
  bg_color: '--bg', text_color: '--text', hint_color: '--hint', link_color: '--link',
  button_color: '--btn', button_text_color: '--btn-text', secondary_bg_color: '--bg-secondary',
  header_bg_color: '--bg-header', section_bg_color: '--bg-section', section_separator_color: '--separator',
  accent_text_color: '--accent', destructive_text_color: '--danger',
};

export function applyTheme() {
  const tg = getTg();
  const root = document.documentElement;
  const params = tg?.themeParams || {};
  for (const [key, cssVar] of Object.entries(MAP)) {
    if (params[key]) root.style.setProperty(cssVar, params[key]);
  }
  const scheme = tg?.colorScheme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  root.dataset.theme = scheme;
  try {
    tg?.setHeaderColor?.(params.header_bg_color || params.bg_color || 'bg_color');
    tg?.setBackgroundColor?.(params.secondary_bg_color || params.bg_color || 'bg_color');
  } catch { /* versi lama */ }
}

export function initTheme() {
  applyTheme();
  const tg = getTg();
  tg?.onEvent?.('themeChanged', applyTheme);
  matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => !tg?.themeParams?.bg_color && applyTheme());
}
