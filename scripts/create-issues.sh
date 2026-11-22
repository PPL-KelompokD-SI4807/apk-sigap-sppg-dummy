#!/usr/bin/env bash
# Membuat 1 issue per PBI dari docs/plan/2026-10-24-matriks-pbi.md (tabel utama).
# Pemakaian: scripts/create-issues.sh <--dry-run | --apply [--yes] | --help>
# Tanpa argumen skrip menolak berjalan. --apply meminta konfirmasi "YA" (atau --yes).
# Prasyarat: label sudah dibuat (scripts/create-labels.sh). Baris PBI "-" (Agen AI) diberi label ai-agent.
set -euo pipefail

MODE=""
YES=0
bantuan() {
  cat >&2 <<'TXT'
Pemakaian: scripts/create-issues.sh <--dry-run | --apply [--yes] | --help>
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
FILE="$ROOT/docs/plan/2026-10-24-matriks-pbi.md"
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

trim() { sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' <<<"$1"; }

[[ $DRY_RUN -eq 1 ]] || konfirmasi "$(awk -F'|' '/^## /{if(seen)exit} /^\| *PBI *\|/{seen=1;next} seen && /^\|---/{next} seen && NF>=9{n++} END{print n+0}' "$FILE") issue akan dibuat"

# Hanya baris tabel utama: kolom pertama angka atau "–"/"-", 8 kolom.
while IFS='|' read -r _ pbi fitur pic sprint fr uc mockup tier _; do
  pbi=$(trim "$pbi"); fitur=$(trim "$fitur"); pic=$(trim "$pic"); sprint=$(trim "$sprint")
  fr=$(trim "$fr"); uc=$(trim "$uc"); mockup=$(trim "$mockup"); tier=$(trim "$tier")

  labels="tier-$tier"
  if [[ "$pbi" =~ ^[0-9]+$ ]]; then
    no=$(printf '%02d' "$pbi")
    title="[PBI-$pbi] $fitur"
    labels="$labels,fitur-$no"
  else
    title="[PBI-AI] $fitur"
    labels="$labels,ai-agent"
  fi
  [[ "$sprint" == *"Sprint 1"* ]] && labels="$labels,sprint-1"
  [[ "$sprint" == *"Sprint 2"* ]] && labels="$labels,sprint-2"

  body="## Informasi
- Penanggung jawab (manusia): $pic
- Sprint rencana (menurut proposal): $sprint
- Tier: $tier

## Kebutuhan, use case, mockup
- Kebutuhan: $fr
- Use case: $uc
- Mockup: $mockup

Acuan: docs/plan/2026-10-24-matriks-pbi.md"

  if [[ $DRY_RUN -eq 1 ]]; then
    echo "[dry-run] issue: $title"
    echo "          label: $labels"
  else
    gh issue create --title "$title" --body "$body" --label "$labels"
  fi
done < <(awk -F'|' '
  /^## /{ if (seen) exit }
  /^\| *PBI *\|/ { seen = 1; next }
  seen && /^\|---/ { next }
  seen && NF >= 9 { print }
' "$FILE")
