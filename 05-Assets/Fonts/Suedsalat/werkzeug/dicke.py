# Strichdicken und Tintenmenge von SUEDSALAT: Logo gegen Suedsalat Bold (gleiche Versalhoehe 141 px)
import sys, contextlib, io; sys.path.insert(0, '.')
from PIL import Image
from satz import rendern
from messen import vermesse

def analyse(img):
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    px = img.convert('L').load()
    dunkel = lambda x, y: px[x, y] < 110
    def senkrecht(x):   # dunkle Laeufe in Spalte x zwischen top-5 und base+5
        runs, y = [], top - 5
        while y <= base + 5:
            if dunkel(x, y):
                s = y
                while y <= base + 5 and dunkel(x, y): y += 1
                runs.append(y - s)
            y += 1
        return runs
    def waagerecht(y, x0, x1):
        runs, x = [], x0
        while x <= x1:
            if dunkel(x, y):
                s = x
                while x <= x1 and dunkel(x, y): x += 1
                runs.append(x - s)
            x += 1
        return runs
    S, U, D, S2, A, Lb, AT = L
    mid = (top + base) // 2
    pct = lambda v: round(100 * v / cap, 1)
    return {
        'L senkrecht (Stamm)': pct(waagerecht(mid, *Lb)[0]),
        'L waagerecht (Fuss)': pct(senkrecht(Lb[1] - 8)[-1]),
        'T-Balken': pct(senkrecht(AT[1] - 10)[0]),
        'D oben (Bogen)': pct(senkrecht((D[0] + D[1]) // 2)[0]),
        'S oben (Bogen)': pct(senkrecht((S[0] + S[1]) // 2)[0]),
        'U unten': pct(senkrecht((U[0] + U[1]) // 2)[-1]),
        'D senkrecht': pct(waagerecht(mid, *D)[0]),
        'Tinte gesamt (% Flaeche)': round(100 * sum(1 for x in range(L[0][0], L[-1][1]) for y in range(top, base + 1) if dunkel(x, y)) / ((L[-1][1] - L[0][0]) * (base - top + 1)), 1),
    }

logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB').crop((0, 690, 1057, 860))
a = analyse(logo); b = analyse(rendern('Suedsalat-Bold.ttf', 'SÜDSALAT', 141))
print(f"{'':28} Logo   Suedsalat")
for k in a: print(f'{k:28} {a[k]:5}  {b[k]:5}')
