#!/usr/bin/env bash
# Design audit: full-page screenshots of key routes, per viewport x theme.
# Usage: scripts/design-audit/capture.sh [base_url]
set -euo pipefail

BASE_URL="${1:-http://localhost:8003}"
OUT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/screenshots"
mkdir -p "$OUT_DIR"

ROUTES=(
    "home:/"
    "buildings:/buildings"
    "critters-index:/critters"
    "critters-show:/critters/hatch"
    "ranch:/ranch"
)

VIEWPORTS=(
    "desktop:1440,900"
    "mobile:390,844"
)

THEMES=("light" "dark")

for route_entry in "${ROUTES[@]}"; do
    slug="${route_entry%%:*}"
    path="${route_entry#*:}"
    for vp_entry in "${VIEWPORTS[@]}"; do
        vp_slug="${vp_entry%%:*}"
        vp_size="${vp_entry#*:}"
        for theme in "${THEMES[@]}"; do
            out="${OUT_DIR}/${slug}--${vp_slug}--${theme}.png"
            echo "==> ${out}"
            bunx playwright screenshot \
                --viewport-size="${vp_size}" \
                --color-scheme="${theme}" \
                --full-page \
                --wait-for-timeout=600 \
                "${BASE_URL}${path}" \
                "${out}"
        done
    done
done

echo "Done. ${#ROUTES[@]} routes x ${#VIEWPORTS[@]} viewports x ${#THEMES[@]} themes."
