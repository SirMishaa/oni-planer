"""Throwaway: report which worklist entities resolve to a kanim and suggest
candidate bases (by token overlap) for the ones that don't."""
import re

import UnityPy

from scripts.kanim.extract import ASSETS, PREFIX_SKIP, SUFFIX_SKIP, _norm, _raw, _resolve_base
from scripts.kanim.mapping import load_worklist


def tokens(s: str) -> set[str]:
    return set(re.findall(r"[a-z0-9]+", re.sub(r"(?<!^)(?=[A-Z])", " ", s).lower()))


env = UnityPy.load(str(ASSETS))
anims: dict[str, bytes] = {}
builds: set[str] = set()
for obj in env.objects:
    if obj.type.name == "TextAsset":
        d = obj.read()
        if d.m_Name.endswith("_anim"):
            anims[d.m_Name] = _raw(d)
        elif d.m_Name.endswith("_build"):
            builds.add(d.m_Name)

norm_index: dict[str, str] = {}
primary_bases: list[str] = []
for name in anims:
    base = name[: -len("_anim")]
    if any(base.startswith(p) for p in PREFIX_SKIP) or any(base.endswith(s) for s in SUFFIX_SKIP):
        continue
    norm_index.setdefault(_norm(base), base)
    primary_bases.append(base)

worklist = load_worklist()
for cat in ("plants", "critters"):
    rows = [r for r in worklist if r["category"] == cat]
    print(f"\n===== {cat} ({len(rows)}) =====")
    for row in sorted(rows, key=lambda r: r["key"]):
        base = _resolve_base(row, norm_index, anims)
        has_build = base and any(b == f"{base}_build" or b.startswith(f"{base}_build_") for b in builds)
        if base and has_build:
            continue
        kt = tokens(row["key"])
        scored = sorted(
            ((len(kt & tokens(b)), b) for b in primary_bases),
            reverse=True,
        )[:6]
        cand = ", ".join(f"{b}({n})" for n, b in scored if n > 0) or "—"
        print(f"  MISS {row['key']:<28} kanim={row['kanim']!r:<28} -> {cand}")
