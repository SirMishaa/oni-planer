#!/usr/bin/env python3
"""
Extract building UI icons from ONI Unity asset bundles.
Reads each building's kanim BILD file to find the "ui" symbol UV coordinates,
then crops that region from the texture atlas.
Outputs webp files to public/assets/game/buildings/{BuildingId}.webp
"""
import os
import sys
import struct
from pathlib import Path
from PIL import Image
import UnityPy

REPO_ROOT = Path(__file__).parent.parent
ASSETS_FILE = REPO_ROOT / "game-data/OxygenNotIncluded_Data/sharedassets0.assets"
OUTPUT_DIR = REPO_ROOT / "public/assets/game/buildings"
ICON_SIZE = 128
WEBP_QUALITY = 85

# SDBM hash (lowercase) — matches KAnimHashedString in the game
def sdbm_hash(s: str) -> int:
    num = 0
    for c in s.lower():
        num = (ord(c) + (num << 6) + (num << 16) - num) & 0xFFFFFFFF
    return struct.unpack("<i", struct.pack("<I", num))[0]


UI_HASH = sdbm_hash("ui")


def parse_bild_ui_uv(data: bytes) -> tuple[float, float, float, float] | None:
    """Parse a BILD kanim build file and return UV coords (x1,y1,x2,y2) for the 'ui' symbol frame 0.
    Returns None if the 'ui' symbol is not found or the file can't be parsed."""
    if len(data) < 8 or data[:4] != b"BILD":
        return None

    pos = 4
    ver = struct.unpack_from("<i", data, pos)[0]; pos += 4
    if ver not in (9, 10):
        return None

    sym_count = struct.unpack_from("<i", data, pos)[0]; pos += 4
    _frame_total = struct.unpack_from("<i", data, pos)[0]; pos += 4

    name_len = struct.unpack_from("<i", data, pos)[0]; pos += 4
    pos += name_len  # skip build name

    for _ in range(sym_count):
        if pos + 20 > len(data):
            break

        sym_hash = struct.unpack_from("<i", data, pos)[0]; pos += 4
        if ver > 9:
            pos += 4  # path hash (version 10+)
        pos += 4  # colourChannel
        pos += 4  # flags
        sym_numframes = struct.unpack_from("<i", data, pos)[0]; pos += 4

        for j in range(sym_numframes):
            if pos + 44 > len(data):
                break
            src_frame = struct.unpack_from("<i", data, pos)[0]; pos += 4  # noqa: F841
            _duration = struct.unpack_from("<i", data, pos)[0]; pos += 4
            _img_idx = struct.unpack_from("<i", data, pos)[0]; pos += 4
            # bbox: cx, cy, w, h (4 floats)
            pos += 16
            # UV: x1, y1, x2, y2 (4 floats, raw from file)
            uv_x1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y1 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_x2 = struct.unpack_from("<f", data, pos)[0]; pos += 4
            uv_y2 = struct.unpack_from("<f", data, pos)[0]; pos += 4

            if sym_hash == UI_HASH and j == 0:
                return (uv_x1, uv_y1, uv_x2, uv_y2)

    return None


def uv_to_pil_crop(uv: tuple[float, float, float, float], w: int, h: int) -> tuple[int, int, int, int]:
    """Convert raw UV coords (ONI file format, bottom-left origin) to PIL crop box."""
    uv_x1, uv_y1, uv_x2, uv_y2 = uv
    left = int(min(uv_x1, uv_x2) * w)
    right = int(max(uv_x1, uv_x2) * w)
    # UV y=0 is bottom in Unity, y=0 is top in PIL
    top = int((1.0 - max(uv_y1, uv_y2)) * h)
    bottom = int((1.0 - min(uv_y1, uv_y2)) * h)
    return (left, top, right, bottom)


