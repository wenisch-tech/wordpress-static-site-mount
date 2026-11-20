#!/usr/bin/env python3
"""Draws the Static Site Mount logo: three pages drop onto a base plate ("mounted"), then the top page fills in.
Outputs static PNGs and animated GIFs into assets/. Needs Pillow.  Usage: python3 make_logo.py"""
import math, os
from PIL import Image, ImageDraw

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
S = 512            # design space
SS = 3             # supersampling
BG1, BG2 = (59, 108, 246), (28, 45, 128)   # warm blue -> deep indigo
WHITE = (255, 255, 255)

def ease_out_back(t):
    c1, c3 = 1.4, 2.4
    return 1 + c3 * (t - 1) ** 3 + c1 * (t - 1) ** 2

def clamp(x): return max(0.0, min(1.0, x))
def seg(t, a, b): return clamp((t - a) / (b - a))

def gradient():
    img = Image.new('RGB', (S * SS, S * SS))
    px = img.load()
    n = S * SS
    for y in range(n):
        for x in range(n):
            k = (x + y) / (2 * n)
            px[x, y] = tuple(int(BG1[i] + (BG2[i] - BG1[i]) * k) for i in range(3))
    return img
GRAD = gradient()

def rr(d, box, r, fill, alpha=255):
    x0, y0, x1, y1 = [v * SS for v in box]
    d.rounded_rectangle((x0, y0, x1, y1), radius=r * SS, fill=fill + (alpha,) if len(fill) == 3 else fill)

def frame(t, bg=True):
    """t in 0..1 over the loop."""
    base = Image.new('RGBA', (S * SS, S * SS), (0, 0, 0, 0))
    if bg:
        mask = Image.new('L', base.size, 0)
        ImageDraw.Draw(mask).rounded_rectangle((0, 0, S * SS - 1, S * SS - 1), radius=112 * SS, fill=255)
        base.paste(GRAD, (0, 0), mask)
    layer = Image.new('RGBA', base.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)

    fade = 1 - seg(t, 0.93, 1.0)          # fade out at loop end
    # glow pulse when the last page lands
    pulse = math.sin(math.pi * seg(t, 0.52, 0.72))
    if pulse > 0.02:
        rr(d, (96 - 8 * pulse, 378 - 4 * pulse, 416 + 8 * pulse, 404 + 4 * pulse), 18, (255, 255, 255), int(70 * pulse))
    # base plate (the mount)
    plate_a = int(255 * clamp(seg(t, 0.0, 0.10)) * fade)
    rr(d, (96, 378, 416, 404), 13, (255, 255, 255), int(plate_a * .95))
    # three pages, bottom to top, dropping in sequence
    pages = [  # final y, width inset, opacity
        (342, 56, 130),
        (318, 40, 200),
        (118, 0, 255),
    ]
    starts = [0.06, 0.20, 0.34]
    for i, (fy, inset, op) in enumerate(pages):
        p = seg(t, starts[i], starts[i] + 0.22)
        if p <= 0: continue
        e = ease_out_back(p)
        y_off = (1 - e) * -260
        a = int(op * clamp(p * 3) * fade)
        if i < 2:
            rr(d, (96 + inset, fy + y_off, 416 - inset, fy + 36 + y_off), 12, (255, 255, 255), a)
        else:
            top, bot = fy + y_off, fy + y_off + 204
            rr(d, (96, top, 416, bot), 22, (255, 255, 255), int(a * .97))
            # browser bar
            bar = (205, 214, 240, a)
            d.rounded_rectangle((96 * SS, top * SS, 416 * SS, (top + 44) * SS), radius=22 * SS, fill=bar)
            d.rectangle((96 * SS, (top + 30) * SS, 416 * SS, (top + 44) * SS), fill=bar)
            for k, c in enumerate(((255, 105, 97), (255, 200, 87), (96, 200, 120))):
                cx = 124 + k * 24
                d.ellipse(((cx - 7) * SS, (top + 15) * SS, (cx + 7) * SS, (top + 29) * SS), fill=c + (a,))
            # content lines draw in left to right
            lines = [(130, 380, 76, (59, 108, 246)), (130, 330, 108, (160, 172, 205)), (130, 360, 134, (160, 172, 205)), (130, 300, 160, (160, 172, 205))]
            for j, (x0, x1, dy, col) in enumerate(lines):
                q = seg(t, 0.56 + j * 0.06, 0.66 + j * 0.06)
                if q <= 0: continue
                w = 18 if j == 0 else 12
                d.rounded_rectangle((x0 * SS, (top + dy - w / 2) * SS, (x0 + (x1 - x0) * q) * SS, (top + dy + w / 2) * SS), radius=6 * SS, fill=col + (a,))
    out = Image.alpha_composite(base, layer)
    return out.resize((S, S), Image.LANCZOS)

def final_frame():
    return frame(0.86)

def save_gif(path, size, nframes=48, ms=40):
    frames = []
    for i in range(nframes):
        f = frame(i / nframes).resize((size, size), Image.LANCZOS).convert('RGBA')
        # flatten on solid corner colour-key: GIF has 1-bit alpha, so keep the rounded square and use transparency
        frames.append(f)
    pal = []
    for f in frames:
        a = f.split()[3]
        q = f.convert('RGB').quantize(255, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
        m = a.point(lambda v: 255 if v < 128 else 0)
        q.paste(255, mask=m)
        pal.append(q)
    durations = [ms] * nframes
    durations[int(nframes * 0.86)] = 1400      # hold the finished mark
    pal[0].save(path, save_all=True, append_images=pal[1:], duration=durations, loop=0, transparency=255, disposal=2, optimize=False)

if __name__ == '__main__':
    fin = final_frame()
    for n in (128, 256, 512):
        fin.resize((n, n), Image.LANCZOS).save(os.path.join(OUT, f'icon-static-{n}x{n}.png'))
    save_gif(os.path.join(OUT, 'icon-128x128.gif'), 128)
    save_gif(os.path.join(OUT, 'icon-256x256.gif'), 256)
    save_gif(os.path.join(OUT, 'logo-animation.gif'), 512)
    print('done')
