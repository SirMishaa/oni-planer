"""Throwaway: render arbitrary kanim bases (by name) to /tmp for visual review."""
import sys

import UnityPy
from PIL import Image

from scripts.kanim.anim import parse_anim
from scripts.kanim.build import parse_build
from scripts.kanim.extract import ASSETS, DENY_NAMES, _pick_idle, _raw
from scripts.kanim.render import encode_webp, render_anim

env = UnityPy.load(str(ASSETS))
builds: dict[str, bytes] = {}
anims: dict[str, bytes] = {}
tex_objs: dict[str, object] = {}
for obj in env.objects:
    if obj.type.name == "TextAsset":
        d = obj.read()
        if d.m_Name.endswith("_anim"):
            anims[d.m_Name] = _raw(d)
        elif d.m_Name.endswith("_build"):
            builds[d.m_Name] = _raw(d)
    elif obj.type.name == "Texture2D":
        d = obj.read()
        if d.m_Name.endswith("_0"):
            tex_objs[d.m_Name] = d

for base in sys.argv[1:]:
    anim_bytes = anims.get(f"{base}_anim")
    build_names = [n for n in builds if n == f"{base}_build" or n.startswith(f"{base}_build_")]
    if not anim_bytes or not build_names:
        print(f"  {base}: anim={anim_bytes is not None} builds={len(build_names)} -> SKIP")
        continue
    symbols = {}
    names = {}
    for bn in build_names:
        obj = tex_objs.get(bn[: -len("_build")] + "_0")
        atl = obj.image.convert("RGBA") if obj else None
        if atl is None:
            continue
        build = parse_build(builds[bn])
        names.update(build.hashes)
        for sym in build.symbols:
            symbols[sym.hash] = (sym.frames, atl)
    anim = _pick_idle(parse_anim(anim_bytes))
    skip = frozenset(h for h, nm in names.items() if any(d in nm.lower() for d in DENY_NAMES))
    frames = render_anim(symbols, anim, skip)
    out = f"/tmp/kanim_{base}.webp"
    encode_webp(frames, anim.rate, out)
    print(f"  {base}: anim='{anim.name}' {len(frames)}f size={frames[0].size} -> {out}")
