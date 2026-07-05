#!/usr/bin/env python3
"""Debug UV values for specific buildings."""
import os
import struct
from pathlib import Path
from PIL import Image
import UnityPy

REPO_ROOT = Path(__file__).parent.parent
ASSETS_FILE = REPO_ROOT / "game-data/OxygenNotIncluded_Data/sharedassets0.assets"

TARGET_BUILDINGS = {"Ladder", "LiquidValve", "ManualGenerator", "Wire"}

def sdbm_hash(s: str) -> int:
    num = 0
    for c in s.lower():
        num = (ord(c) + (num << 6) + (num << 16) - num) & 0xFFFFFFFF
    return struct.unpack("<i", struct.pack("<I", num))[0]

UI_HASH = sdbm_hash("ui")

def parse_bild_debug(data: bytes, name: str):
    if len(data) < 8 or data[:4] != b"BILD":
        return
    pos = 4
    ver = struct.unpack_from("<i", data, pos)[0]; pos += 4
    sym_count = struct.unpack_from("<i", data, pos)[0]; pos += 4
    _frame_total = struct.unpack_from("<i", data, pos)[0]; pos += 4
    name_len = struct.unpack_from("<i", data, pos)[0]; pos += 4
    bild_name = data[pos:pos+name_len].decode("utf-8", errors="replace"); pos += name_len

    print(f"\n  BILD: {bild_name}  ver={ver}  sym_count={sym_count}")

    for _ in range(sym_count):
        sym_hash = struct.unpack_from("<i", data, pos)[0]; pos += 4
        if ver > 9:
            pos += 4  # path hash
        pos += 4  # colourChannel
        pos += 4  # flags
        sym_numframes = struct.unpack_from("<i", data, pos)[0]; pos += 4

        for j in range(sym_numframes):
            src_frame = struct.unpack_from("<i", data, pos)[0]; pos += 4
            _duration = struct.unpack_from("<i", data, pos)[0]; pos += 4
            img_idx = struct.unpack_from("<i", data, pos)[0]; pos += 4
            cx = struct.unpack_from("<f", data, pos)[0]; pos += 4
            cy = struct.unpack_from("<f", data, pos)[0]; pos += 4
            bw = struct.unpack_from("<f", data, pos)[0]; pos += 4
            bh = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_x1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_x2 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y2 = struct.unpack_from("<f", data, pos)[0]; pos += 4

            if sym_hash == UI_HASH:
                print(f"  -> 'ui' frame {j}: bbox=({cx:.1f},{cy:.1f},{bw:.1f},{bh:.1f})  uv=({uv_x1:.4f},{uv_y1:.4f},{uv_x2:.4f},{uv_y2:.4f})  img_idx={img_idx}")
                print(f"     uv range x: {abs(uv_x2-uv_x1):.4f}  y: {abs(uv_y2-uv_y1):.4f}")


def load_kanim_map() -> dict[str, str]:
    map_file = Path(__file__).parent / "building_kanim_map.txt"
    result = {}
    with open(map_file) as f:
        for line in f:
            line = line.strip()
            if "|" in line:
                bid, kanim = line.split("|", 1)
                if bid in TARGET_BUILDINGS:
                    result[bid] = kanim.removesuffix("_kanim")
    return result

def main():
    kanim_map = load_kanim_map()
    print("Target buildings:", kanim_map)

    os.chdir(ASSETS_FILE.parent)

    STREAMING = ASSETS_FILE.parent / "StreamingAssets"
    bundles = [
        str(ASSETS_FILE),
        str(STREAMING / "expansion1_bundle"),
        str(STREAMING / "dlc2_bundle"),
        str(STREAMING / "dlc3_bundle"),
        str(STREAMING / "dlc4_bundle"),
    ]

    texture_map = {}
    bild_map = {}

    for bundle_path in bundles:
        if not Path(bundle_path).exists():
            continue
        env = UnityPy.load(bundle_path)
        for obj in env.objects:
            if obj.type.name == "Texture2D":
                d = obj.read_typetree()
                name = d.get("m_Name", "")
                if name:
                    try:
                        texture_map[name.lower()] = obj.read().image
                    except Exception:
                        pass
            elif obj.type.name == "TextAsset":
                d = obj.read_typetree()
                name = d.get("m_Name", "")
                if name.endswith("_build"):
                    raw = d.get("m_Script", "")
                    bild_map[name[:-6].lower()] = raw.encode("utf-8", errors="surrogateescape")

    for bid, kanim_base in kanim_map.items():
        print(f"\n=== {bid} (kanim: {kanim_base}) ===")
        key = kanim_base.lower()
        if key not in bild_map:
            print("  MISS BILD")
            continue

        parse_bild_debug(bild_map[key], kanim_base)

        tex_key = f"{key}_0"
        if tex_key in texture_map:
            tex = texture_map[tex_key]
            print(f"  Texture size: {tex.size}")
        else:
            print(f"  Texture: NOT FOUND ({tex_key})")

if __name__ == "__main__":
    main()
