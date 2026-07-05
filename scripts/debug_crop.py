#!/usr/bin/env python3
"""
Generate test crops for Ladder and LiquidValve both with and without Y-flip
to determine which UV interpretation is correct.
"""
import os
import struct
from pathlib import Path
from PIL import Image
import UnityPy

REPO_ROOT = Path(__file__).parent.parent
ASSETS_FILE = REPO_ROOT / "game-data/OxygenNotIncluded_Data/sharedassets0.assets"
OUT = REPO_ROOT / "scripts" / "debug_crops"
OUT.mkdir(exist_ok=True)

TARGET_BUILDINGS = {"Ladder": "ladder", "LiquidValve": "valveliquid", "ManualGenerator": "generatormanual"}

def sdbm_hash(s: str) -> int:
    num = 0
    for c in s.lower():
        num = (ord(c) + (num << 6) + (num << 16) - num) & 0xFFFFFFFF
    return struct.unpack("<i", struct.pack("<I", num))[0]

UI_HASH = sdbm_hash("ui")

def parse_bild_ui_uv(data: bytes):
    if len(data) < 8 or data[:4] != b"BILD":
        return None
    pos = 4
    ver = struct.unpack_from("<i", data, pos)[0]; pos += 4
    if ver not in (9, 10):
        return None
    sym_count = struct.unpack_from("<i", data, pos)[0]; pos += 4
    pos += 4  # frame_total
    name_len = struct.unpack_from("<i", data, pos)[0]; pos += 4
    pos += name_len
    for _ in range(sym_count):
        sym_hash = struct.unpack_from("<i", data, pos)[0]; pos += 4
        if ver > 9:
            pos += 4
        pos += 8  # colourChannel + flags
        sym_numframes = struct.unpack_from("<i", data, pos)[0]; pos += 4
        for j in range(sym_numframes):
            pos += 12  # sourceFrameNum + duration + buildImageIdx
            pos += 16  # bbox
            uv_x1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_x2 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y2 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            if sym_hash == UI_HASH and j == 0:
                return (uv_x1, uv_y1, uv_x2, uv_y2)
    return None

def crop_and_save(tex: Image.Image, uv, name: str, flip_y: bool):
    uv_x1, uv_y1, uv_x2, uv_y2 = uv
    w, h = tex.size
    left = int(min(uv_x1, uv_x2) * w)
    right = int(max(uv_x1, uv_x2) * w)
    if flip_y:
        top = int((1.0 - max(uv_y1, uv_y2)) * h)
        bottom = int((1.0 - min(uv_y1, uv_y2)) * h)
        suffix = "flipped"
    else:
        top = int(min(uv_y1, uv_y2) * h)
        bottom = int(max(uv_y1, uv_y2) * h)
        suffix = "normal"

    print(f"  {suffix}: crop ({left},{top},{right},{bottom}) from {w}x{h}")
    if right <= left or bottom <= top:
        print(f"  BAD CROP")
        return

    cropped = tex.crop((left, top, right, bottom))
    # Save raw crop
    cropped.save(OUT / f"{name}_{suffix}_raw.png", "png")
    # Save also the full texture with a red border around the crop
    tex_copy = tex.convert("RGBA")
    # Highlight the crop region
    from PIL import ImageDraw
    draw = ImageDraw.Draw(tex_copy)
    draw.rectangle([left, top, right-1, bottom-1], outline=(255, 0, 0, 255), width=2)
    tex_copy.save(OUT / f"{name}_{suffix}_atlas.png", "png")

def main():
    os.chdir(ASSETS_FILE.parent)
    STREAMING = ASSETS_FILE.parent / "StreamingAssets"
    bundles = [str(ASSETS_FILE), str(STREAMING / "expansion1_bundle")]

    texture_map = {}
    bild_map = {}

    for bundle_path in bundles:
        if not Path(bundle_path).exists():
            continue
        env = UnityPy.load(bundle_path)
        for obj in env.objects:
            if obj.type.name == "Texture2D":
                d = obj.read_typetree()
                n = d.get("m_Name", "")
                if n:
                    try:
                        texture_map[n.lower()] = obj.read().image
                    except Exception:
                        pass
            elif obj.type.name == "TextAsset":
                d = obj.read_typetree()
                n = d.get("m_Name", "")
                if n.endswith("_build"):
                    raw = d.get("m_Script", "")
                    bild_map[n[:-6].lower()] = raw.encode("utf-8", errors="surrogateescape")

    for bid, kanim_base in TARGET_BUILDINGS.items():
        key = kanim_base.lower()
        if key not in bild_map:
            print(f"{bid}: no BILD"); continue
        uv = parse_bild_ui_uv(bild_map[key])
        if uv is None:
            print(f"{bid}: no UV"); continue
        tex_key = f"{key}_0"
        if tex_key not in texture_map:
            print(f"{bid}: no texture {tex_key}"); continue
        tex = texture_map[tex_key]
        print(f"\n{bid} ({tex.size}) uv={tuple(f'{v:.4f}' for v in uv)}")
        crop_and_save(tex, uv, bid, flip_y=True)   # current approach
        crop_and_save(tex, uv, bid, flip_y=False)  # alternative

    print(f"\nSaved to {OUT}/")

if __name__ == "__main__":
    main()
