// Skema Penerima Manfaat (fitur 5). Kontrak: docs/api/paths/penerima-manfaat.yaml. Tanpa NIK (UU PDP).
import { buildPayload, activeField } from '../../core/schema-helpers.js';
import { noNik } from '../../core/validators.js';

export const KELOMPOK = [
  { value: 'siswa', label: 'Siswa' },
  { value: 'balita', label: 'Balita' },
  { value: 'ibu_hamil', label: 'Ibu hamil' },
  { value: 'ibu_menyusui', label: 'Ibu menyusui' },
  { value: 'pendidik', label: 'Pendidik' },
  { value: 'tenaga_kependidikan', label: 'Tenaga kependidikan' },
  { value: 'ats', label: 'Anak tidak sekolah (ATS)' },
];
export const JENIS_KELAMIN = [
  { value: 'laki_laki', label: 'Laki-laki' },
  { value: 'perempuan', label: 'Perempuan' },
];
export const JENJANG = [
  { value: 'paud_tk', label: 'PAUD/TK' },
  { value: 'sd_1_3', label: 'SD kelas 1-3' },
  { value: 'sd_4_6', label: 'SD kelas 4-6' },
  { value: 'smp', label: 'SMP' },
  { value: 'sma', label: 'SMA/SMK' },
  { value: 'ats_kurang_9', label: 'ATS usia kurang dari 9 tahun' },
  { value: 'ats_9_18', label: 'ATS usia 9-18 tahun' },
];
export const JENIS_SATUAN = [
  { value: 'reguler', label: 'Reguler' },
  { value: 'slb', label: 'SLB' },
  { value: 'pesantren', label: 'Pesantren (santri)' },
  { value: 'pendidikan_khusus', label: 'Pendidikan khusus lainnya' },
  { value: 'informal', label: 'Informal' },
];
const jenjangAts = (v) => v === 'ats_kurang_9' || v === 'ats_9_18';
/** Aturan silang (cermin server; server tetap otoritas): siswa -> bukan ats_*; ats -> wajib ats_*; kelompok lain kosong. */
export function validasiJenjang(jenjang, kelompok) {
  const j = jenjang || '';
  if (kelompok === 'siswa') return jenjangAts(j) ? 'Jenjang siswa tidak boleh ATS. Pilih PAUD/TK sampai SMA.' : '';
  if (kelompok === 'ats') return jenjangAts(j) ? '' : 'Kelompok ATS wajib memilih jenjang ATS (kurang dari 9 tahun atau 9-18 tahun).';
  return j ? 'Jenjang hanya untuk kelompok Siswa atau ATS.' : '';
}
export const validasiJenisSatuan = (satuan, kelompok) => (satuan && kelompok !== 'siswa' ? 'Jenis satuan hanya untuk kelompok Siswa.' : '');
export const kelompokLabel = (v) => KELOMPOK.find((k) => k.value === v)?.label ?? v ?? '-';

export const penerimaFields = () => [
  { name: 'titik_layanan_id', label: 'Titik layanan', type: 'relasi', required: true, relation: { endpoint: '/titik-layanan', label: 'nama', value: 'id', as: 'titik_layanan', query: { is_active: 1 } }, display: (r) => r.titik_layanan_nama ?? r.titik_layanan?.nama ?? '-' },
  { name: 'nama', label: 'Nama (inisial)', type: 'text', required: true, maxlength: 100, help: 'Cukup inisial atau nama minimal. Jangan isi NIK atau nomor identitas.', validate: (v) => noNik(v) },
  { name: 'kelompok_sasaran', label: 'Kelompok sasaran', type: 'select', required: true, options: KELOMPOK },
  { name: 'jenis_kelamin', label: 'Jenis kelamin', type: 'select', options: JENIS_KELAMIN, help: 'Opsional; dipakai laporan BGN (hanya hitungan).' },
  { name: 'jenjang', label: 'Jenjang (Lampiran 30)', type: 'select', options: JENJANG, showIf: (v) => ['siswa', 'ats'].includes(v.kelompok_sasaran), validate: (v, all) => validasiJenjang(v, all.kelompok_sasaran), help: 'Siswa: PAUD/TK sampai SMA. ATS: pilih jenjang ATS.' },
  { name: 'jenis_satuan', label: 'Jenis satuan pendidikan', type: 'select', options: JENIS_SATUAN, showIf: (v) => v.kelompok_sasaran === 'siswa', validate: (v, all) => validasiJenisSatuan(v, all.kelompok_sasaran) },
  { name: 'kelas_keterangan', label: 'Kelas / keterangan', type: 'text', maxlength: 100, placeholder: 'Mis. Kelas 4A' },
  { name: 'catatan_alergi', label: 'Catatan alergi / kebutuhan khusus', type: 'textarea', maxlength: 1000, help: 'Menjadi masukan bagi Ahli Gizi.' },
  activeField(),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['titik_layanan_id', 'nama', 'kelompok_sasaran', 'jenis_kelamin', 'jenjang', 'jenis_satuan', 'kelas_keterangan', 'catatan_alergi', 'is_active'],
  ints: ['titik_layanan_id'],
  nullable: ['jenis_kelamin', 'jenjang', 'jenis_satuan', 'kelas_keterangan', 'catatan_alergi'],
  bools: ['is_active'],
});
