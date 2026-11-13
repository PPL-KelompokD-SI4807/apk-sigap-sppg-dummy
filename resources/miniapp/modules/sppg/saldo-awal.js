// Panel saldo awal SPPG (R07-1). Hanya ditampilkan bila server memberi izin.ubah_saldo_awal; hak tetap ditegakkan server.
import { h, formatValue } from '../../core/dom.js';
import { request, newKey } from '../../core/api.js';
import { resolve } from '../../core/router.js';
import { toast } from '../../core/ui.js';
import { todayWIB } from '../../core/waktu.js';
import { ringkasGalatAksi } from '../../core/errors.js';
import { formatRupiah } from '../keuangan/schema.js';
import { bolehUbahSaldoAwal, parseSaldo, validasiSaldo, validasiTanggalSaldo, payloadSaldoAwal } from './schema.js';

/** Node panel (atau null bila peran tidak membaca saldo awal dan tidak berhak mengubah). */
export function saldoAwalPanel(rec) {
  const ada = 'saldo_awal' in rec;
  const boleh = bolehUbahSaldoAwal(rec);
  if (!ada && !boleh) return null;
  const info = h('p', {}, h('strong', {}, rec.saldo_awal == null ? 'Belum diisi' : formatRupiah(rec.saldo_awal)),
    rec.saldo_awal_tanggal ? h('span', { class: 'muted small' }, ` per ${formatValue(rec.saldo_awal_tanggal, 'date')}`) : null);
  const panel = h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Saldo awal'), info,
    h('p', { class: 'muted small' }, 'Dipakai Laporan 30c/30d sebagai saldo awal periode pertama.'));
  if (!boleh) return panel;

  const nom = h('input', { type: 'text', id: 'f-saldo', inputmode: 'numeric', 'aria-describedby': 'f-saldo-err', placeholder: 'Mis. 25.000.000' });
  nom.value = rec.saldo_awal == null ? '' : String(rec.saldo_awal);
  const tgl = h('input', { type: 'date', id: 'f-saldo-tgl', max: todayWIB(), 'aria-describedby': 'f-saldo-tgl-err', value: String(rec.saldo_awal_tanggal ?? todayWIB()).slice(0, 10) });
  const errN = h('p', { class: 'field-error', id: 'f-saldo-err', role: 'alert' });
  const errT = h('p', { class: 'field-error', id: 'f-saldo-tgl-err', role: 'alert' });
  const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button' }, 'Simpan saldo awal');
  btn.addEventListener('click', async () => {
    const n = parseSaldo(nom.value);
    errN.textContent = validasiSaldo(n);
    errT.textContent = validasiTanggalSaldo(tgl.value, todayWIB());
    if (errN.textContent || errT.textContent) return;
    btn.disabled = true;
    try {
      await request('PUT', `/sppg/${rec.id}/saldo-awal`, { body: payloadSaldoAwal(n, tgl.value), key: newKey(), silent: true });
      toast('Saldo awal tersimpan.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      btn.disabled = false;
      const g = ringkasGalatAksi(e, 'Saldo awal gagal disimpan.');
      errN.textContent = g.fields.saldo_awal || '';
      errT.textContent = g.fields.saldo_awal_tanggal || '';
      if (!errN.textContent && !errT.textContent) toast(g.pesan, 'error', 5000);
    }
  });
  panel.append(h('label', { class: 'label', for: 'f-saldo' }, 'Saldo awal (Rp)'), nom, errN,
    h('label', { class: 'label', for: 'f-saldo-tgl' }, 'Per tanggal'), tgl, errT, btn);
  return panel;
}
