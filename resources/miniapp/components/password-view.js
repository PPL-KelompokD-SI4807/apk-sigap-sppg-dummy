// Ubah password (R-03). Password hanya hidup di kolom formulir dan dikosongkan setelah dikirim.
import { h, clear } from '../core/dom.js';
import { ubahPassword, getUser } from '../core/auth.js';
import { toast } from '../core/ui.js';
import { mapValidationErrors } from '../core/errors.js';
import { aturanPassword, passwordValid, pesanGalatAkun } from '../core/kredensial.js';

export function renderUbahPassword(ctx) {
  const mk = (name, ac) => h('input', { type: 'password', name, autocomplete: ac, required: true });
  const lamaEl = mk('password_lama', 'current-password');
  const baruEl = mk('password', 'new-password');
  const konfEl = mk('password_confirmation', 'new-password');
  const errBox = () => h('p', { class: 'field-error', role: 'alert' });
  const boxes = { password_lama: errBox(), password: errBox(), password_confirmation: errBox() };
  const fe = h('p', { class: 'notice', role: 'alert', hidden: true });
  const ul = h('ul', { class: 'pw-rules small', 'aria-live': 'polite' });
  const rules = () => {
    clear(ul);
    for (const r of aturanPassword(baruEl.value, getUser()?.username)) ul.append(h('li', { class: r.ok ? 'ok' : 'no' }, `${r.ok ? '✓' : '○'} ${r.teks}`));
  };
  rules();
  baruEl.addEventListener('input', rules);

  const field = (label, input, key) => h('label', { class: 'field' }, h('span', { class: 'label' }, label), input, boxes[key]);
  const show = (map) => {
    let any = false;
    for (const [k, box] of Object.entries(boxes)) {
      box.textContent = map[k] || '';
      box.closest('.field')?.classList.toggle('invalid', !!map[k]);
      any = any || !!map[k];
    }
    return any;
  };
  const setFe = (t) => { fe.textContent = t || ''; fe.hidden = !t; };

  const btn = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Ubah password');
  const form = h('form', { class: 'form', novalidate: true },
    field('Password lama', lamaEl, 'password_lama'),
    field('Password baru', baruEl, 'password'), ul,
    field('Ulangi password baru', konfEl, 'password_confirmation'), fe, btn);
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    setFe('');
    const local = {};
    if (!lamaEl.value) local.password_lama = 'Wajib diisi.';
    if (!passwordValid(baruEl.value, getUser()?.username)) local.password = 'Password belum memenuhi aturan di atas.';
    else if (baruEl.value === lamaEl.value) local.password = 'Password baru harus berbeda dari password lama.';
    if (konfEl.value !== baruEl.value) local.password_confirmation = 'Konfirmasi password tidak sama.';
    if (show(local)) return;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    try {
      const r = await ubahPassword({ password_lama: lamaEl.value, password: baruEl.value, password_confirmation: konfEl.value });
      form.reset();
      rules();
      show({});
      toast(r?.message || 'Password diubah.', 'success');
    } catch (e) {
      lamaEl.value = '';
      if (e.status === 422) {
        const sisa = e.data?.sisa_percobaan;
        if (e.data?.alasan === 'kredensial_salah') show({ password_lama: `Password lama salah.${Number.isInteger(sisa) ? ` Sisa percobaan: ${sisa}.` : ''}` });
        else if (!show(mapValidationErrors(e.errors))) setFe(e.message);
      } else if (e.status !== 401) setFe(pesanGalatAkun(e) || e.message || 'Gagal mengubah password.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Ubah password';
    }
  });
  ctx.el.append(h('p', { class: 'muted small' }, 'Setelah password diubah, perangkat lain akan keluar dan harus masuk lagi. Perangkat ini tetap masuk.'), form);
}
