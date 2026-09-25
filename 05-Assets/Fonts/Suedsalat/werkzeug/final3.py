# Endgueltiger Bau "Suedsalat": Logo-Masse, spitze Ecken, runde Punkte, SUEDSALAT Buchstabe fuer
# Buchstabe wie im Logo, danach automatische Luft fuer Paare, die sich zu nahe kommen.
import sys, contextlib, io; sys.path.insert(0, '.')
from bauen import baue, la_paare, A_VARIANTEN
from kollision import pruefe
from satz import rendern, versal
from messen import vermesse
from fontTools.ttLib import TTFont

CAP = 742
LOGO_GAPS = [11.3, 16.3, 3.5, 2.1, 8.5, 5.7]            # SÜDSALAT, % Versalhoehe (L-A inkl. deinem Abstand)
# Segment-Paare wie gemessen: S|Ü, Ü|D, D|S, S|A, A|L, L|A(T)
PAARE = [('S', ['Udieresis']), ('Udieresis', ['D']), ('D', ['S']), ('S', A_VARIANTEN), (A_VARIANTEN, ['L']), ('L', A_VARIANTEN)]
FORM_FETT = {'dieresis': (round(0.184 * CAP), round(0.238 * CAP)), 'umlaut_abstand': round(0.078 * CAP),
             # Buchstabenbreiten wie im Logo: Einheiten, unberuehrte Mitte, gleichmaessig strecken?
             'breiten': {'S': (35, 0, True), 'U': (-33, 0, False), 'D': (25, 0, False), 'A': (10, 0, False),
                         'L': (-18, 0, False), 'T': (-28, 90, False)}}
MIN = 3.0

def gaps(path):
    img = rendern(path, 'SÜDSALAT', 564)
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    return [round(100 * (L[i + 1][0] - L[i][1] - 1) / cap, 1) for i in range(len(L) - 1)], (L[-1][1] - L[0][0] + 1) / cap

def als_liste(x):
    return x if isinstance(x, list) else [x]

kern = {}
def setze(links, rechts, delta):
    for a in als_liste(links):
        for b in als_liste(rechts):
            kern[(a, b)] = kern.get((a, b), 0) + delta

LOGO_BREITEN = [80.1, 70.2, 82.3, 80.1, 88.7, 62.4, 151.8]   # S Ü D S A L AT, % Versalhoehe
STAERKE, HOEHE = 668, 14                                      # Staemme / waagerechte Striche wie im Logo
breiten = {'S': [0, 0, True], 'U': [0, 0, False], 'D': [0, 0, False], 'A': [0, 0, False],
           'L': [0, 0, False], 'T': [0, 90, False]}

def segmente(path):
    img = rendern(path, 'SÜDSALAT', 564)
    with contextlib.redirect_stdout(io.StringIO()):
        L, top, base, cap = vermesse(img, 0, img.size[1], '')
    return [100 * (b - a + 1) / cap for a, b in L]

def form():
    f = dict(FORM_FETT); f['fett_vertikal'] = HOEHE
    f['breiten'] = {k: tuple(v) for k, v in breiten.items()}
    return f

setze('L', A_VARIANTEN, 26)
for runde in range(12):
    baue('Bold', STAERKE, 0.95, -30, 0, dict(kern), 700, 'Suedsalat-Bold.ttf', form())
    w = segmente('Suedsalat-Bold.ttf')
    dw = [l - x for l, x in zip(LOGO_BREITEN, w)]
    g, breite_gesamt = gaps('Suedsalat-Bold.ttf')
    diff = [l - x for l, x in zip(LOGO_GAPS, g)]
    print(f'Runde {runde + 1}: Breiten {[round(x, 1) for x in w]} | Abstaende {g} | Gesamt {breite_gesamt:.2f}')
    if all(abs(d) <= 0.4 for d in dw) and all(abs(d) <= 0.4 for d in diff):
        break
    u = lambda d: round(0.7 * d / 100 * CAP)   # gedaempft, damit es nicht pendelt
    breiten['S'][0] += u((dw[0] + dw[3]) / 2); breiten['U'][0] += u(dw[1]); breiten['D'][0] += u(dw[2])
    breiten['A'][0] += u(dw[4]); breiten['L'][0] += u(dw[5]); breiten['T'][0] += u(dw[6] - dw[4])
    for (links, rechts), d in zip(PAARE, diff):
        if abs(d) > 0.4:
            setze(links, rechts, round(0.7 * d / 100 * CAP))
