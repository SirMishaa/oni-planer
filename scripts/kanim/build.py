"""Parser for Klei kanim *_build.bytes (symbols, frames, atlas UVs).

Validated against ONI build version 10 (see the proof in the plan): each symbol
carries a path (v>=10), color, flags, then frames; each frame has a pivot
(x/y/w/h) and a normalized UV rect into the build's atlas.
"""
from dataclasses import dataclass, field

from scripts.kanim.reader import BinaryReader


@dataclass
class BuildFrame:
    source_frame: int
    duration: int
    build_image_index: int
    pivot_x: float
    pivot_y: float
    pivot_w: float
    pivot_h: float
    uv: tuple[float, float, float, float]  # x1, y1, x2, y2 (normalized)


@dataclass
class BuildSymbol:
    hash: int
    name: str
    frames: list[BuildFrame] = field(default_factory=list)


@dataclass
class Build:
    name: str
    version: int
    symbols: list[BuildSymbol]
    hashes: dict[int, str]


def parse_build(data: bytes) -> Build:
    r = BinaryReader(data)
    assert r.magic() == b"BILD", "not a BILD file"
    version = r.int32()
    symbol_count = r.int32()
    r.int32()  # total frame count (unused; per-symbol counts drive parsing)
    name = r.string()

    symbols: list[BuildSymbol] = []
    for _ in range(symbol_count):
        symbol_hash = r.int32()
        if version >= 10:
            r.int32()  # path hash (unused)
        r.uint32()  # color (unused)
        r.int32()  # flags (unused)
        num_frames = r.int32()
        frames: list[BuildFrame] = []
        for _ in range(num_frames):
            source_frame = r.int32()
            duration = r.int32()
            build_image_index = r.int32()
            pivot_x = r.float32()
            pivot_y = r.float32()
            pivot_w = r.float32()
            pivot_h = r.float32()
            uv = (r.float32(), r.float32(), r.float32(), r.float32())
            frames.append(BuildFrame(source_frame, duration, build_image_index, pivot_x, pivot_y, pivot_w, pivot_h, uv))
        symbols.append(BuildSymbol(symbol_hash, "", frames))

    hashes: dict[int, str] = {}
    hash_count = r.int32()
    for _ in range(hash_count):
        h = r.int32()
        hashes[h] = r.string()

    for symbol in symbols:
        symbol.name = hashes.get(symbol.hash, str(symbol.hash))

    return Build(name=name, version=version, symbols=symbols, hashes=hashes)
