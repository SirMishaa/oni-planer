"""Composite kanim frames over their atlases and encode a looping animated WebP.

Symbol table maps a symbol hash to (build frames, atlas page lookup). A creature is
split across multiple builds, each with its own atlas, so the caller merges them
before rendering. Large builds span several atlas pages ({build}_0, _1, ...): each
build frame names its page in build_image_index.
"""
from collections.abc import Callable

from PIL import Image

from scripts.kanim.anim import Anim
from scripts.kanim.build import BuildFrame

AtlasPages = Callable[[int], Image.Image | None]
SymbolTable = dict[int, tuple[list[BuildFrame], AtlasPages]]


def crop_symbol(atlas: Image.Image, frame: BuildFrame) -> Image.Image | None:
    x1, y1, x2, y2 = frame.uv
    w, h = atlas.size
    box = (int(round(x1 * w)), int(round(y1 * h)), int(round(x2 * w)), int(round(y2 * h)))
    if box[2] <= box[0] or box[3] <= box[1]:
        return None
    sprite = atlas.crop(box)
    # Atlas is exported at half res; scale each symbol up to its build pivot size
    # so the pivot and element matrix line up in anim units.
    return sprite.resize((max(1, int(round(frame.pivot_w))), max(1, int(round(frame.pivot_h)))), Image.BILINEAR)


MAX_CANVAS_PX = 4096


def _is_black_mask(sprite: Image.Image) -> bool:
    """Pure-black silhouette frames (e.g. a Pokeshell body mask in idle_loop) drive
    shader effects in game and are never shown as-is."""
    return all(high == 0 for _, high in sprite.convert("RGB").getextrema())


def _tint(sprite: Image.Image, color: tuple[float, float, float, float]) -> Image.Image:
    """Multiply each RGBA channel by the element's tint, as the game's shader does."""
    bands = [band.point(lambda v, f=factor: int(v * max(0.0, min(1.0, f)))) for band, factor in zip(sprite.split(), color)]
    return Image.merge("RGBA", bands)


