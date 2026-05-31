"""Composite kanim frames over their atlases and encode a looping animated WebP.

Symbol table maps a symbol hash to (build frames, source atlas). A creature is
split across multiple builds, each with its own atlas, so the caller merges them
before rendering. See the validated approach in the plan.
"""
from PIL import Image

from scripts.kanim.anim import Anim
from scripts.kanim.build import BuildFrame

SymbolTable = dict[int, tuple[list[BuildFrame], Image.Image]]


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


def render_anim(symbols: SymbolTable, anim: Anim, skip_hashes: frozenset[int] = frozenset()) -> list[Image.Image]:
    frames = anim.frames
    xs = [fr.x - fr.w / 2 for fr in frames]
    ys = [fr.y - fr.h / 2 for fr in frames]
    xe = [fr.x + fr.w / 2 for fr in frames]
    ye = [fr.y + fr.h / 2 for fr in frames]
    ox, oy = min(xs), min(ys)
    cw0, ch0 = max(xe) - ox, max(ye) - oy
    margin = 0.5 * max(cw0, ch0, 1.0)
    ox -= margin
    oy -= margin
    cw = max(1, int(round(cw0 + 2 * margin)))
    ch = max(1, int(round(ch0 + 2 * margin)))

    out: list[Image.Image] = []
    for fr in frames:
        canvas = Image.new("RGBA", (cw, ch), (0, 0, 0, 0))
        for el in sorted(fr.elements, key=lambda e: e.order, reverse=True):
            if el.symbol_hash in skip_hashes:
                continue
            entry = symbols.get(el.symbol_hash)
            if entry is None:
                continue
            build_frames, atlas = entry
            if not build_frames:
                continue
            bf = build_frames[min(el.frame_index, len(build_frames) - 1)]
            sprite = crop_symbol(atlas, bf)
            if sprite is None:
                continue
            a, b, c, d = el.matrix
            tx, ty = el.translation
            det = a * d - b * c
            if abs(det) < 1e-8:
                continue
            ia, ib, ic, id_ = d / det, -b / det, -c / det, a / det
            wx0, wy0 = ox - tx, oy - ty
            cu = bf.pivot_x + ia * wx0 + ic * wy0
            cv = bf.pivot_y + ib * wx0 + id_ * wy0
            warped = sprite.transform((cw, ch), Image.AFFINE, (ia, ic, cu, ib, id_, cv), resample=Image.BILINEAR)
            canvas.alpha_composite(warped)
        out.append(canvas)

    # Stable framing: crop every frame to the union of non-transparent bounds.
    union: tuple[int, int, int, int] | None = None
    for canvas in out:
        bb = canvas.getbbox()
        if bb is None:
            continue
        union = bb if union is None else (
            min(union[0], bb[0]), min(union[1], bb[1]), max(union[2], bb[2]), max(union[3], bb[3]),
        )
    if union is not None:
        pad = 4
        union = (max(0, union[0] - pad), max(0, union[1] - pad), min(cw, union[2] + pad), min(ch, union[3] + pad))
        out = [canvas.crop(union) for canvas in out]
    return out


def encode_webp(frames: list[Image.Image], rate: float, out_path, max_px: int = 160, quality: int = 80) -> None:
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
