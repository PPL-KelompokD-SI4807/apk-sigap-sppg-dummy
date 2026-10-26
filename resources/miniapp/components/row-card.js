import { h } from '../core/dom.js';

const PALETTE = ['#2481cc', '#8e55d6', '#d9831f', '#1f9d6e', '#d0504d', '#5a7a99'];
export const initials = (name = '') => String(name).trim().split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase() || '?';
const colorOf = (name = '') => PALETTE[[...String(name)].reduce((a, c) => a + c.charCodeAt(0), 0) % PALETTE.length];

/**
 * Kartu baris ala mockup: {href, title, sub, aside, asideSub, badge:{text,cls}, avatar:true}
 * Teks selalu lewat textContent (aman dari HTML dari server).
 */
export function rowCard({ href, title, sub, aside, asideSub, badge, badges, avatar = false }) {
  const all = [badge, ...(badges || [])].filter(Boolean);
  return h('a', { class: 'row-card', href },
    avatar ? h('span', { class: 'avatar', 'aria-hidden': 'true', style: `background:${colorOf(title)}` }, initials(title)) : null,
    h('span', { class: 'rc-main' }, h('span', { class: 'rc-title' }, title), sub ? h('div', { class: 'rc-sub' }, sub) : null),
    aside != null ? h('span', { class: 'rc-aside' }, h('strong', {}, aside), asideSub ? h('span', { class: 'muted small' }, asideSub) : null) : null,
    all.length ? h('span', { class: 'rc-badges' }, all.map((b) => h('span', { class: `badge ${b.cls || ''}`.trim() }, b.text))) : null);
}
