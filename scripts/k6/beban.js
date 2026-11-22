// NFR-01 uji beban SiGAP SPPG. HANYA untuk staging/lokal, jangan produksi. Lihat docs/ops/uji-beban.md.
// Env: BASE_URL (wajib, mis. https://staging.example.test),
//      K6_TOKENS (wajib, daftar token Bearer dipisah koma, SATU TOKEN PER AKUN UJI BERBEDA; jangan ditulis di berkas),
//      K6_TOKEN (fallback satu token; throttle 60/menit per pengguna pasti kena, hasil tidak valid),
//      K6_SPPG_ID (wajib bila token milik Admin, karena POST /keluhan meminta sppg_id).
// Throttle `api` = 60 permintaan/menit per pengguna dan TIDAK dilonggarkan. Hitungan laju:
//   50 VU dibagi rata ke 10 token = 5 VU/token (token = TOKENS[(__VU - 1) % n]).
//   Iterasi baca = 1 GET acak + sleep(5..10 dtk, rata-rata 7,5 dtk) ~ 8 iterasi/menit/VU.
//   Per token: 5 VU x ~8 permintaan/menit = ~40 permintaan/menit (< 60). Skenario tulis menambah
//   5 POST per token sekali di awal => ~45 pada menit pertama, tetap < 60.
//   (Dengan 4 GET per iterasi akan ~160/menit/token dan kena 429, karena itu 1 GET per iterasi.)
import http from 'k6/http';
import { check, sleep, fail } from 'k6';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const TOKENS_ENV = __ENV.K6_TOKENS || __ENV.K6_TOKEN || '';
const TOKENS = TOKENS_ENV.split(',').map((t) => t.trim()).filter(Boolean);
const SPPG_ID = __ENV.K6_SPPG_ID ? parseInt(__ENV.K6_SPPG_ID, 10) : null;

export const options = {
  scenarios: {
    baca: {
      executor: 'constant-vus',
      exec: 'baca',
      vus: 50,
      duration: '5m',
      tags: { skenario: 'baca' },
    },
    tulis: {
      executor: 'per-vu-iterations',
      exec: 'tulis',
      vus: 50,
      iterations: 1,
      maxDuration: '5m',
      tags: { skenario: 'tulis' },
    },
  },
  thresholds: {
    'http_req_duration{skenario:baca}': ['p(95)<500'],
    'http_req_duration{skenario:tulis}': ['p(95)<1000'],
    http_req_failed: ['rate<0.01'],
  },
};

export function setup() {
  if (!BASE_URL) fail('BASE_URL wajib diisi, mis. $env:BASE_URL="https://staging.example.test"');
  if (TOKENS.length === 0) fail('K6_TOKENS wajib diisi (token Bearer, dipisah koma, satu per akun uji).');
  if (!__ENV.K6_TOKENS || TOKENS.length < 10) {
    console.warn(`PERINGATAN: hanya ${TOKENS.length} token. Dengan 50 VU, tiap token menerima >60 permintaan/menit sehingga throttle (429) akan kena dan hasil tidak mewakili kinerja aplikasi. Pakai 10 token dari 10 akun berbeda.`);
  }
}

function headers() {
  const token = TOKENS[(__VU - 1) % TOKENS.length];
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
}

const ENDPOINT_BACA = ['/api/v1/jadwal', '/api/v1/jadwal/distribusi', '/api/v1/bahan-baku', '/api/v1/dashboard'];

export function baca() {
  const path = ENDPOINT_BACA[Math.floor(Math.random() * ENDPOINT_BACA.length)];
  const r = http.get(`${BASE_URL}${path}`, { headers: headers() });
  check(r, { 'baca status 200': (x) => x.status === 200 });
  sleep(5 + Math.random() * 5);
}

export function tulis() {
  const body = {
    sumber: 'lainnya',
    kategori: 'lainnya',
    deskripsi: `UJI-BEBAN keluhan uji beban otomatis VU ${__VU} (data fiktif, abaikan).`,
  };
  if (SPPG_ID) body.sppg_id = SPPG_ID;
  const res = http.post(`${BASE_URL}/api/v1/keluhan`, JSON.stringify(body), { headers: headers() });
  check(res, { 'tulis status 200/201': (r) => r.status === 200 || r.status === 201 });
}
