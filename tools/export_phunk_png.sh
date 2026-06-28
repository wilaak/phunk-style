#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="${ROOT_DIR}/assets/phunk"
OUT_DIR="${ROOT_DIR}/assets/phunk-png"

# Optional knobs:
#   SIZES="64 512 1200" ./tools/export_phunk_png.sh
#   SIZES="64,512,1200" ./tools/export_phunk_png.sh
#   WIDTH=2048 ./tools/export_phunk_png.sh
#   SCALE=4 ./tools/export_phunk_png.sh  (legacy single-output mode)
#   CLEAN=1 ./tools/export_phunk_png.sh  (remove old outputs first)
SIZES="${SIZES:-64 512 1200}"
WIDTH="${WIDTH:-}"
SCALE="${SCALE:-}"
CLEAN="${CLEAN:-0}"

if ! command -v rsvg-convert >/dev/null 2>&1; then
  echo "rsvg-convert not found. Install librsvg2-bin." >&2
  exit 1
fi

if [[ "${CLEAN}" == "1" ]]; then
  rm -rf "${OUT_DIR}"
fi

mkdir -p "${OUT_DIR}"

sizes_raw="${SIZES//,/ }"
read -r -a size_list <<< "${sizes_raw}"

generated=0

while IFS= read -r -d '' svg; do
  rel="${svg#${SRC_DIR}/}"
  png_rel="${rel%.svg}.png"

  if [[ -n "${WIDTH}" ]]; then
    out_path="${OUT_DIR}/${WIDTH}w/${png_rel}"
    out_dir="$(dirname "${out_path}")"
    mkdir -p "${out_dir}"
    rsvg-convert -w "${WIDTH}" "${svg}" -o "${out_path}"
    generated=$((generated + 1))
  elif [[ ${#size_list[@]} -gt 0 ]]; then
    for size in "${size_list[@]}"; do
      out_path="${OUT_DIR}/${size}w/${png_rel}"
      out_dir="$(dirname "${out_path}")"
      mkdir -p "${out_dir}"
      rsvg-convert -w "${size}" "${svg}" -o "${out_path}"
      generated=$((generated + 1))
    done
  else
    out_path="${OUT_DIR}/${png_rel}"
    out_dir="$(dirname "${out_path}")"
    mkdir -p "${out_dir}"
    rsvg-convert -z "${SCALE}" "${svg}" -o "${out_path}"
    generated=$((generated + 1))
  fi

done < <(find "${SRC_DIR}" -type f -name '*.svg' -print0 | sort -z)

echo "Export complete: ${OUT_DIR} (${generated} PNG files)"
