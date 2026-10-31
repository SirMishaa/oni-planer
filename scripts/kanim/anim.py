"""Parser for Klei kanim *_anim.bytes (animations, frames, element transforms).

Validated against ONI anim version 5. Element transform is a 2x3 matrix
(m1..m4 + tx, ty) applied as world = M.(local - pivot) + t. A trailing hash
table follows the anims and is not needed for rendering.
"""
from dataclasses import dataclass, field

from scripts.kanim.reader import BinaryReader


@dataclass
class AnimElement:
    symbol_hash: int
    frame_index: int
    matrix: tuple[float, float, float, float]  # m1, m2, m3, m4
    translation: tuple[float, float]
    order: float
    # Multiplicative tint (r, g, b, a). The game hides layers with alpha 0 (e.g. the
    # Pokeshell's alternate shells), so they must not be drawn.
    color: tuple[float, float, float, float] = (1.0, 1.0, 1.0, 1.0)


@dataclass
class AnimFrame:
    x: float
    y: float
    w: float
    h: float
    elements: list[AnimElement] = field(default_factory=list)


@dataclass
class Anim:
    name: str
    rate: float
    frames: list[AnimFrame] = field(default_factory=list)


@dataclass
class AnimData:
    version: int
    anims: list[Anim]


def parse_anim(data: bytes) -> AnimData:
    r = BinaryReader(data)
    assert r.magic() == b"ANIM", "not an ANIM file"
    version = r.int32()
    r.int32()  # maxVisSymbolFrames (unused)
    r.int32()  # total frame count (unused)
    anim_count = r.int32()

    anims: list[Anim] = []
    for _ in range(anim_count):
        name = r.string()
        r.int32()  # root symbol hash (unused)
        rate = r.float32()
        frame_count = r.int32()
        frames: list[AnimFrame] = []
        for _ in range(frame_count):
            x, y, w, h = r.float32(), r.float32(), r.float32(), r.float32()
            element_count = r.int32()
            elements: list[AnimElement] = []
            for _ in range(element_count):
                symbol_hash = r.int32()
                frame_index = r.int32()
                r.int32()  # folder hash (unused)
                r.int32()  # flags (unused)
                ca, cb, cg, cr = r.float32(), r.float32(), r.float32(), r.float32()
                m1, m2, m3, m4 = r.float32(), r.float32(), r.float32(), r.float32()
                tx, ty = r.float32(), r.float32()
                order = r.float32()
                elements.append(AnimElement(symbol_hash, frame_index, (m1, m2, m3, m4), (tx, ty), order, (cr, cg, cb, ca)))
            frames.append(AnimFrame(x, y, w, h, elements))
        anims.append(Anim(name=name, rate=rate, frames=frames))

    return AnimData(version=version, anims=anims)
