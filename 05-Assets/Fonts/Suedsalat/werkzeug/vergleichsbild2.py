import sys; sys.path.insert(0, '.')
from PIL import Image, ImageDraw, ImageFont
from satz import rendern

logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB')
gruen = logo.getpixel((20, 20))
ink = lambda im: Image.eval(im.convert('L'), lambda v: 255 if v < 110 else 0)

def zeile(y0, y1):
    return logo.crop((0, y0, logo.size[0], y1))

def box(maske):
    return maske.getbbox()

# Original-Zeilen (Maske) und neue Zeilen, jeweils auf gleiche Versalhoehe gerendert
teile = [((640, 860), 'Suedsalat-Bold.ttf', 'SÜDSALAT', 141), ((880, 1000), 'Suedsalat-Regular.ttf', 'THEMEN AUS DEM LEBEN', 56)]
orig_masken, neu_masken = [], []
for (y0, y1), f, text, cap in teile:
    m = ink(zeile(y0, y1)); orig_masken.append(m.crop(box(m)))
    n = ink(rendern(f, text, cap)); neu_masken.append(n.crop(box(n)))

W = 1000; pad = 30
def panel(masken, farbe_text, hintergrund):
    h = sum(m.size[1] for m in masken) + pad * 3
    p = Image.new('RGB', (W, h), hintergrund); y = pad
    for m in masken:
        p.paste(Image.new('RGB', m.size, farbe_text), ((W - m.size[0]) // 2, y), m); y += m.size[1] + pad
    return p

DECKUNG = []


def ueberlagert():
    h = sum(max(a.size[1], b.size[1]) for a, b in zip(orig_masken, neu_masken)) + pad * 3
    p = Image.new('RGB', (W, h), (255, 255, 255)); y = pad
    for a, b in zip(orig_masken, neu_masken):
        hh = max(a.size[1], b.size[1]); x = (W - max(a.size[0], b.size[0])) // 2
        A = Image.new('L', (W, hh)); A.paste(a, (x, hh - a.size[1]))   # an der Grundlinie ausrichten
        # beste Deckung: neue Zeile waagerecht so verschieben, dass am meisten uebereinanderliegt
        from PIL import ImageChops
        def deckung(dx):
            B = Image.new('L', (W, hh)); B.paste(b, (x + dx, hh - b.size[1]))
            beide = ImageChops.multiply(A, B).histogram()[255]
            eines = ImageChops.lighter(A, B).histogram()[255]
            return beide / eines, B
        dx = max(range(-12, 13), key=lambda d: deckung(d)[0])
        wert, B = deckung(dx)
        DECKUNG.append(round(100 * wert, 1))
        pa, pb = A.load(), B.load(); px = p.load()
        for yy in range(hh):
            for xx in range(W):
                o, n = pa[xx, yy] > 0, pb[xx, yy] > 0
                if o and n: px[xx, y + yy] = (20, 20, 20)
                elif o: px[xx, y + yy] = (220, 40, 40)
                elif n: px[xx, y + yy] = (40, 90, 230)
        y += hh + pad
    return p

bilder = [('1  Original-Logo (Franklin Gothic, L-A-Abstand von Hand)', panel(orig_masken, (16, 32, 36), gruen)),
          ('2  Neue Hausschrift „Suedsalat“ (Fett + Normal, L-A-Abstand fest eingebaut)', panel(neu_masken, (16, 32, 36), gruen)),
          ('3  Übereinander: schwarz = deckungsgleich, rot = nur Original, blau = nur neu', ueberlagert())]
schrift = ImageFont.truetype('Suedsalat-Regular.ttf', 28)
gesamt = Image.new('RGB', (W + 40, sum(b.size[1] + 60 for _, b in bilder) + 20), 'white')
d = ImageDraw.Draw(gesamt); y = 15
for titel, b in bilder:
    d.text((20, y), titel, fill=(50, 50, 50), font=schrift); y += 45
    gesamt.paste(b, (20, y)); y += b.size[1] + 15
gesamt.save('U:/Suedsalat-Schrift-Vergleich.png'); gesamt.save('vergleich2.png')
print('gespeichert', gesamt.size, '| Deckung (Anteil gemeinsamer Flaeche):', DECKUNG)
