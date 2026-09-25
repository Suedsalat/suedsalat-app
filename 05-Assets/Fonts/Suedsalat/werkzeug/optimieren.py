# Unterzeile "THEMEN AUS DEM LEBEN": Abstaende je Buchstabenpaar so waehlen, dass die gerenderte
# Zeile moeglichst deckungsgleich mit dem Logo ist (4-fach gerendert, dann verkleinert).
import sys; sys.path.insert(0, '.')
from PIL import Image, ImageChops
from satz import rendern

TEXT = 'THEMEN AUS DEM LEBEN'
logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('L').crop((0, 880, 1057, 1000))
LOGO = Image.eval(logo, lambda v: 255 if v < 110 else 0)
LOGO = LOGO.crop(LOGO.getbbox())

def maske(path, extra):
    big = rendern(path, TEXT, 56 * 4, 0, {i: v for i, v in extra.items()})
    m = Image.eval(big.convert('L'), lambda v: 255 - v)
    m = m.crop(m.getbbox())
    m = m.resize((round(m.size[0] / 4), round(m.size[1] / 4)), Image.LANCZOS)
    return Image.eval(m, lambda v: 255 if v > 145 else 0)

def deckung(m):
    H = max(m.size[1], LOGO.size[1]); W = max(m.size[0], LOGO.size[0]) + 30
    A = Image.new('L', (W, H)); A.paste(LOGO, (15, H - LOGO.size[1]))
    best = 0
    for dx in range(-4, 5):
        B = Image.new('L', (W, H)); B.paste(m, (15 + dx, H - m.size[1]))
        beide = ImageChops.multiply(A, B).histogram()[255]; eines = ImageChops.lighter(A, B).histogram()[255]
        best = max(best, beide / eines)
    return best

def optimiere_unterzeile(path):
    # Luecken (Index des Buchstabens davor im Text) je Paar
    paare = {}
    for i in range(len(TEXT) - 1):
        a, b = TEXT[i], TEXT[i + 1]
        if a == ' ':
            continue
        key = (a, 'space') if b == ' ' else (a, b)
        paare.setdefault(key, []).append(i)
    namen = {' ': 'space'}
    werte = {k: 0 for k in paare}
    def extra():
        return {i: v for k, v in werte.items() for i in paare[k]}
    aktuell = deckung(maske(path, extra()))
    print(f'  Start-Deckung {100 * aktuell:.1f} %')
    for schritt in (16, 8, 4, 2):
        besser = True
        while besser:
            besser = False
            for k in werte:
                for d in (schritt, -schritt):
                    werte[k] += d
                    wert = deckung(maske(path, extra()))
                    if wert > aktuell + 1e-4:
                        aktuell = wert; besser = True
                        break
                    werte[k] -= d
        print(f'  Schritt {schritt}: Deckung {100 * aktuell:.1f} %')
    return {(a if a != ' ' else 'space', b): v for (a, b), v in werte.items() if v}
