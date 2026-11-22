#!/usr/bin/env bash
# Membuat/memperbarui label dari .github/labels.yml memakai gh CLI.
# Pemakaian: scripts/create-labels.sh <--dry-run | --apply [--yes] | --help>
# Tanpa argumen skrip menolak berjalan. --apply meminta konfirmasi "YA" (atau --yes).
set -euo pipefail

MODE=""
YES=0
bantuan() {
  cat >&2 <<'TXT'
Pemakaian: scripts/create-labels.sh <--dry-run | --apply [--yes] | --help>
  --dry-run  hanya mencetak perintah, tidak memanggil gh
  --apply    eksekusi sungguhan ke GitHub (meminta konfirmasi "YA")
  --yes      lewati konfirmasi (untuk non-interaktif; hanya bersama --apply)
  --help     tampilkan bantuan ini
Tanpa argumen, skrip menolak berjalan.
TXT
}
if [[ $# -eq 0 ]]; then
  echo "Galat: argumen eksplisit wajib." >&2; bantuan; exit 2
fi
for a in "$@"; do
  case "$a" in
    --help|-h) if [[ $# -eq 1 ]]; then bantuan; exit 0; fi; echo "Galat: --help tidak boleh digabung." >&2; exit 2 ;;
    --dry-run) MODE=dry ;;
    --apply) MODE=apply ;;
    --yes) YES=1 ;;
    *) echo "Galat: argumen tidak dikenal: $a" >&2; bantuan; exit 2 ;;
  esac
done
if [[ "$MODE" == "" || ( "$MODE" == "dry" && $# -ne 1 ) || ( "$MODE" == "apply" && $# -gt 2 ) || ( $YES -eq 1 && "$MODE" != "apply" ) ]]; then
  echo "Galat: kombinasi argumen tidak valid." >&2; bantuan; exit 2
fi
DRY_RUN=0
[[ "$MODE" == "dry" ]] && DRY_RUN=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE="$ROOT/.github/labels.yml"
[[ -f "$FILE" ]] || { echo "Tidak ada $FILE" >&2; exit 1; }
[[ $DRY_RUN -eq 1 ]] || command -v gh >/dev/null || { echo "gh CLI tidak ditemukan" >&2; exit 1; }

konfirmasi() { # $1 = ringkasan
  command -v gh >/dev/null || { echo "gh CLI tidak ditemukan" >&2; exit 1; }
  repo=$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || echo "(tidak diketahui)")
  echo "Ringkasan: $1" >&2
  echo "Repo tujuan: $repo" >&2
  if [[ $YES -eq 1 ]]; then return 0; fi
  if [[ ! -t 0 ]]; then echo "Galat: stdin bukan TTY dan tanpa --yes; dibatalkan." >&2; exit 2; fi
  read -r -p 'Ketik YA untuk melanjutkan: ' jawab
  [[ "$jawab" == "YA" ]] || { echo "Dibatalkan." >&2; exit 2; }
}

[[ $DRY_RUN -eq 1 ]] || konfirmasi "$(grep -c '^- name:' "$FILE") label akan dibuat/diperbarui"

# Parser sederhana: tiap entri "- name:", "description:", "color:" (nilai dalam tanda kutip ganda).
awk '
  function val(s) { sub(/^[^:]*:[ ]*/, "", s); gsub(/^"|"$/, "", s); return s }
  /^- name:/        { if (n != "") print n "\t" d "\t" c; n = val($0); d = ""; c = "" }
  /^  description:/ { d = val($0) }
  /^  color:/       { c = val($0) }
  END               { if (n != "") print n "\t" d "\t" c }
' "$FILE" | while IFS=$'\t' read -r name desc color; do
  if [[ $DRY_RUN -eq 1 ]]; then
    echo "[dry-run] gh label create \"$name\" --description \"$desc\" --color \"$color\" --force"
  else
    gh label create "$name" --description "$desc" --color "$color" --force
  fi
done
