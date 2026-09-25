import sys, contextlib, io; sys.path.insert(0, '.')
from PIL import Image
from satz import rendern
from messen import vermesse
def segs(img):
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    return [round(100 * (b - a + 1) / cap, 1) for a, b in L], cap
logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB').crop((0, 640, 1057, 860))
lw, _ = segs(logo); ow, _ = segs(rendern('Suedsalat-Bold.ttf', 'SÜDSALAT', 141))
print('Segmente  S     Ü     D     S     A     L     AT')
print('Logo  ', lw); print('Neu   ', ow)
