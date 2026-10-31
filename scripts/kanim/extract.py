"""Render looping idle animations for worklist entities into public/assets/game/.

Usage:
    uv run --with UnityPy --with Pillow python -m scripts.kanim.extract [--key Hatch] [--missing] [--verbose]
"""
import re
import sys
from pathlib import Path

import UnityPy
from PIL import Image

from scripts.kanim.anim import AnimData, parse_anim
from scripts.kanim.build import parse_build
from scripts.kanim.mapping import EXTRA_BUILD_BASES, MORPH_SYMBOL_PREFIX, MORPH_UI_ANIM, OVERRIDES, load_worklist, morph_symbol_prefix
from scripts.kanim.render import encode_webp, render_anim

REPO = Path(__file__).resolve().parents[2]
GAME_DATA = REPO / "game-data/OxygenNotIncluded_Data"
ASSETS = GAME_DATA / "sharedassets0.assets"
# Base game first so its kanims win normalized-name collisions; DLC critters
# (Spaced Out!, Frosty, Bionic, Prehistoric, Aquatic) live in these bundles.
SOURCES = (
    ASSETS,
    GAME_DATA / "StreamingAssets/expansion1_bundle",
    GAME_DATA / "StreamingAssets/dlc2_bundle",
    GAME_DATA / "StreamingAssets/dlc3_bundle",
    GAME_DATA / "StreamingAssets/dlc4_bundle",
    GAME_DATA / "StreamingAssets/dlc5_bundle",
)
IDLE_PREFERENCES = ("idle_loop", "idle_default", "idle")
# Plants: the mature, harvest-ready loop first (idle_empty shows a bare stalk).
PLANT_IDLE_PREFERENCES = ("idle_full", *IDLE_PREFERENCES)
# Categories rendered as a static ui portrait plus an animated <key>_idle.webp.
PORTRAIT_CATEGORIES = ("critters", "plants")
DENY_NAMES = ("pivot", "snapto")
# anim bases that are accessory/variant assets, not a creature/plant's main kanim
PREFIX_SKIP = ("baby_", "egg_", "creature_interacts_", "elegantbed_", "flowervase_", "jet_", "jetsuit_", "balloon_", "sticker_", "dehydrated_", "anim_", "meteor_", "seed_")
SUFFIX_SKIP = ("_build", "_emotes")


def _norm(s: str) -> str:
    return re.sub(r"[^a-z0-9]", "", s.lower())


def _resolve_base(row: dict[str, str], norm_index: dict[str, str], anims: dict[str, bytes]) -> str | None:
    """Map an entity natural key to its kanim base name."""
    key = row["key"]
    if row.get("exact_kanim"):
        return row["exact_kanim"]
    if key in OVERRIDES:
        return OVERRIDES[key]
    if f"{row['kanim']}_anim" in anims:  # snake_case default
        return row["kanim"]
    return norm_index.get(_norm(key))  # separator-insensitive match


def _is_rendered(row: dict[str, str]) -> bool:
    out_dir = REPO / "public/assets/game" / row["category"]
    files = [out_dir / f"{row['key']}.webp"]
    if row["category"] in PORTRAIT_CATEGORIES:
        files.append(out_dir / f"{row['key']}_idle.webp")
    return all(f.exists() and f.stat().st_size > 0 for f in files)


def _raw(text_asset) -> bytes:
    script = text_asset.m_Script
    return script if isinstance(script, bytes) else script.encode("utf-8", "surrogateescape")


def _pick_idle(data: AnimData, preferences: tuple[str, ...] = IDLE_PREFERENCES):
    for pref in preferences:
        for a in data.anims:
            if a.name == pref and a.frames:
                return a
    for a in data.anims:
        if "idle" in a.name.lower() and a.frames:
            return a
    return next((a for a in data.anims if a.frames), None)


def _apply_symbol_prefix(symbols: dict, names: dict[int, str], prefix: str) -> dict:
    """Substitute every symbol that has a '<prefix>_<name>' sibling in the build
    (the game's SymbolOverrideController does the same for morphs)."""
    by_name = {nm: h for h, nm in names.items()}
    out = dict(symbols)
    for nm, base_hash in by_name.items():
        override_hash = by_name.get(f"{prefix}_{nm}")
        if override_hash is not None and override_hash in symbols:
            out[base_hash] = symbols[override_hash]
    return out


def _pick_critter_anim(data: AnimData, key: str):
    """Front-facing portrait: the morph's own ui anim, else the base ui, else idle."""
    preferred = MORPH_UI_ANIM.get(key)
    for name in (preferred, "ui"):
        if name is None:
            continue
        for a in data.anims:
            # Some ui anims carry frames but no element (DewPalm): fall back to the idle.
            if a.name == name and any(fr.elements for fr in a.frames):
                return a
    return _pick_idle(data)


