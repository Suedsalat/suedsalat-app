import sys, contextlib, io; sys.path.insert(0, '.')
from PIL import Image
from satz import rendern
from messen import vermesse
def segs(img):
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    return L, top, base, cap
# Ue-Punkte
img = rendern('Suedsalat-Bold.ttf', 'Ü', 141); px = img.load()
d = lambda x, y: sum(px[x, y][:3]) < 270
zeilen = [y for y in range(img.size[1]) if any(d(x, y) for x in range(img.size[0]))]
luecke = next(y for y in range(zeilen[0], zeilen[-1]) if y not in zeilen)
top = next(y for y in zeilen if y > luecke); base = zeilen[-1]; cap = base - top + 1
pts = [(x, y) for x in range(img.size[0]) for y in range(zeilen[0], luecke) if d(x, y)]
mx = (min(p[0] for p in pts) + max(p[0] for p in pts)) / 2
for n, grp in (('links', [p for p in pts if p[0] < mx]), ('rechts', [p for p in pts if p[0] >= mx])):
    gx = [p[0] for p in grp]; gy = [p[1] for p in grp]
    print(f'Ü-Punkt {n}: Durchmesser {100*(max(gx)-min(gx)+1)/cap:.1f}% (Logo 18.4), Luft zum Buchstaben {100*(top-max(gy)-1)/cap:.1f}% (Logo 7.8), Mitte {(max(gx)+min(gx))/2:.1f}')
# Unterzeile
logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB').crop((0, 880, 1057, 1000))
Ll, _, _, capl = segs(logo); Ln, _, _, capn = segs(rendern('Suedsalat-Regular.ttf', 'THEMEN AUS DEM LEBEN', 56))
print('Unterzeile Breiten (% Versal):', 'Buchstaben', ''.join('THEMENAUSDEMLEBEN'))
print(' Logo', [round(100*(b-a+1)/capl) for a, b in Ll])
print(' Neu ', [round(100*(b-a+1)/capn) for a, b in Ln])
