import struct

from scripts.kanim.anim import parse_anim


def _klei_str(s: str) -> bytes:
    return struct.pack("<i", len(s)) + s.encode("ascii")


def _synthetic_anim() -> bytes:
    out = b"ANIM" + struct.pack("<iiii", 5, 1, 1, 1)  # version, maxVis, frameCount, animCount
    out += _klei_str("idle_loop") + struct.pack("<i", 0) + struct.pack("<f", 30.0) + struct.pack("<i", 1)
    out += struct.pack("<ffff", 0.0, 0.0, 64.0, 64.0) + struct.pack("<i", 1)  # frame bbox + elementCount
    out += struct.pack("<iiii", 100, 0, 0, 0)  # symbolHash, frameIndex, folder, flags
    out += struct.pack("<ffff", 1.0, 1.0, 1.0, 1.0)  # color a,b,g,r
    out += struct.pack("<ffffff", 1.0, 0.0, 0.0, 1.0, 5.0, 6.0)  # m1..m4, tx, ty
    out += struct.pack("<f", 0.0)  # order
    out += struct.pack("<i", 0)  # empty hash table
    return out


def test_parses_anim_frame_element_transform():
    data = parse_anim(_synthetic_anim())
    assert data.version == 5
    idle = data.anims[0]
    assert idle.name == "idle_loop" and idle.rate == 30.0
    element = idle.frames[0].elements[0]
    assert element.symbol_hash == 100
    assert element.matrix == (1.0, 0.0, 0.0, 1.0)
    assert element.translation == (5.0, 6.0)
