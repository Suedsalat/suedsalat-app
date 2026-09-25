import sys; sys.path.insert(0, '.')
from PIL import Image, ImageDraw, ImageFont
from satz import rendern

logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB')
W, H = logo.size
gruen = logo.getpixel((20, 20)); tinte = (16, 32, 36)

def maske(im):  # Tinte als Deckkraft (0..255), aus Schwarz-auf-Weiss-Rendering
    # Rendering ist Dunkelgrau (Helligkeit ~28) auf Weiss: auf volle Deckung hochskalieren
    return Image.eval(im.convert('L'), lambda v: min(255, round((255 - v) * 255 / (255 - 28))))

def text_bbox(y0, y1):
    reg = logo.crop((0, y0, W, y1)).convert('L')
    b = Image.eval(reg, lambda v: 255 if v < 110 else 0).getbbox()
    return b[0], y0 + b[1], b[2], y0 + b[3]

neu = logo.copy()
zeilen = [((640, 860), 'Suedsalat-Bold.ttf', 'SÜDSALAT', 141), ((880, 1000), 'Suedsalat-Regular.ttf', 'THEMEN AUS DEM LEBEN', 56)]
for (y0, y1), f, text, cap in zeilen:
    x0, t0, x1, t1 = text_bbox(y0, y1)
    ImageDraw.Draw(neu).rectangle((x0 - 12, t0 - 12, x1 + 12, t1 + 12), fill=gruen)
    m = maske(rendern(f, text, cap)); m = m.crop(m.getbbox())
    # waagerecht mittig auf die alte Zeile, unten auf dieselbe Grundlinie
    x = round((x0 + x1) / 2 - m.size[0] / 2); y = t1 - m.size[1]
    neu.paste(Image.new('RGB', m.size, tinte), (x, y), m)

rand, kopf = 40, 90
bild = Image.new('RGB', (2 * W + 3 * rand, H + kopf + rand), 'white')
d = ImageDraw.Draw(bild); schrift = ImageFont.truetype('Suedsalat-Bold.ttf', 44)
for i, (titel, im) in enumerate([('Original (Franklin Gothic)', logo), ('Neu: Schrift Südsalat', neu)]):
    x = rand + i * (W + rand)
    d.text((x, 25), titel, fill=tinte, font=schrift)
    bild.paste(im, (x, kopf))
bild.save('U:/Suedsalat-Logo-Vergleich.png'); neu.save('U:/Suedsalat_Logo_neue_Schrift.png')
print('ok', bild.size)
