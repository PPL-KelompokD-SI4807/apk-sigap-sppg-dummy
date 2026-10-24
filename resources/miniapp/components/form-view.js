import { h } from '../core/dom.js';
import { fetchList } from '../core/api.js';
import { createPhotoUpload } from './photo-upload.js';
import { setMainButton, setMainButtonLoading, hideMainButton } from '../core/ui.js';

export const LOW_CONFIDENCE = 0.7;

/**
 * Skema kolom: {name,label,type,required,options,min,max,maxlength,step,placeholder,help,default,
 *   relation:{endpoint,label='nama',value='id'}, readonly}
 * type: text|number|date|time|select|textarea|photo|relasi
 *
 * createFormView({fields, values, confidence, submitText, onSubmit}) -> {el, destroy, setErrors}
 * onSubmit(values) -> Promise; boleh melempar ApiError 422 (errors ditampilkan di bawah kolom).
 */
export function createFormView({ fields, values = {}, confidence = {}, submitText = 'Simpan', onSubmit }) {
  const controls = {}; // name -> {field, wrap, errEl, get(), set(v), hasValue()}
  const form = h('form', { class: 'form', novalidate: true });
  let submitting = false;

  for (const f of fields) {
    const type = f.type === 'relation' ? 'relasi' : f.type || 'text';
    const id = `f-${f.name}`;
    const errEl = h('p', { class: 'field-error', id: `${id}-err`, role: 'alert' });
    const flag = h('span', { class: 'badge badge-warn', hidden: true }, 'Perlu dicek');
    const label = h('label', { class: 'label', for: id }, f.label || f.name, f.required ? h('span', { class: 'req' }, ' *') : null, ' ', flag);
    const wrap = h('div', { class: 'field', dataset: { name: f.name } });
    const initial = values[f.name] ?? f.default ?? '';
    let ctl;

    if (type === 'photo') {
      const up = createPhotoUpload({ name: f.name, existingUrl: typeof initial === 'string' && initial ? initial : null, onChange: clearFlag });
      ctl = { get: () => up.getFile(), set: () => {}, hasValue: up.hasValue, setError: up.setError, focusEl: null };
      wrap.append(label, up.el);
    } else {
      let input;
      const common = { id, name: f.name, 'aria-describedby': `${id}-err`, placeholder: f.placeholder, disabled: f.readonly };
      if (type === 'textarea') input = h('textarea', { ...common, rows: 3, maxlength: f.maxlength });
      else if (type === 'select' || type === 'relasi') {
        input = h('select', common, h('option', { value: '' }, '- Pilih -'));
        const opts = (f.options || []).map((o) => (typeof o === 'object' ? o : { value: o, label: o }));
        opts.forEach((o) => input.append(h('option', { value: o.value }, o.label)));
        if (type === 'relasi') loadRelation(f, input, () => values[f.name] ?? f.default);
      } else {
        const htmlType = { number: 'number', date: 'date', time: 'time' }[type] || 'text';
        input = h('input', { ...common, type: htmlType, min: f.min, max: f.max, maxlength: f.maxlength, step: f.step, inputmode: type === 'number' ? 'decimal' : undefined, pattern: f.pattern });
      }
      if (type === 'time' && typeof initial === 'string') input.value = initial.slice(0, 5);
      else if (type === 'date' && typeof initial === 'string') input.value = initial.slice(0, 10);
      else input.value = initial ?? '';
      input.addEventListener('input', clearFlag);
      input.addEventListener('change', clearFlag);
      ctl = {
        get: () => {
          const v = input.value.trim?.() ?? input.value;
          if (v === '') return null;
          return type === 'number' ? Number(v) : v;
        },
        set: (v) => { input.value = v ?? ''; },
        hasValue: () => input.value.trim() !== '',
        setError: null,
        focusEl: input,
      };
      wrap.append(label, input);
    }
    if (f.help) wrap.append(h('p', { class: 'muted small' }, f.help));
    wrap.append(errEl);
    function clearFlag() { flag.hidden = true; wrap.classList.remove('needs-check'); }
    ctl.field = { ...f, type };
    ctl.wrap = wrap;
    ctl.errEl = errEl;
    const conf = confidence[f.name];
    if (typeof conf === 'number' && conf < LOW_CONFIDENCE) { flag.hidden = false; wrap.classList.add('needs-check'); }
    controls[f.name] = ctl;
    form.append(wrap);
  }

  function showError(name, msg) {
    const c = controls[name];
    if (!c) return false;
    c.errEl.textContent = msg || '';
    c.wrap.classList.toggle('invalid', !!msg);
    c.setError?.(msg);
    return true;
  }

  function clearErrors() { Object.keys(controls).forEach((n) => showError(n, '')); }

  function validate() {
    clearErrors();
    let first = null;
    for (const [name, c] of Object.entries(controls)) {
      const f = c.field;
      const v = c.get();
      let msg = '';
      if (f.required && !c.hasValue()) msg = `${f.label || name} wajib diisi.`;
      else if (v != null && typeof v !== 'object') {
        if (f.type === 'number') {
          if (Number.isNaN(v)) msg = 'Harus berupa angka.';
          else if (f.min != null && v < f.min) msg = `Minimal ${f.min}.`;
          else if (f.max != null && v > f.max) msg = `Maksimal ${f.max}.`;
        } else if (f.maxlength && String(v).length > f.maxlength) msg = `Maksimal ${f.maxlength} karakter.`;
        else if (f.pattern && !new RegExp(`^(?:${f.pattern})$`).test(String(v))) msg = f.patternMessage || 'Format tidak sesuai.';
      }
      if (msg) { showError(name, msg); first = first || c; }
    }
    first?.focusEl?.focus();
    first?.wrap.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return !first;
  }

  function setErrors(errors = {}) {
    let first = true;
    const unmatched = [];
    for (const [name, msgs] of Object.entries(errors)) {
      const msg = Array.isArray(msgs) ? msgs[0] : msgs;
      if (showError(name, msg)) {
        if (first) { controls[name].wrap.scrollIntoView({ block: 'center', behavior: 'smooth' }); first = false; }
      } else unmatched.push(msg);
    }
    return unmatched;
  }

  function collect() {
    const out = {};
    for (const [name, c] of Object.entries(controls)) {
      const v = c.get();
      if (c.field.type === 'photo') { if (v) out[name] = v; } // tanpa foto baru = tidak dikirim
      else if (!c.field.readonly) out[name] = v;
    }
    return out;
  }

  async function submit() {
    if (submitting || !validate()) return;
    submitting = true;
    setMainButtonLoading(true);
    try {
      await onSubmit(collect());
    } catch (e) {
      if (e?.status === 422 && e.errors) {
        const rest = setErrors(e.errors);
        if (rest.length) formErr.textContent = rest.join(' ');
      }
    } finally {
      submitting = false;
      setMainButtonLoading(false, submitText);
    }
  }

  const formErr = h('p', { class: 'field-error', role: 'alert' });
  form.append(formErr);
  form.addEventListener('submit', (ev) => { ev.preventDefault(); submit(); });
  setMainButton({ text: submitText, onClick: submit });

  return { el: form, destroy: hideMainButton, setErrors, validate, collect };
}

async function loadRelation(f, select, getInitial) {
  const rel = f.relation || {};
  try {
    const { items } = await fetchList(rel.endpoint, { perPage: 100 });
    items.forEach((it) => select.append(h('option', { value: it[rel.value || 'id'] }, it[rel.label || 'nama'] ?? it.name ?? it.id)));
    const init = getInitial();
    if (init != null) select.value = init;
  } catch {
    select.append(h('option', { value: '', disabled: true }, 'Gagal memuat pilihan'));
  }
}
