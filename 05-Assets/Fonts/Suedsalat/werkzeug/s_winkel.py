# Winkel der Schnittkanten an den S-Enden messen (Geradenanpassung ueber die Kantenpixel)
import math
from PIL import Image

def kante(img, xs, y_von, y_bis, richtung):
    """Fuer jede Spalte x: Uebergang Tinte -> Hintergrund. richtung +1 = von oben nach unten suchen
    (Unterkante des oberen Endes), -1 = von unten nach oben (Oberkante des unteren Endes)."""
    px = img.convert('L').load(); pts = []
    for x in xs:
        ys = range(y_von, y_bis) if richtung > 0 else range(y_bis, y_von, -1)
        in_tinte = False
        for y in ys:
            dunkel = px[x, y] < 110
            if dunkel:
                in_tinte = True
            elif in_tinte:
                # Teilpixel: Grauwert-Interpolation zwischen letzter Tinte und Hintergrund
                a, b = px[x, y - richtung], px[x, y]
                t = (110 - a) / (b - a) if b != a else 0.5
                pts.append((x, y - richtung + richtung * t)); break
    return pts

def gerade(pts):
    n = len(pts); mx = sum(p[0] for p in pts) / n; my = sum(p[1] for p in pts) / n
    sxx = sum((p[0] - mx) ** 2 for p in pts); sxy = sum((p[0] - mx) * (p[1] - my) for p in pts)
    m = sxy / sxx
    rest = max(abs(p[1] - (my + m * (p[0] - mx))) for p in pts)
    return math.degrees(math.atan(-m)), rest   # Bild-y zeigt nach unten -> Vorzeichen drehen

def s_enden(img, x0, x1, y_top, y_base):
    """S im Bereich x0..x1: oberes Ende rechts oben, unteres Ende links unten."""
    b = x1 - x0; h = y_base - y_top
    oben = kante(img, range(x0 + int(0.80 * b), x0 + int(0.95 * b)), y_top - 5, y_top + int(0.45 * h), +1)
    unten = kante(img, range(x0 + int(0.03 * b), x0 + int(0.20 * b)), y_top + int(0.55 * h), y_base + 5, -1)
    return gerade(oben), gerade(unten), oben, unten

if __name__ == '__main__':
    logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB')
    (wo, ro), (wu, ru), po, pu = s_enden(logo, 61, 173, 697, 837)
    print(f'Logo SÜDSALAT, erstes S: oberes Ende {wo:.1f}° (Abweichung max {ro:.2f} px, {len(po)} Punkte), unteres Ende {wu:.1f}° (max {ru:.2f} px, {len(pu)} Punkte)')
    (wo, ro), (wu, ru), po, pu = s_enden(logo, 433, 545, 697, 837)
    print(f'Logo SÜDSALAT, zweites S: oberes Ende {wo:.1f}°, unteres Ende {wu:.1f}°')
