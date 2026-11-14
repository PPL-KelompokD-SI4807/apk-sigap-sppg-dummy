<?php

/*
|--------------------------------------------------------------------------
| Proxy tepercaya (R04-4, #81)
|--------------------------------------------------------------------------
|
| Dibaca middleware TrustProxies bawaan Laravel (config('trustedproxy.proxies')). Header X-Forwarded-*
| HANYA dipercaya bila REMOTE_ADDR termasuk daftar ini; selain itu diabaikan dan IP klien = REMOTE_ADDR.
|
| Produksi: nginx adalah tepi langsung dan meneruskan $remote_addr sebagai REMOTE_ADDR lewat FastCGI, serta
| mengosongkan X-Forwarded-* dari klien (docker/nginx/snippets/sigap-php.conf). Biarkan kosong. Bila kelak
| ada CDN/ALB di depan, pulihkan IP asli di nginx (set_real_ip_from + real_ip_header) dan TETAP kosongkan ini.
| Isi hanya bila PHP menerima permintaan langsung dari proxy HTTP (daftar IP/CIDR dipisah koma).
| '*' / '**' sengaja DITOLAK (diperlakukan kosong): dengan itu setiap klien dapat memalsukan IP lewat
| X-Forwarded-For dan melewati throttle per IP.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' || in_array($proxies, ['*', '**'], true) ? null : $proxies,
];
