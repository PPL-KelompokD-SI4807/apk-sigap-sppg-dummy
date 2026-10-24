import { h } from '../core/dom.js';

/** Tab navigasi antar layar (tautan hash): items=[{href,label,key}], active=key. */
export function navTabs(items, active, label = 'Navigasi') {
  return h('nav', { class: 'seg', 'aria-label': label },
    items.map((it) => h('a', { class: `seg-btn${it.key === active ? ' active' : ''}`, href: it.href, 'aria-current': it.key === active ? 'page' : null }, it.label)));
}
