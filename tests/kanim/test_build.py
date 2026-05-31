import struct

from scripts.kanim.build import parse_build


def _klei_str(s: str) -> bytes:
    return struct.pack("<i", len(s)) + s.encode("ascii")


def _synthetic_build(version: int) -> bytes:
    out = b"BILD" + struct.pack("<iii", version, 1, 1) + _klei_str("test")
    out += struct.pack("<i", 100)
    if version >= 10:
        out += struct.pack("<i", 0)  # path
    out += struct.pack("<Ii", 0xFFFFFFFF, 0)  # color, flags
    out += struct.pack("<i", 1)  # numFrames
    out += struct.pack("<iii", 0, 1, 0)
    out += struct.pack("<ffff", 0.0, 0.0, 32.0, 32.0)
    out += struct.pack("<ffff", 0.0, 0.0, 0.5, 0.5)
    out += struct.pack("<i", 1) + struct.pack("<i", 100) + _klei_str("body")
    return out


def test_parses_symbol_frame_uv_and_hash_name():
    build = parse_build(_synthetic_build(version=10))
    assert build.name == "test"
    assert build.hashes[100] == "body"
    sym = build.symbols[0]
    assert sym.hash == 100 and sym.name == "body"
    frame = sym.frames[0]
    assert frame.uv == (0.0, 0.0, 0.5, 0.5)
    assert frame.pivot_w == 32.0 and frame.pivot_h == 32.0