def render_anim(symbols: SymbolTable, anim: Anim, skip_hashes: frozenset[int] = frozenset()) -> list[Image.Image]:
    frames = anim.frames
    # Canvas must contain every transformed sprite, not just the declared frame
    # bbox — some ui anims (e.g. snail) declare a bbox that misses the art, so
    # project each element's sprite corners through its matrix.
    pts_x: list[float] = []
    pts_y: list[float] = []
    for fr in frames:
        pts_x += [fr.x - fr.w / 2, fr.x + fr.w / 2]
        pts_y += [fr.y - fr.h / 2, fr.y + fr.h / 2]
        for el in fr.elements:
            if el.symbol_hash in skip_hashes:
                continue
            entry = symbols.get(el.symbol_hash)
            if entry is None or not entry[0]:
                continue
            bf = entry[0][min(el.frame_index, len(entry[0]) - 1)]
            sw, sh = max(1, int(round(bf.pivot_w))), max(1, int(round(bf.pivot_h)))
            ax, ay = sw / 2 - bf.pivot_x, sh / 2 - bf.pivot_y
            a, b, c, d = el.matrix
            tx, ty = el.translation
            for u, v in ((0, 0), (sw, 0), (0, sh), (sw, sh)):
                lx, ly = u - ax, v - ay
                pts_x.append(a * lx + c * ly + tx)
                pts_y.append(b * lx + d * ly + ty)
    pad = 8.0
    ox, oy = min(pts_x) - pad, min(pts_y) - pad
    cw = max(1, int(round(max(pts_x) + pad - ox)))
    ch = max(1, int(round(max(pts_y) + pad - oy)))
    # An outlier element (huge translation/scale) can blow the canvas up to tens of
    # thousands of pixels; clamp around the centre rather than allocating GBs.
    if cw > MAX_CANVAS_PX or ch > MAX_CANVAS_PX:
        print(f"    ! canvas {cw}x{ch} clamped to {MAX_CANVAS_PX}px")
        if cw > MAX_CANVAS_PX:
            ox += (cw - MAX_CANVAS_PX) / 2
            cw = MAX_CANVAS_PX
        if ch > MAX_CANVAS_PX:
            oy += (ch - MAX_CANVAS_PX) / 2
            ch = MAX_CANVAS_PX

    # Each frame is cropped to its own content as soon as it is drawn (memory stays
    # proportional to the art, not frames x canvas), then re-framed on the union.
    pieces: list[tuple[Image.Image, tuple[int, int, int, int]] | None] = []
    for fr in frames:
        canvas = Image.new("RGBA", (cw, ch), (0, 0, 0, 0))
        for el in sorted(fr.elements, key=lambda e: e.order, reverse=True):
            if el.symbol_hash in skip_hashes or el.color[3] <= 0.01:
                continue
            entry = symbols.get(el.symbol_hash)
            if entry is None:
                continue
            build_frames, atlas_page = entry
            if not build_frames:
                continue
            bf = build_frames[min(el.frame_index, len(build_frames) - 1)]
            atlas = atlas_page(bf.build_image_index)
            if atlas is None:
                continue
            sprite = crop_symbol(atlas, bf)
            if sprite is None or _is_black_mask(sprite):
                continue
            if any(c < 0.999 for c in el.color):
                sprite = _tint(sprite, el.color)
            a, b, c, d = el.matrix
            tx, ty = el.translation
            det = a * d - b * c
            if abs(det) < 1e-8:
                continue
            ia, ib, ic, id_ = d / det, -b / det, -c / det, a / det
            # The build pivot is measured from the symbol center, so the anchor
            # in sprite-pixel space is (w/2 - pivot_x, h/2 - pivot_y). Using the
            # raw pivot scatters limbs by an offset that grows with sprite size.
            sw, sh = sprite.size
            ax, ay = sw / 2 - bf.pivot_x, sh / 2 - bf.pivot_y
            wx0, wy0 = ox - tx, oy - ty
            cu = ax + ia * wx0 + ic * wy0
            cv = ay + ib * wx0 + id_ * wy0
            warped = sprite.transform((cw, ch), Image.AFFINE, (ia, ic, cu, ib, id_, cv), resample=Image.BILINEAR)
            canvas.alpha_composite(warped)
            del warped
        bb = canvas.getbbox()
        pieces.append((canvas.crop(bb), bb) if bb is not None else None)
        del canvas

    # Stable framing: every frame shares the union of non-transparent bounds.
    boxes = [p[1] for p in pieces if p is not None]
    if not boxes:
        return [Image.new("RGBA", (1, 1), (0, 0, 0, 0)) for _ in pieces]
    pad = 4
    union = (
        max(0, min(b[0] for b in boxes) - pad), max(0, min(b[1] for b in boxes) - pad),
        min(cw, max(b[2] for b in boxes) + pad), min(ch, max(b[3] for b in boxes) + pad),
    )
    size = (union[2] - union[0], union[3] - union[1])
    out: list[Image.Image] = []
    for piece in pieces:
        framed = Image.new("RGBA", size, (0, 0, 0, 0))
        if piece is not None:
            img, bb = piece
            framed.paste(img, (bb[0] - union[0], bb[1] - union[1]))
        out.append(framed)
    return out


def encode_webp(frames: list[Image.Image], rate: float, out_path, max_px: int = 256, quality: int = 82) -> None:
    if not frames:
        raise ValueError("no frames to encode")
    big = max(frames[0].size)
    if big > max_px:
        scale = max_px / big
        frames = [f.resize((max(1, int(f.width * scale)), max(1, int(f.height * scale))), Image.BILINEAR) for f in frames]
    duration_ms = max(1, int(round(1000 / max(rate, 1.0))))
    frames[0].save(
        out_path,
        "webp",
        save_all=True,
        append_images=frames[1:],
        duration=duration_ms,
        loop=0,
        quality=quality,
        method=6,
        disposal=2,
    )
