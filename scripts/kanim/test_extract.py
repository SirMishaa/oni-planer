"""Tests for the world sprite and portrait frame rules of the kanim renderer.

Usage:
    uv run --with pytest --with UnityPy --with Pillow python -m pytest scripts/kanim
"""
import pytest
from PIL import Image

from scripts.kanim.anim import Anim, AnimData, AnimElement, AnimFrame
from scripts.kanim.extract import WorldSpriteError, encode_still, first_frame, klei_hash, pick_world_anim, skipped_symbols


def _frame() -> AnimFrame:
    return AnimFrame(0, 0, 10, 10, [AnimElement(1, 0, (1, 0, 0, 1), (0, 0), 0)])


def _anim(name: str, frames: int) -> Anim:
    return Anim(name=name, rate=30, frames=[_frame() for _ in range(frames)])


def test_the_world_anim_is_the_idle_loop_the_game_draws():
    data = AnimData(version=5, anims=[_anim("ui", 5), _anim("idle_loop", 47), _anim("idle_default", 20)])

    assert pick_world_anim(data).name == "idle_loop"


def test_the_world_anim_never_falls_back_to_another_anim():
    data = AnimData(version=5, anims=[_anim("ui", 5), _anim("idle_default", 20)])

    assert pick_world_anim(data) is None


def test_the_world_anim_is_skipped_when_its_frames_are_empty():
    data = AnimData(version=5, anims=[_anim("idle_loop", 0)])

    assert pick_world_anim(data) is None


def test_first_frame_keeps_only_frame_zero():
    anim = _anim("idle_loop", 49)

    still = first_frame(anim)

    assert len(still.frames) == 1
    assert still.frames[0] is anim.frames[0]
    assert len(anim.frames) == 49


def test_encode_still_writes_a_single_frame_webp(tmp_path):
    out = tmp_path / "Pacu_world.webp"

    encode_still([Image.new("RGBA", (12, 8), (255, 0, 0, 255))], out)

    with Image.open(out) as written:
        assert getattr(written, "n_frames", 1) == 1
        assert written.size == (12, 8)


def test_encode_still_refuses_several_frames(tmp_path):
    out = tmp_path / "ParrotFish.webp"
    frames = [Image.new("RGBA", (12, 8), (255, 0, 0, 255)), Image.new("RGBA", (12, 8), (0, 0, 255, 255))]

    with pytest.raises(WorldSpriteError):
        encode_still(frames, out)

    assert not out.exists()


def test_klei_hash_matches_the_game_symbol_hashes():
    assert klei_hash("snapto_pivot") == -2004390296
    assert klei_hash("SNAPTO_TAG") == -660180960


def test_anchor_symbols_are_skipped_even_when_the_build_does_not_name_them():
    names = {klei_hash("body"): "body", 42: "fx_snapto_glow"}

    skipped = skipped_symbols(names)

    assert klei_hash("snapto_pivot") in skipped
    assert klei_hash("snapto_tag") in skipped
    assert 42 in skipped
    assert klei_hash("body") not in skipped


def test_the_divergent_segment_tracker_is_never_drawn():
    names = {klei_hash("segmenttracker"): "segmenttracker", klei_hash("head"): "head"}

    skipped = skipped_symbols(names)

    assert klei_hash("segmenttracker") in skipped
    assert klei_hash("head") not in skipped
