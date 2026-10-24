#!/usr/bin/env bash
# Membuat/memperbarui label dari .github/labels.yml memakai gh CLI.
# Pemakaian: scripts/create-labels.sh [--dry-run]
set -euo pipefail

DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE="$ROOT/.github/labels.yml"
[[ -f "$FILE" ]] || { echo "Tidak ada $FILE" >&2; exit 1; }
[[ $DRY_RUN -eq 1 ]] || command -v gh >/dev/null || { echo "gh CLI tidak ditemukan" >&2; exit 1; }

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
