import { h, clear, debounce, formatValue } from '../core/dom.js';
import { fetchList } from '../core/api.js';

/**
 * createListView({endpoint, columns, searchable, createHref, itemHref, extraQuery, emptyText})
 * columns: [{key, label, primary, badge, type, format(record)}]
 *  - primary: judul kartu; badge: ditampilkan sebagai lencana; lainnya: baris "label: nilai".
 */
export function createListView({ endpoint, columns, searchable = true, createHref = null, itemHref, extraQuery = {}, emptyText = 'Belum ada data.' }) {
  let page = 1;
  let search = '';
  let seq = 0;
  const primary = columns.find((c) => c.primary) || columns[0];
  const badges = columns.filter((c) => c.badge && c !== primary);
  const rows = columns.filter((c) => c !== primary && !c.badge);

  const input = h('input', { type: 'search', class: 'search', placeholder: 'Cari...', 'aria-label': 'Cari' });
  const list = h('div', { class: 'cards' });
  const pager = h('div', { class: 'pager' });
  const el = h('div', { class: 'list-view' },
    h('div', { class: 'list-head' }, searchable ? input : null, createHref ? h('a', { class: 'btn btn-primary', href: createHref }, '+ Tambah') : null),
    list, pager);

  const val = (rec, c) => (c.format ? c.format(rec) : formatValue(rec[c.key], c.type));

  function card(rec) {
    return h('a', { class: 'card', href: itemHref(rec) },
      h('div', { class: 'card-title' }, h('span', {}, val(rec, primary)), badges.map((b) => h('span', { class: 'badge' }, val(rec, b)))),
      rows.map((c) => h('div', { class: 'card-row' }, h('span', { class: 'muted' }, c.label || c.key), h('span', {}, val(rec, c)))));
  }

  async function load() {
    const my = ++seq;
    clear(list);
    list.append(h('p', { class: 'muted center pad' }, 'Memuat...'));
    try {
      const res = await fetchList(endpoint, { page, search, extra: extraQuery });
      if (my !== seq) return;
      clear(list);
      if (!res.items.length) list.append(h('p', { class: 'muted center pad' }, search ? 'Data tidak ditemukan.' : emptyText));
      res.items.forEach((r) => list.append(card(r)));
      renderPager(res);
    } catch (e) {
      if (my !== seq) return;
      clear(list);
      list.append(h('div', { class: 'empty' }, h('p', { class: 'muted' }, e.message || 'Gagal memuat data.'), h('button', { class: 'btn', type: 'button', onclick: load }, 'Coba lagi')));
      clear(pager);
    }
  }

  function renderPager({ page: p, lastPage, total }) {
    clear(pager);
    if (lastPage <= 1) return pager.append(h('span', { class: 'muted small' }, `${total} data`));
    pager.append(
      h('button', { class: 'btn', type: 'button', disabled: p <= 1, onclick: () => { page = p - 1; load(); } }, 'Sebelumnya'),
      h('span', { class: 'muted small' }, `Hal ${p} dari ${lastPage} (${total})`),
      h('button', { class: 'btn', type: 'button', disabled: p >= lastPage, onclick: () => { page = p + 1; load(); } }, 'Berikutnya'));
  }

  input.addEventListener('input', debounce(() => { search = input.value.trim(); page = 1; load(); }));
  load();
  return { el, reload: load };
}
