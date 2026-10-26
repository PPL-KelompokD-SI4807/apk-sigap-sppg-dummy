#!/usr/bin/env bash
# Membuat 1 issue per PBI dari docs/plan/2026-10-24-matriks-pbi.md (tabel utama).
# Pemakaian: scripts/create-issues.sh [--dry-run]
# Prasyarat: label sudah dibuat (scripts/create-labels.sh). Baris PBI "-" (Agen AI) diberi label ai-agent.
set -euo pipefail

DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE="$ROOT/docs/plan/2026-10-24-matriks-pbi.md"
[[ -f "$FILE" ]] || { echo "Tidak ada $FILE" >&2; exit 1; }
[[ $DRY_RUN -eq 1 ]] || command -v gh >/dev/null || { echo "gh CLI tidak ditemukan" >&2; exit 1; }

trim() { sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' <<<"$1"; }

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
