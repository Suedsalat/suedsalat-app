# Vermisst Buchstaben in einem Bild: Versalhoehe, Strichstaerke, Buchstabenabstaende.
from PIL import Image

def dunkel(px):
    r, g, b = px[:3]
    return r < 90 and g < 90 and b < 90

def vermesse(img, y0, y1, name):
    w, h = img.size
    px = img.load()
    rows = [y for y in range(y0, y1) if any(dunkel(px[x, y]) for x in range(w))]
    # Buchstaben = zusammenhaengende Spalten mit dunklen Pixeln (im Hauptbereich ohne Umlautpunkte)
    top, bot = rows[0], rows[-1]
    # Versalhoehe: Zeilen, in denen viele Spalten dunkel sind (ohne Punkte oben)
    counts = {y: sum(dunkel(px[x, y]) for x in range(w)) for y in rows}
    body = [y for y in rows if counts[y] > 0.15 * max(counts.values())]
    capTop, base = body[0], body[-1]
    cap = base - capTop + 1
    cols = [any(dunkel(px[x, y]) for y in range(capTop, base + 1)) for x in range(w)]
    letters, x = [], 0
    while x < w:
        if cols[x]:
            s = x
            while x < w and cols[x]:
                x += 1
            letters.append((s, x - 1))
        x += 1
    gaps = [letters[i + 1][0] - letters[i][1] - 1 for i in range(len(letters) - 1)]
    breite = letters[-1][1] - letters[0][0] + 1
    print(f"{name}: Versalhoehe {cap}px, Breite {breite}px (= {breite / cap:.2f} x Versalhoehe), {len(letters)} Buchstaben")
    print("  Abstaende (in % der Versalhoehe):", [round(100 * g / cap, 1) for g in gaps])
    return letters, capTop, base, cap

def stamm(img, x_start, y, cap):
    px = img.load()
    x = x_start
    while not dunkel(px[x, y]):
        x += 1
    s = x
    while dunkel(px[x, y]):
        x += 1
    return round(100 * (x - s) / cap, 1)

if __name__ == '__main__':
    img = Image.open('U:/Logo/Suedsalat_Logo.png').convert('RGB')
    L, top, base, cap = vermesse(img, 640, 860, 'SÜDSALAT')
    mid = (top + base) // 2
    print('  Segmente:', L); print('  Strich L senkrecht (% Versalhoehe):', stamm(img, L[5][0], mid, cap))
    L2, top2, base2, cap2 = vermesse(img, 880, 1000, 'THEMEN AUS DEM LEBEN')
    print('  Strich T senkrecht (% Versalhoehe):', stamm(img, L2[0][0] + (L2[0][1]-L2[0][0])//3, (top2+base2)//2, cap2), '| H links:', stamm(img, L2[1][0], (top2+base2)//2, cap2))
