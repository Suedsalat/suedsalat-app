# Richtung des aeusseren Bogens direkt an den S-Ecken (Logo) und Lage der Ecken im S
import math
from PIL import Image
def rand_x(img, ys, links):
    px = img.convert('L').load(); out = []
    for y in ys:
        xs = [x for x in range(img.size[0]) if px[x, y] < 110]
        if not xs: continue
        # Teilpixel ueber Grauwert am Rand
        x = xs[0] if links else xs[-1]
        nb = x - 1 if links else x + 1
        a, b = px[x, y], px[nb, y]
        t = (110 - a) / (b - a) if b != a else 0.5
        out.append((x - t if links else x + t, y))
    return out
def richtung(pts):
    n = len(pts); my = sum(p[1] for p in pts) / n; mx = sum(p[0] for p in pts) / n
    m = sum((p[1] - my) * (p[0] - mx) for p in pts) / sum((p[1] - my) ** 2 for p in pts)   # dx/dy
    return math.degrees(math.atan2(1, m))   # Winkel zur Waagerechten
def ecken(img, x0, x1, top, base):
    s = img.crop((x0 - 3, top - 8, x1 + 3, base + 8)); w, h = s.size
    px = s.convert('L').load()
    links = [min((x for x in range(w) if px[x, y] < 110), default=None) for y in range(h)]
    rechts = [max((x for x in range(w) if px[x, y] < 110), default=None) for y in range(h)]
    # unteres Ende: tiefste Zeile mit dem kleinsten linken Rand im unteren Drittel = Ecke
    yl = min(range(int(h * 0.55), int(h * 0.9)), key=lambda y: (links[y] if links[y] is not None else 999, -y))
    yr = min(range(int(h * 0.1), int(h * 0.45)), key=lambda y: (-(rechts[y] if rechts[y] is not None else -999), y))
    unten = rand_x(s, range(yl + 2, yl + 10), True)
    oben = rand_x(s, range(yr - 9, yr - 1), False)
    return (yl, links[yl]), (yr, rechts[yr]), richtung(unten), richtung(oben), (w, h)
logo = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB')
(yl, xl), (yr, xr), ru, ro, (w, h) = ecken(logo, 61, 173, 697, 837)
print(f'Logo: Ecke unten bei {100*yl/h:.1f}% Hoehe, Bogenrichtung davor {ru:.1f}°; Ecke oben bei {100*yr/h:.1f}% Hoehe, Bogenrichtung davor {ro:.1f}°')
