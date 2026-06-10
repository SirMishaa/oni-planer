"""Compositing tests for the kanim renderer.

These guard the symbol-pivot convention: a build frame's (pivot_x, pivot_y) is
the anchor measured from the symbol *center*, so the anchor in sprite-pixel
space is (w/2 - pivot_x, h/2 - pivot_y). Using the raw (pivot_x, pivot_y) as the
anchor scatters limbs by an amount that grows with each symbol's size — the bug
this test pins down.
"""
from PIL import Image

from scripts.kanim.anim import Anim, AnimElement, AnimFrame
from scripts.kanim.build import BuildFrame
from scripts.kanim.render import render_anim


def _solid(color: tuple[int, int, int, int]) -> Image.Image:
    return Image.new("RGBA", (10, 10), color)


def _frame(pivot_x: float) -> list[BuildFrame]:
    # Full-atlas UV; resized to a 6x6 sprite. pivot_y centered (=3 → no y shift).
    return [BuildFrame(0, 1, 0, pivot_x, 3.0, 6.0, 6.0, (0.0, 0.0, 1.0, 1.0))]


def _centroid_x(img: Image.Image, channel: int) -> float:
    px = img.load()
    total = weighted = 0
    for y in range(img.height):
        for x in range(img.width):
            value = px[x, y][channel]
            total += value
            weighted += value * x
    assert total > 0, "channel not present in render"
    return weighted / total


def test_symbol_anchor_is_measured_from_center() -> None:
    red, blue = 0x10000001, 0x10000002
    symbols = {
        red: (_frame(pivot_x=0.0), _solid((255, 0, 0, 255))),
        blue: (_frame(pivot_x=12.0), _solid((0, 0, 255, 255))),
    }
    identity = (1.0, 0.0, 0.0, 1.0)
    frame = AnimFrame(
        0.0,
        0.0,
        40.0,
        40.0,
        [
            AnimElement(red, 0, identity, (0.0, 0.0), 0.0),
            AnimElement(blue, 0, identity, (0.0, 0.0), 1.0),
        ],
    )
    anim = Anim(name="idle", rate=10.0, frames=[frame])

    [rendered] = render_anim(symbols, anim)

    # Both symbols share translation (0,0); only their pivots differ. With the
    # anchor measured from the center, the larger pivot_x pushes blue to the
    # right of red. The buggy raw-pivot anchor reverses this ordering.
    assert _centroid_x(rendered, 2) > _centroid_x(rendered, 0)
