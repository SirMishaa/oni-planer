"""Little-endian binary reader for Klei kanim files."""
import struct


class BinaryReader:
    def __init__(self, data: bytes) -> None:
        self._data = data
        self._pos = 0

    def int32(self) -> int:
        value = struct.unpack_from("<i", self._data, self._pos)[0]
        self._pos += 4
        return value

    def uint32(self) -> int:
        value = struct.unpack_from("<I", self._data, self._pos)[0]
        self._pos += 4
        return value

    def float32(self) -> float:
        value = struct.unpack_from("<f", self._data, self._pos)[0]
        self._pos += 4
        return value

    def magic(self) -> bytes:
        value = self._data[self._pos:self._pos + 4]
        self._pos += 4
        return value

    def string(self) -> str:
        length = self.int32()
        raw = self._data[self._pos:self._pos + length]
        self._pos += length
        return raw.decode("ascii", "replace")

    def remaining(self) -> int:
        return len(self._data) - self._pos

    def eof(self) -> bool:
        return self._pos >= len(self._data)
