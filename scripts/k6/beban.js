// NFR-01 uji beban SiGAP SPPG. HANYA untuk staging/lokal, jangan produksi. Lihat docs/ops/uji-beban.md.
// Env: BASE_URL (wajib, mis. https://staging.example.test), K6_TOKEN (wajib, Bearer; jangan ditulis di berkas),
//      K6_TOKENS (opsional, daftar token dipisah koma, dipakai bergilir per VU agar tidak kena throttle 60/menit per pengguna),
//      K6_SPPG_ID (wajib bila token milik Admin, karena POST /keluhan meminta sppg_id).
import http from 'k6/http';
import { check, sleep, fail } from 'k6';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const TOKENS = (__ENV.K6_TOKENS || __ENV.K6_TOKEN || '').split(',').map((t) => t.trim()).filter(Boolean);
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
  if (TOKENS.length === 0) fail('K6_TOKEN wajib diisi (token Bearer akun uji).');
}

function headers() {
  const token = TOKENS[(__VU - 1) % TOKENS.length];
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
}

export function baca() {
  const params = { headers: headers() };
  const res = http.batch([
    ['GET', `${BASE_URL}/api/v1/jadwal`, null, params],
    ['GET', `${BASE_URL}/api/v1/jadwal/distribusi`, null, params],
    ['GET', `${BASE_URL}/api/v1/bahan-baku`, null, params],
    ['GET', `${BASE_URL}/api/v1/dashboard`, null, params],
  ]);
  res.forEach((r, i) => {
    check(r, { [`baca[${i}] status 200`]: (x) => x.status === 200 });
  });
  sleep(1);
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
