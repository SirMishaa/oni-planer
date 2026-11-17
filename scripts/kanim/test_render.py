"""Tests for the kanim compositor.

Usage:
    uv run --with pytest --with UnityPy --with Pillow python -m pytest scripts/kanim
"""
from PIL import Image

from scripts.kanim.anim import Anim, AnimElement, AnimFrame
from scripts.kanim.build import BuildFrame
from scripts.kanim.render import render_anim

RED, BLUE = 1, 2


def _symbol(color: tuple[int, int, int, int]):
    atlas = Image.new("RGBA", (16, 16), color)
    frame = BuildFrame(source_frame=0, duration=1, build_image_index=0, pivot_x=0, pivot_y=0, pivot_w=16, pivot_h=16, uv=(0.0, 0.0, 1.0, 1.0))
    return [frame], lambda index: atlas


def _element(symbol_hash: int) -> AnimElement:
    return AnimElement(symbol_hash, 0, (1, 0, 0, 1), (0, 0), 0.0)


def test_the_first_element_of_a_frame_is_drawn_in_front():
    symbols = {RED: _symbol((255, 0, 0, 255)), BLUE: _symbol((0, 0, 255, 255))}
    # Klei lists a frame's elements front to back (a Pacu's idle_loop: eye, mouth, body, fins)
    # and every element's order field is 0.
    anim = Anim(name="idle_loop", rate=30, frames=[AnimFrame(0, 0, 16, 16, [_element(RED), _element(BLUE)])])

    [frame] = render_anim(symbols, anim)

    assert frame.getpixel((frame.width // 2, frame.height // 2)) == (255, 0, 0, 255)
