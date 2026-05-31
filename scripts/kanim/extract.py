"""Render looping idle animations for worklist entities into public/assets/game/.

Usage:
    uv run --with UnityPy --with Pillow python -m scripts.kanim.extract [--key Hatch] [--verbose]
"""
import re
import sys
from pathlib import Path

import UnityPy
from PIL import Image

from scripts.kanim.anim import AnimData, parse_anim
from scripts.kanim.build import parse_build
from scripts.kanim.mapping import OVERRIDES, load_worklist
from scripts.kanim.render import encode_webp, render_anim

REPO = Path(__file__).resolve().parents[2]
ASSETS = REPO / "game-data/OxygenNotIncluded_Data/sharedassets0.assets"
IDLE_PREFERENCES = ("idle_loop", "idle_default", "idle")
DENY_NAMES = ("pivot", "snapto")
# anim bases that are accessory/variant assets, not a creature/plant's main kanim
PREFIX_SKIP = ("baby_", "egg_", "creature_interacts_", "elegantbed_", "flowervase_", "jet_", "jetsuit_", "balloon_", "sticker_", "dehydrated_", "anim_", "meteor_", "seed_")
SUFFIX_SKIP = ("_build", "_emotes")


def _norm(s: str) -> str:
    return re.sub(r"[^a-z0-9]", "", s.lower())


def _resolve_base(row: dict[str, str], norm_index: dict[str, str], anims: dict[str, bytes]) -> str | None:
    """Map an entity natural key to its kanim base name."""
    key = row["key"]
    if key in OVERRIDES:
        return OVERRIDES[key]
    if f"{row['kanim']}_anim" in anims:  # snake_case default
        return row["kanim"]
    return norm_index.get(_norm(key))  # separator-insensitive match


def _raw(text_asset) -> bytes:
    script = text_asset.m_Script
    return script if isinstance(script, bytes) else script.encode("utf-8", "surrogateescape")


def _pick_idle(data: AnimData):
    for pref in IDLE_PREFERENCES:
        for a in data.anims:
            if a.name == pref and a.frames:
                return a
    for a in data.anims:
        if "idle" in a.name.lower() and a.frames:
            return a
    return next((a for a in data.anims if a.frames), None)


def main() -> None:
    args = sys.argv[1:]
    only_key = args[args.index("--key") + 1] if "--key" in args else None
    verbose = "--verbose" in args

    print("loading", ASSETS.name, "…")
    env = UnityPy.load(str(ASSETS))
    builds: dict[str, bytes] = {}
    anims: dict[str, bytes] = {}
    tex_objs: dict[str, object] = {}
    for obj in env.objects:
        if obj.type.name == "TextAsset":
            d = obj.read()
            n = d.m_Name
            if n.endswith("_anim"):
                anims[n] = _raw(d)
            elif n.endswith("_build"):
                builds[n] = _raw(d)
        elif obj.type.name == "Texture2D":
            d = obj.read()
            if d.m_Name.endswith("_0"):
                tex_objs[d.m_Name] = d
    print(f"  {len(builds)} builds, {len(anims)} anims, {len(tex_objs)} atlases")

    # Index of primary (creature/plant) kanim bases for separator-insensitive matching.
    norm_index: dict[str, str] = {}
    for name in anims:
        base = name[: -len("_anim")]
        if any(base.startswith(p) for p in PREFIX_SKIP) or any(base.endswith(s) for s in SUFFIX_SKIP):
            continue
        norm_index.setdefault(_norm(base), base)

    atlas_cache: dict[str, Image.Image] = {}

    def atlas(name: str):
        if name not in atlas_cache:
            obj = tex_objs.get(name)
            atlas_cache[name] = obj.image.convert("RGBA") if obj is not None else None
        return atlas_cache[name]

    worklist = load_worklist()
    if only_key:
        worklist = [r for r in worklist if r["key"] == only_key]
    ok = miss = 0
    for row in worklist:
        base = _resolve_base(row, norm_index, anims)
        anim_bytes = anims.get(f"{base}_anim") if base else None
        build_names = [n for n in builds if n == f"{base}_build" or n.startswith(f"{base}_build_")] if base else []
        if anim_bytes is None or not build_names:
            print(f"  MISS  {row['category']}/{row['key']} (base={base!r}: anim={anim_bytes is not None}, builds={len(build_names)})")
            miss += 1
            continue

        symbols = {}
        names: dict[int, str] = {}
        for bn in build_names:
            atl = atlas(bn[: -len("_build")] + "_0")
            if atl is None:
                continue
            build = parse_build(builds[bn])
            names.update(build.hashes)
            for sym in build.symbols:
                symbols[sym.hash] = (sym.frames, atl)

        anim = _pick_idle(parse_anim(anim_bytes))
        if anim is None:
            print(f"  MISS  {row['category']}/{row['key']} (no usable anim)")
            miss += 1
            continue

        referenced = {e.symbol_hash for fr in anim.frames for e in fr.elements}
        unresolved = sum(1 for h in referenced if h not in symbols)
        skip = frozenset(h for h, nm in names.items() if any(d in nm.lower() for d in DENY_NAMES))
        if verbose:
            print(f"  {row['key']}: anim='{anim.name}' frames={len(anim.frames)} refs={len(referenced)} unresolved={unresolved} skip={len(skip)}")

        frames = render_anim(symbols, anim, skip)
        out_dir = REPO / "public/assets/game" / row["category"]
        out_dir.mkdir(parents=True, exist_ok=True)
        encode_webp(frames, anim.rate, out_dir / f"{row['key']}.webp")
        flag = "" if unresolved == 0 else f" (!{unresolved} unresolved)"
        print(f"  OK    {row['category']}/{row['key']} <- {base} ({anim.name}, {len(frames)}f){flag}")
        ok += 1

    print(f"\nDone: {ok} rendered, {miss} missing.")


if __name__ == "__main__":
    main()