form_fertig = form()

# Kollisionen
for runde in range(3):
    f = dict(form_fertig)
    baue('Bold', STAERKE, 0.95, -30, 0, dict(kern), 700, 'Suedsalat-Bold.ttf', f)
    eng = [(g, p) for g, p in pruefe('Suedsalat-Bold.ttf') if g < MIN]
    if not eng:
        break
    font = TTFont('Suedsalat-Bold.ttf'); cmap = font.getBestCmap()
    dazu = {}
    for g, (a, b) in eng:
        mehr = round((MIN - g + 0.2) / 100 * CAP)
        dazu[(cmap[ord(a)], cmap[ord(b)])] = max(dazu.get((cmap[ord(a)], cmap[ord(b)]), 0), mehr)
    for k, v in dazu.items():
        kern[k] = kern.get(k, 0) + v
    print('mehr Luft:', len(eng), 'Paare:', ', '.join(a + b for _, (a, b) in eng[:40]))
print('Fett: Ecken geschaerft', f['ecken'], '| Punkte rund', f['punkte'])

fr = {'breiten': {'S': (67, 0, True), 'T': (-30, 45, False), 'D': (22, 0, False)}}
kern_normal = {**{(a, u): -25 for a in A_VARIANTEN for u in ('U', 'Udieresis')}, ('U', 'S'): -24, ('Udieresis', 'S'): -24}
baue('Regular', 400, 1.0, -12, 75, kern_normal, 400, 'Suedsalat-Regular.ttf', fr)
print('Normal: Ecken geschaerft', fr['ecken'], '| Punkte rund', fr['punkte'])
g, b = gaps('Suedsalat-Bold.ttf'); print('Ergebnis SÜDSALAT:', g, f'Breite {b:.2f}', '\nLogo:              ', LOGO_GAPS, 'Breite 6.63')

# --- Unterzeile: Abstaende so optimieren, dass die Deckung mit dem Logo am groessten ist ---------
from optimieren import optimiere_unterzeile
dazu = optimiere_unterzeile('Suedsalat-Regular.ttf')
# Wortabstaende nicht je Buchstabe davor unterschiedlich (wuerde normalen Text unruhig machen):
# statt der Paare "Buchstabe -> Leerzeichen" ein einheitlich breiteres/schmaleres Leerzeichen.
leer = [v for (a, b), v in dazu.items() if b == 'space']
leer_mittel = round(sum(leer) / 3) if leer else 0     # drei Wortabstaende in der Unterzeile
dazu = {k: v for k, v in dazu.items() if k[1] != 'space'}
for k, v in dazu.items():
    kern_normal[k] = kern_normal.get(k, 0) + v
baue('Regular', 400, 1.0, -12, 75 + leer_mittel, dict(kern_normal), 400, 'Suedsalat-Regular.ttf', dict(fr))
print('Leerzeichen einheitlich:', 75 + leer_mittel)
print('Unterzeile Zusatz-Kerning:', dazu)

# Normal: ebenfalls keine Paare unter MIN
for runde in range(3):
    eng = [(g, p) for g, p in pruefe('Suedsalat-Regular.ttf') if g < MIN]
    if not eng:
        break
    font = TTFont('Suedsalat-Regular.ttf'); cmap = font.getBestCmap()
    for g, (a, b) in eng:
        k = (cmap[ord(a)], cmap[ord(b)])
        kern_normal[k] = kern_normal.get(k, 0) + round((MIN - g + 0.2) / 100 * CAP)
    print('Normal mehr Luft:', ', '.join(a + b for _, (a, b) in eng))
    baue('Regular', 400, 1.0, -12, 75 + leer_mittel, dict(kern_normal), 400, 'Suedsalat-Regular.ttf', dict(fr))