def main() -> None:
    args = sys.argv[1:]
    only_key = args[args.index("--key") + 1] if "--key" in args else None
    verbose = "--verbose" in args
    # --missing: render only entities whose sprite (and, for critters, idle loop)
    # does not exist yet. Rendering everything takes ~40 min; never redo it casually.
    only_missing = "--missing" in args

    builds: dict[str, bytes] = {}
    anims: dict[str, bytes] = {}
    tex_objs: dict[str, object] = {}
    for source in SOURCES:
        print("loading", source.name, "…")
        env = UnityPy.load(str(source))
        # peek_name() reads only the name: decoding every Texture2D up front held
        # their pixel data for all bundles at once (15+ GB before any render).
        for obj in env.objects:
            if obj.type.name == "TextAsset":
                n = obj.peek_name()
                if n.endswith("_anim") and n not in anims:
                    anims[n] = _raw(obj.read())
                elif n.endswith("_build") and n not in builds:
                    builds[n] = _raw(obj.read())
            elif obj.type.name == "Texture2D":
                n = obj.peek_name()
                if re.search(r"_\d+$", n):  # atlas pages: {build}_0, {build}_1, ...
                    tex_objs.setdefault(n, obj)
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
            atlas_cache[name] = obj.read().image.convert("RGBA") if obj is not None else None
        return atlas_cache[name]

    worklist = load_worklist()
    if only_key:
        worklist = [r for r in worklist if r["key"] == only_key]
    if only_missing:
        worklist = [r for r in worklist if not _is_rendered(r)]
        print(f"  {len(worklist)} entities missing a sprite")
    ok = miss = 0
    for row in worklist:
        # Decoded RGBA atlases are ~16-64 MB each; keeping every one ever used grew
        # the process to ~30 GB and OOM-killed the whole WSL VM. Atlases are
        # rarely shared across entities, so drop them after each one.
        atlas_cache.clear()
        base = _resolve_base(row, norm_index, anims)
        anim_bytes = anims.get(f"{base}_anim") if base else None
        build_names: list[str] = []
        if base:
            for b in [*EXTRA_BUILD_BASES.get(row["key"], []), base]:
                # Exact "{b}_build" last: it is the kanim's own build and must win
                # symbol collisions with sibling "{b}_build_*" groups (e.g. the
                # snail_build_build shell deco overriding the snail ui portrait).
                build_names += sorted(
                    (n for n in builds if n == f"{b}_build" or n.startswith(f"{b}_build_")),
                    key=lambda n, exact=f"{b}_build": n == exact,
                )
        if anim_bytes is None or not build_names:
            print(f"  MISS  {row['category']}/{row['key']} (base={base!r}: anim={anim_bytes is not None}, builds={len(build_names)})")
            miss += 1
            continue

        symbols = {}
        names: dict[int, str] = {}
        for bn in build_names:
            page_base = bn[: -len("_build")]
            if f"{page_base}_0" not in tex_objs:
                continue
            build = parse_build(builds[bn])
            names.update(build.hashes)
            pages = lambda index, b=page_base: atlas(f"{b}_{index}")
            for sym in build.symbols:
                symbols[sym.hash] = (sym.frames, pages)

        data = parse_anim(anim_bytes)
        anim = _pick_critter_anim(data, row["key"]) if row["category"] in PORTRAIT_CATEGORIES else _pick_idle(data)
        if anim is None:
            print(f"  MISS  {row['category']}/{row['key']} (no usable anim)")
            miss += 1
            continue

        prefix = MORPH_SYMBOL_PREFIX.get(row["key"])
        if prefix:
            symbols = _apply_symbol_prefix(symbols, names, prefix)

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

        # The ui portrait is a single static frame; also render the animated
        # side-view idle as <key>_idle.webp (shown on the critter grid). Morph
        # colors come from the game's symbol-prefix override (trp_, glp_, ...).
        # Always written, even when the portrait already fell back to the idle
        # (e.g. ParrotFish has no ui anim), so every critter has one.
        if row["category"] in PORTRAIT_CATEGORIES:
            prefs = PLANT_IDLE_PREFERENCES if row["category"] == "plants" else IDLE_PREFERENCES
            idle = _pick_idle(data, prefs)
            if idle is not None:
                idle_prefix = morph_symbol_prefix(row["key"])
                idle_symbols = _apply_symbol_prefix(symbols, names, idle_prefix) if idle_prefix else symbols
                idle_frames = render_anim(idle_symbols, idle, skip)
                encode_webp(idle_frames, idle.rate, out_dir / f"{row['key']}_idle.webp")
                if verbose:
                    print(f"        idle: {idle.name} {len(idle_frames)}f")

    print(f"\nDone: {ok} rendered, {miss} missing.")


if __name__ == "__main__":
    main()
