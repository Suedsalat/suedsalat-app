import sys, contextlib, io; sys.path.insert(0, '.')
from satz import rendern, versal
from messen import vermesse
from fontTools.ttLib import TTFont
def m(path, text, cap_px):
    img = rendern(path, text, cap_px)
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    return (L[-1][1] - L[0][0] + 1) / cap, [round(100 * (L[i+1][0] - L[i][1] - 1) / cap, 1) for i in range(len(L) - 1)]
r, g = m('Suedsalat-Bold.ttf', 'SÜDSALAT', 141); print(f'Fett:   Breite {r:.2f} (Logo 6.63)  Abstaende {g}\n        Logo:                 [11.3, 16.3, 3.5, 2.1, 8.5, 5.7]')
r, g = m('Suedsalat-Regular.ttf', 'THEMEN AUS DEM LEBEN', 56); print(f'Normal: Breite {r:.2f} (Logo 16.41) Abstaende {g}')
f = TTFont('Suedsalat-Bold.ttf'); print('Versalhoehe Einheiten:', versal(f), 'upem', f['head'].unitsPerEm)
