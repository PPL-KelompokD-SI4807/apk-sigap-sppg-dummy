// MODUL CONTOH: Data Titik Layanan (fitur 4). Tiru berkas ini untuk modul lain.
// Hapus/ganti folder _contoh saat modul titik-layanan asli dibuat (slug sama: modul asli yang dipakai).
import { defineCrudModule } from '../../components/crud-module.js';

export default defineCrudModule({
  slug: 'titik-layanan', // kunci di permissions.js + rute #/titik-layanan
  title: 'Titik Layanan',
  icon: '📍',
  description: 'Sekolah, posyandu, dan lokasi penerima MBG',
  endpoint: '/titik-layanan', // -> /api/v1/titik-layanan
  columns: [
    { key: 'nama', primary: true },
    { key: 'jenis', badge: true },
    { key: 'alamat', label: 'Alamat' },
    { key: 'kapasitas', label: 'Kapasitas', type: 'number', format: (r) => (r.kapasitas != null ? `${r.kapasitas} porsi` : '-') },
  ],
  fields: [
    { name: 'nama', label: 'Nama titik layanan', type: 'text', required: true, maxlength: 120 },
    { name: 'jenis', label: 'Jenis', type: 'select', required: true, options: [
      { value: 'sekolah', label: 'Sekolah' },
      { value: 'posyandu', label: 'Posyandu' },
      { value: 'pesantren', label: 'Pesantren' },
      { value: 'lainnya', label: 'Lainnya' },
    ] },
    { name: 'alamat', label: 'Alamat', type: 'textarea', maxlength: 255 },
    { name: 'kapasitas', label: 'Kapasitas (porsi)', type: 'number', min: 0, max: 100000, required: true },
    { name: 'jam_antar', label: 'Jam antar', type: 'time' },
    { name: 'tanggal_mulai', label: 'Tanggal mulai layanan', type: 'date' },
    { name: 'sppg_id', label: 'SPPG', type: 'relasi', relation: { endpoint: '/sppg', label: 'nama', value: 'id', as: 'sppg' } },
    { name: 'foto', label: 'Foto lokasi', type: 'photo' },
  ],
});
