#!/usr/bin/env python3
"""bin/gen-pwa-icons.py — generate the PWA icon set from the app tokens.

Design (PWA-02, REQUIREMENTS v2.7): a 3-lane Kanban glyph in the light base
on a solid --color-primary tile. The glyph is kept inside the Android
maskable safe zone (central circle at r = 204.8/512) so the same tile works
for `purpose: any` AND `purpose: maskable`.

Output (all deterministic, no network):
  www/img/icon-512.png          manifest icon, 512, purpose any + maskable
  www/img/icon-192.png          manifest icon, 192, purpose any
  www/img/apple-touch-icon.png  iOS, 180 (opaque tile, iOS-safe)
  www/img/favicon.png           48 favicon (opaque tile)

Depends only on Pillow (no system tools).
"""

from pathlib import Path

from PIL import Image, ImageDraw

try:  # Pillow >= 9.1
    LANCZOS = Image.Resampling.LANCZOS
except AttributeError:  # pragma: no cover (Pillow < 9.1)
    LANCZOS = Image.LANCZOS

PRIMARY = (109, 40, 217)   # --color-primary #6D28D9 (dark + light tokens agree)
GLYPH = (18, 18, 34)      # --color-text #1A1A2E (light-theme ink) — reads as
                          # a cut-out on the solid primary tile in both themes

OUT = Path(__file__).resolve().parent.parent / "www" / "img"

# Maskable safe zone (Android): the central circle r = 0.4 * 512 = 204.8 px
# around (256, 256). A square of half-side <= 204.8/sqrt(2) = 144.8 fits fully
# in it, so ALL glyph geometry stays inside the box [112..400] x [112..400].
LANES = [
    (114, 140, 76, 232),  # left: medium height
    (216, 118, 76, 276),  # middle: tallest, carries the card
    (318, 170, 76, 172),  # right: shortest
]
CARD = (228, 134, 52, 44)  # the work card inside the middle lane


def _round(d: ImageDraw.ImageDraw, box, radius: int, fill) -> None:
    l, t, w, h = box
    d.rounded_rectangle(
        (int(l), int(t), int(l + w), int(t + h)),
        radius=radius,
        fill=fill,
    )


def render_tile(base: int) -> Image.Image:
    """Draw the 512-space glyph scaled to `base`; solid tile, opaque."""
    s = base / 512.0
    scale = lambda v: int(round(v * s))  # noqa: E731
    img = Image.new("RGBA", (base, base), PRIMARY + (255,))
    d = ImageDraw.Draw(img)
    for lane in LANES:
        _round(d, (scale(lane[0]), scale(lane[1]),
                   scale(lane[2]), scale(lane[3])), radius=scale(28), fill=GLYPH)
    _round(d, (scale(CARD[0]), scale(CARD[1]),
               scale(CARD[2]), scale(CARD[3])), radius=scale(20), fill=PRIMARY)
    return img


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    src = render_tile(512)
    outputs = [
        (OUT / "icon-512.png", 512),
        (OUT / "icon-192.png", 192),
        (OUT / "apple-touch-icon.png", 180),
        (OUT / "favicon.png", 48),
    ]
    for path, size in outputs:
        if size == 512:
            im = src
        else:
            im = src.resize((size, size), LANCZOS)
        im.save(path, "PNG")
        print(f"wrote {path}  ({size}x{size})")


if __name__ == "__main__":
    main()
