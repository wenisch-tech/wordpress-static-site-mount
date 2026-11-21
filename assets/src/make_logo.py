#!/usr/bin/env python3
"""Draws the Stasimo logo: a theme header and footer with an empty slot between them,
and a page sliding into that slot. Writes static PNGs and animated GIFs into assets/. Needs Pillow."""
import math, os
from PIL import Image, ImageDraw, ImageChops

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
S, SS = 512, 3
BG1, BG2 = (59, 108, 246), (24, 38, 112)
ACCENT = (59, 108, 246)

# geometry (design space 512)
X0, X1 = 84, 428
HEAD = (100, 160)
SLOT = (176, 336)
FOOT = (352, 412)


def clamp(x): return max(0.0, min(1.0, x))
def seg(t, a, b): return clamp((t - a) / (b - a))
def out_back(t):
    c1, c3 = 1.2, 2.2
    return 1 + c3 * (t - 1) ** 3 + c1 * (t - 1) ** 2
def out_cubic(t): return 1 - (1 - t) ** 3


def gradient():
    n = S * SS
    img = Image.new('RGB', (n, n))
    px = img.load()
    for y in range(n):
        for x in range(n):
            k = (x + y) / (2 * n)
            px[x, y] = tuple(int(BG1[i] + (BG2[i] - BG1[i]) * k) for i in range(3))
    return img
GRAD = gradient()


def rrect(d, x0, y0, x1, y1, r, fill):
    d.rounded_rectangle((x0 * SS, y0 * SS, x1 * SS, y1 * SS), radius=r * SS, fill=fill)


def dashed_slot(d, alpha):
    """Dashed outline of the empty slot (straight edges only)."""
    col = (255, 255, 255, alpha)
    w = 3 * SS
    dash, gap = 14, 9
    x = X0 + 16
    while x < X1 - 16:
        e = min(x + dash, X1 - 16)
        for y in (SLOT[0], SLOT[1]):
            d.line((x * SS, y * SS, e * SS, y * SS), fill=col, width=w)
        x += dash + gap
    y = SLOT[0] + 16
    while y < SLOT[1] - 16:
        e = min(y + dash, SLOT[1] - 16)
        for x in (X0, X1):
            d.line((x * SS, y * SS, x * SS, e * SS), fill=col, width=w)
        y += dash + gap


def frame(t, bg=True):
    base = Image.new('RGBA', (S * SS, S * SS), (0, 0, 0, 0))
    if bg:
        mask = Image.new('L', base.size, 0)
        ImageDraw.Draw(mask).rounded_rectangle((0, 0, S * SS - 1, S * SS - 1), radius=112 * SS, fill=255)
        base.paste(GRAD, (0, 0), mask)
    layer = Image.new('RGBA', base.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    fade = 1 - seg(t, 0.93, 1.0)

    # header: slides down from above
    p = out_cubic(seg(t, 0.00, 0.14))
    a = int(255 * p * fade)
    dy = (1 - p) * -40
    rrect(d, X0, HEAD[0] + dy, X1, HEAD[1] + dy, 16, (255, 255, 255, int(a * .30)))
    rrect(d, X0 + 18, HEAD[0] + 18 + dy, X0 + 42, HEAD[0] + 42 + dy, 7, (255, 255, 255, int(a * .85)))   # site logo
    for i in range(3):                                                                                     # menu
        x = X1 - 28 - (3 - i) * 50
        rrect(d, x, HEAD[0] + 25 + dy, x + 38, HEAD[0] + 35 + dy, 5, (255, 255, 255, int(a * .65)))

    # footer: slides up from below
    dy = (1 - p) * 40
    rrect(d, X0, FOOT[0] + dy, X1, FOOT[1] + dy, 16, (255, 255, 255, int(a * .30)))
    for i, w in enumerate((70, 52, 62)):
        x = X0 + 22 + i * 100
        rrect(d, x, FOOT[0] + 20 + dy, x + w, FOOT[0] + 30 + dy, 5, (255, 255, 255, int(a * .65)))
        rrect(d, x, FOOT[0] + 36 + dy, x + w * .7, FOOT[0] + 44 + dy, 4, (255, 255, 255, int(a * .35)))

    # empty slot between them, until the page lands
    slot_in = seg(t, 0.10, 0.20)
    slot_out = 1 - seg(t, 0.46, 0.54)
    sa = int(190 * slot_in * slot_out * fade)
    if sa > 4:
        dashed_slot(d, sa)

    # the page slides in from the right
    q = seg(t, 0.26, 0.52)
    if q > 0:
        e = out_back(q)
        off = (1 - e) * 430
        ba = int(255 * clamp(q * 4) * fade)
        x0, x1 = X0 + off, X1 + off
        rrect(d, x0, SLOT[0], x1, SLOT[1], 16, (247, 248, 253, ba))
        rrect(d, x0 + 26, SLOT[0] + 26, x0 + 26 + 150, SLOT[0] + 46, 8, ACCENT + (ba,))
        # content lines draw in after landing
        rows = [(190, 90), (255, 118), (300, 140), (210, 164)]
        for j, (wd, ry) in enumerate(((290, 78), (250, 104), (280, 128))):
            k = seg(t, 0.56 + j * 0.06, 0.66 + j * 0.06)
            if k > 0:
                rrect(d, x0 + 26, SLOT[0] + ry, x0 + 26 + wd * k, SLOT[0] + ry + 12, 6, (160, 172, 205, ba))

    # soft flash when it snaps into place
    pulse = math.sin(math.pi * seg(t, 0.46, 0.60))
    if pulse > 0.03:
        glow = Image.new('RGBA', base.size, (0, 0, 0, 0))
        gd = ImageDraw.Draw(glow)
        gd.rounded_rectangle(((X0 - 6) * SS, (SLOT[0] - 6) * SS, (X1 + 6) * SS, (SLOT[1] + 6) * SS), radius=22 * SS, outline=(255, 255, 255, int(150 * pulse * fade)), width=5 * SS)
        layer = Image.alpha_composite(layer, glow)

    if bg:  # keep everything inside the rounded icon shape
        layer.putalpha(ImageChops.multiply(layer.split()[3], mask))
    out = Image.alpha_composite(base, layer)
    return out.resize((S, S), Image.LANCZOS)


def save_gif(path, size, nframes=48, ms=40):
    pal = []
    for i in range(nframes):
        f = frame(i / nframes).resize((size, size), Image.LANCZOS).convert('RGBA')
        a = f.split()[3]
        q = f.convert('RGB').quantize(255, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
        q.paste(255, mask=a.point(lambda v: 255 if v < 128 else 0))
        pal.append(q)
    durations = [ms] * nframes
    durations[int(nframes * 0.86)] = 1500  # hold the finished mark
    pal[0].save(path, save_all=True, append_images=pal[1:], duration=durations, loop=0, transparency=255, disposal=2, optimize=False)


if __name__ == '__main__':
    fin = frame(0.86)
    for n in (128, 256, 512):
        fin.resize((n, n), Image.LANCZOS).save(os.path.join(OUT, f'icon-static-{n}x{n}.png'))
    save_gif(os.path.join(OUT, 'icon-128x128.gif'), 128)
    save_gif(os.path.join(OUT, 'icon-256x256.gif'), 256)
    save_gif(os.path.join(OUT, 'logo-animation.gif'), 512)
    print('done')