def center_crop(img: Image.Image, size: int) -> Image.Image:
    w, h = img.size
    if w == 0 or h == 0:
        return img
    # Pad to square then resize
    sq = max(w, h)
    padded = Image.new("RGBA", (sq, sq), (0, 0, 0, 0))
    padded.paste(img, ((sq - w) // 2, (sq - h) // 2))
    return padded.resize((size, size), Image.LANCZOS)


# building ID → kanim name (strip _kanim suffix when looking up _build TextAsset)
BUILDING_KANIM: dict[str, str] = {}


def load_building_kanim_map() -> None:
    """Load mapping from the pre-extracted DLL data."""
    map_file = Path(__file__).parent / "building_kanim_map.txt"
    if map_file.exists():
        with open(map_file) as f:
            for line in f:
                line = line.strip()
                if "|" in line:
                    building_id, kanim = line.split("|", 1)
                    BUILDING_KANIM[building_id] = kanim.removesuffix("_kanim")


def main() -> None:
    if not ASSETS_FILE.exists():
        print(f"ERROR: asset bundle not found at {ASSETS_FILE}", file=sys.stderr)
        sys.exit(1)

    load_building_kanim_map()
    if not BUILDING_KANIM:
        print("ERROR: building_kanim_map.txt not found or empty", file=sys.stderr)
        print("Run: python3 scripts/extract-building-kanim-map.py", file=sys.stderr)
        sys.exit(1)

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    os.chdir(ASSETS_FILE.parent)

    STREAMING = ASSETS_FILE.parent / "StreamingAssets"
    DLC_BUNDLES = [
        ASSETS_FILE.name,
        str(STREAMING / "expansion1_bundle"),
        str(STREAMING / "dlc2_bundle"),
        str(STREAMING / "dlc3_bundle"),
        str(STREAMING / "dlc4_bundle"),
    ]

    texture_map: dict[str, Image.Image] = {}   # lower-name → image
    bild_map: dict[str, bytes] = {}             # lower-name → bytes

    for bundle_path in DLC_BUNDLES:
        if not Path(bundle_path).exists():
            continue
        print(f"Loading {Path(bundle_path).name} …")
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

    print(f"Textures: {len(texture_map)}, BILD files: {len(bild_map)}\n")

    extracted = 0
    missing_bild = 0
    missing_uv = 0
    missing_tex = 0

    for building_id, kanim_base in sorted(BUILDING_KANIM.items()):
        out_path = OUTPUT_DIR / f"{building_id}.webp"

        # Look up kanim build data (case-insensitive)
        kanim_key = kanim_base.lower()
        if kanim_key not in bild_map:
            missing_bild += 1
            print(f"  MISS_BILD  {building_id} → {kanim_base}_build (not in bundle)")
            continue

        uv = parse_bild_ui_uv(bild_map[kanim_key])
        if uv is None:
            missing_uv += 1
            print(f"  MISS_UV    {building_id} → {kanim_base} (no 'ui' symbol)")
            continue

        # Texture atlas: {kanim_base}_0 (case-insensitive)
        tex_key = f"{kanim_key}_0"
        if tex_key not in texture_map:
            missing_tex += 1
            print(f"  MISS_TEX   {building_id} → {kanim_base}_0 (not in bundle)")
            continue

        tex = texture_map[tex_key]
        w, h = tex.size
        crop_box = uv_to_pil_crop(uv, w, h)

        left, top, right, bottom = crop_box
        if right <= left or bottom <= top:
            missing_uv += 1
            print(f"  BAD_UV     {building_id} → {kanim_base} crop={crop_box}")
            continue

        cropped = tex.crop(crop_box)
        icon = center_crop(cropped, ICON_SIZE)
        icon.save(out_path, "webp", quality=WEBP_QUALITY)
        extracted += 1
        print(f"  OK         {building_id}")

    print(f"\nDone: {extracted} extracted, {missing_bild} no-bild, {missing_uv} no-uv/bad-uv, {missing_tex} no-tex")


if __name__ == "__main__":
    main()
