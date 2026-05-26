#!/usr/bin/env python3
"""Extract English building display names from the .po strings file and update the DB."""
import re
import json
import subprocess
from pathlib import Path

PO_FILE = Path("game-data/OxygenNotIncluded_Data/StreamingAssets/strings/strings_preinstalled_ko_klei.po")

content = PO_FILE.read_text(encoding="utf-8")

pattern = re.compile(
    r'msgctxt "STRINGS\.BUILDINGS\.PREFABS\.([A-Z0-9_]+)\.NAME"\s*'
    r'msgid "((?:[^"\\]|\\.)*)"'
)

result: dict[str, str] = {}
for m in pattern.finditer(content):
    building_id = m.group(1)
    raw = m.group(2).replace('\\"', '"')
    link_m = re.search(r">([^<]+)</link>", raw)
    name = link_m.group(1) if link_m else raw
    result[building_id] = name

print(f"Parsed {len(result)} building names")

# Write to a JSON file for the artisan command to consume
output = Path("scripts/building_names.json")
output.write_text(json.dumps(result, ensure_ascii=False, indent=2))
print(f"Written to {output}")
