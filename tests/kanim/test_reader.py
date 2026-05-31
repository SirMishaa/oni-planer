import struct

from scripts.kanim.reader import BinaryReader


def test_reads_ints_floats_and_klei_string():
    payload = struct.pack("<i", 7) + struct.pack("<f", 1.5) + struct.pack("<i", 3) + b"abc"
    r = BinaryReader(payload)
    assert r.int32() == 7
    assert r.float32() == 1.5
    assert r.string() == "abc"
    assert r.eof()
