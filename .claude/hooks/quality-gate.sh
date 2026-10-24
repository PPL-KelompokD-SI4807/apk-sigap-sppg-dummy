#!/usr/bin/env bash
# Quality gate: dijalankan otomatis saat sebuah tugas ditandai selesai.
# Exit 2 = tolak penyelesaian tugas dan kirim umpan balik ke agen.
cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0

fail=0
msg=""

if [ -f artisan ] && [ -d vendor ]; then
  if ! vendor/bin/pint --test >/tmp/sigap-pint.log 2>&1; then
    fail=1; msg+="Format kode belum rapi: jalankan vendor/bin/pint. "
  fi
  if ! php artisan test --stop-on-failure >/tmp/sigap-test.log 2>&1; then
    fail=1; msg+="Tes gagal: $(tail -n 15 /tmp/sigap-test.log | tr '\n' ' ') "
  fi
fi

if [ -f package.json ] && [ -d node_modules ]; then
  if ! npm run build >/tmp/sigap-build.log 2>&1; then
    fail=1; msg+="Build Mini App gagal: $(tail -n 10 /tmp/sigap-build.log | tr '\n' ' ') "
  fi
fi

if [ "$fail" -ne 0 ]; then
  echo "Tugas belum memenuhi Definition of Done. $msg" >&2
  exit 2
fi
exit 0
