// Pola bersama filter tanggal (acuan P12 Rekap): label terlihat + input yang boleh menyusut (tanpa melebar keluar layar di 360 px).
import { h } from '../core/dom.js';

export function kolomTanggal(label, id, input) {
  if (!input.placeholder) input.placeholder = 'HH/BB/TTTT';
  return h('div', { class: 'stack-tight filter-col' }, h('label', { class: 'label small muted', for: id }, label), input);
}
