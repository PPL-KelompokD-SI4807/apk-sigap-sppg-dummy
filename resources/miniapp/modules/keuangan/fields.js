// Kolom kustom Keuangan (DOM): nominal rupiah (tampil berformat ribuan, kirim integer) dan periode bulan.
import { h } from '../../core/dom.js';
import { formatRibuan, parseRupiah, validasiNominal, periodeSekarang, validasiPeriode } from './schema.js';

/** Kolom nominal rupiah. get() -> integer | null (kosong) | NaN (tidak valid; ditolak validator). */
export function rupiahField(name, label) {
  return {
    name, label, type: 'custom', required: true,
    validate: (v) => validasiNominal(v, label),
    build: ({ value, onChange }) => {
      const input = h('input', { id: `f-${name}`, name, type: 'text', inputmode: 'numeric', autocomplete: 'off', placeholder: '0', 'aria-describedby': `f-${name}-err`, class: 'rupiah-input', value: typeof value === 'number' ? formatRibuan(value) : '' });
      input.addEventListener('input', () => {
        const fromEnd = input.value.length - (input.selectionStart ?? input.value.length);
        const n = parseRupiah(input.value);
        if (typeof n === 'number' && !Number.isNaN(n)) {
          input.value = formatRibuan(n);
          const pos = Math.max(0, input.value.length - fromEnd);
          try { input.setSelectionRange(pos, pos); } catch { /* abaikan */ }
        }
        onChange?.();
      });
      return {
        el: h('div', { class: 'rupiah-wrap' }, h('span', { class: 'rupiah-prefix', 'aria-hidden': 'true' }, 'Rp'), input),
        get: () => parseRupiah(input.value),
        set: (v) => { input.value = typeof v === 'number' ? formatRibuan(v) : ''; },
        hasValue: () => input.value.trim() !== '',
        focusEl: input,
      };
    },
  };
}

/** Kolom periode bulan (TTTT-BB); input type=month, jatuh ke teks berpola bila peramban tak mendukung. */
export function periodeField() {
  return {
    type: 'custom',
    build: ({ value, onChange }) => {
      const input = h('input', { id: 'f-periode', name: 'periode', type: 'month', pattern: '\\d{4}-(0[1-9]|1[0-2])', placeholder: 'TTTT-BB, mis. 2026-11', 'aria-describedby': 'f-periode-err', value: value || periodeSekarang() });
      input.addEventListener('change', () => onChange?.());
      return { el: input, get: () => (input.value.trim() === '' ? null : input.value.trim()), set: (v) => { input.value = v ?? ''; }, hasValue: () => input.value.trim() !== '', focusEl: input };
    },
  };
}
export { validasiPeriode };
