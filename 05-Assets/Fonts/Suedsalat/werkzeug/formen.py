# Formkorrekturen fuer "Suedsalat": spitze Ecken und runde Punkte (wie im Logo).
import math
from fontTools.ttLib.tables import ttProgram, otTables
from fontTools.ttLib.tables._g_l_y_f import GlyphCoordinates


def _konturen(g):
    coords, ends, flags = list(g.coordinates), g.endPtsOfContours, list(g.flags)
    out, s = [], 0
    for e in ends:
        out.append([(coords[i][0], coords[i][1], flags[i] & 1) for i in range(s, e + 1)])
        s = e + 1
    return out


def _setze(g, konturen, glyf):
    coords, flags, ends = [], [], []
    for k in konturen:
        for x, y, on in k:
            coords.append((x, y)); flags.append(1 if on else 0)
        ends.append(len(coords) - 1)
    g.coordinates = GlyphCoordinates(coords); g.flags = bytearray(flags); g.endPtsOfContours = ends
    g.numberOfContours = len(ends)
    g.program = ttProgram.Program(); g.program.fromBytecode(b'')
    g.recalcBounds(glyf)


def _kollinear(a, b, c, tol=0.03):
    """b liegt auf der Verlaengerung von a->b in Richtung c (gleiche Richtung, fast gerade)."""
    v1 = (b[0] - a[0], b[1] - a[1]); v2 = (c[0] - b[0], c[1] - b[1])
    l1, l2 = math.hypot(*v1), math.hypot(*v2)
    if l1 < 1e-6 or l2 < 1e-6:
        return False
    kreuz = (v1[0] * v2[1] - v1[1] * v2[0]) / (l1 * l2)
    punkt = (v1[0] * v2[0] + v1[1] * v2[1]) / (l1 * l2)
    return abs(kreuz) < tol and punkt > 0


def _schnitt(p0, p1, p2, p3):
    """Schnittpunkt der Geraden p0-p1 und p2-p3, oder None bei (fast) parallelen Linien."""
    d1 = (p1[0] - p0[0], p1[1] - p0[1]); d2 = (p3[0] - p2[0], p3[1] - p2[1])
    nenner = d1[0] * d2[1] - d1[1] * d2[0]
    l1, l2 = math.hypot(*d1), math.hypot(*d2)
    if l1 < 1e-6 or l2 < 1e-6 or abs(nenner) / (l1 * l2) < math.sin(math.radians(20)):
        return None
    t = ((p2[0] - p0[0]) * d2[1] - (p2[1] - p0[1]) * d2[0]) / nenner
    return (p0[0] + t * d1[0], p0[1] + t * d1[1])


def ecken_schaerfen(font, max_sehne=45):
    """Abgerundete Ecken (gerade Linie - kleiner Bogen aus 1-2 Hilfspunkten - gerade Linie)
    durch die spitze Ecke ersetzen: den Schnittpunkt der beiden Linien."""
    glyf = font['glyf']; anzahl = 0
    for name in font.getGlyphOrder():
        g = glyf[name]
        if g.isComposite() or g.numberOfContours <= 0:
            continue
        neu, geaendert = [], False
        for k in _konturen(g):
            n = len(k)
            if n < 4:
                neu.append(k); continue
            weg, ersetze = set(), {}
            for i in range(n):
                p1 = k[i]
                if not p1[2]:
                    continue
                offs = []
                j = (i + 1) % n
                while not k[j][2] and len(offs) < 3:
                    offs.append(j); j = (j + 1) % n
                if not 1 <= len(offs) <= 2:
                    continue
                p2 = k[j]; p0 = k[(i - 1) % n]; p3 = k[(j + 1) % n]
                if not (p0[2] and p3[2]) or math.hypot(p1[0] - p2[0], p1[1] - p2[1]) > max_sehne:
                    continue
                x = _schnitt(p0, p1, p2, p3)
                if x is None:
                    continue
                sehne = math.hypot(p1[0] - p2[0], p1[1] - p2[1])
                if max(math.hypot(x[0] - q[0], x[1] - q[1]) for q in (p1, p2)) > 1.5 * sehne + 2:
                    continue
                gruppe = {i, j, *offs}
                if gruppe & (weg | set(ersetze)):
                    continue
                weg |= gruppe - {offs[0]}
                ersetze[offs[0]] = (round(x[0]), round(x[1]), 1)
            if ersetze:
                geaendert = True; anzahl += len(ersetze)
                k = [ersetze.get(i, p) for i, p in enumerate(k) if i not in weg]
            neu.append(k)
        if geaendert:
            _setze(g, neu, glyf)
    return anzahl


def _flaeche(k):
    return sum(k[i][0] * k[(i + 1) % len(k)][1] - k[(i + 1) % len(k)][0] * k[i][1] for i in range(len(k))) / 2


def _kreis(cx, cy, r, orientierung):
    """Kreis aus 8 Kurven-Hilfspunkten (TrueType), Umlaufrichtung wie die ersetzte Kontur."""
    rr = r / math.cos(math.pi / 8)
    winkel = [math.pi / 8 + k * math.pi / 4 for k in range(8)]
    if orientierung < 0:
        winkel = winkel[::-1]
    return [(round(cx + rr * math.cos(w)), round(cy + rr * math.sin(w)), 0) for w in winkel]


def ist_punkt(k, max_groesse=260):
    """Punkt in Libre Franklin: kleines, fast quadratisches Stueck mit vier geraden Seiten und
    abgerundeten Ecken (12-14 Punkte). Ovale (z. B. im %-Zeichen) haben keine geraden Seiten."""
    if not 8 <= len(k) <= 20:
        return False
    xs = [p[0] for p in k]; ys = [p[1] for p in k]
    w, h = max(xs) - min(xs), max(ys) - min(ys)
    if not (0 < w < max_groesse and 0 < h < max_groesse and abs(w - h) / max(w, h) < 0.35):
        return False
    gerade = sum(1 for i in range(len(k)) if k[i][2] and k[(i + 1) % len(k)][2]
                 and max(abs(k[i][0] - k[(i + 1) % len(k)][0]), abs(k[i][1] - k[(i + 1) % len(k)][1])) > 0.3 * min(w, h))
    return gerade >= 4


GERUNDET = []


def punkte_rund(font, durchmesser_faktor=1.0, dieresis=None):
    """Alle Punkte durch Kreise ersetzen (gleiche Mitte). dieresis = (durchmesser, mittenabstand)
    fuer die Umlautpunkte (uni0308) wie im Logo."""
    glyf = font['glyf']; anzahl = 0
    for name in font.getGlyphOrder():
        g = glyf[name]
        if g.isComposite() or g.numberOfContours <= 0 or 'ring' in name.lower():
            continue
        konturen = _konturen(g); neu = []; geaendert = False
        punkte = [k for k in konturen if ist_punkt(k)]
        for k in konturen:
            if k in punkte:
                xs = [p[0] for p in k]; ys = [p[1] for p in k]
                cx, cy = (min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2
                # Kreis mit derselben Flaeche wie das (evtl. hochkante) Rechteck
                d = math.sqrt((max(xs) - min(xs)) * (max(ys) - min(ys))) * durchmesser_faktor
                if name == 'uni0308' and dieresis and len(punkte) == 2:
                    d, abstand = dieresis
                    mitte = sum((min(p[0] for p in q) + max(p[0] for p in q)) / 2 for q in punkte) / 2
                    cx = mitte + (abstand / 2 if cx > mitte else -abstand / 2)
                neu.append(_kreis(cx, cy, d / 2, _flaeche(k))); geaendert = True; anzahl += 1
                GERUNDET.append(name)
            else:
                neu.append(k)
        if geaendert:
            _setze(g, neu, glyf)
    return anzahl


def umlaute_hoehe(font, abstand_ueber_versal):
    """Grossbuchstaben-Umlaute: Punkte mit festem Abstand ueber der Versalhoehe (wie im Logo)."""
    glyf = font['glyf']
    glyf['uni0308'].recalcBounds(glyf); unten = glyf['uni0308'].yMin
    cap = glyf['H'].yMax if hasattr(glyf['H'], 'yMax') else 742
    for name in ('Adieresis', 'Edieresis', 'Idieresis', 'Odieresis', 'Udieresis', 'Wdieresis', 'Ydieresis'):
        if name in glyf.glyphs and glyf[name].isComposite():
            for c in glyf[name].components:
                if c.glyphName == 'uni0308':
                    c.y = round(cap + abstand_ueber_versal - unten)
            glyf[name].recalcBounds(glyf)


def enthinten(font):
    for tag in ('fpgm', 'prep', 'cvt ', 'hdmx', 'LTSH', 'VDMX'):
        if tag in font:
            del font[tag]
    for name in font.getGlyphOrder():
        g = font['glyf'][name]
        if hasattr(g, 'program'):
            g.program = ttProgram.Program(); g.program.fromBytecode(b'')
    if 'GPOS' in font:
        def walk(obj, seen):
            if id(obj) in seen:
                return
            seen.add(id(obj))
            if isinstance(obj, otTables.Anchor) and getattr(obj, 'Format', 1) == 2:
                obj.Format = 1
                if hasattr(obj, 'AnchorPoint'):
                    del obj.AnchorPoint
            if isinstance(obj, list):
                for x in obj:
                    walk(x, seen)
            elif hasattr(obj, '__dict__'):
                for x in list(vars(obj).values()):
                    walk(x, seen)
        walk(font['GPOS'].table, set())


def breite(font, name, delta, mitte=0, skalieren=False):
    """Buchstaben um delta Einheiten breiter/schmaler machen, Strichstaerke bleibt:
    Punkte links der Mitte wandern um -delta/2, rechts um +delta/2 (mitte = unberuehrte Zone
    +-mitte um die Mitte, z. B. der T-Stamm). skalieren=True streckt stattdessen gleichmaessig."""
    glyf, hmtx = font['glyf'], font['hmtx']
    g = glyf[name]; g.recalcBounds(glyf)
    c = (g.xMin + g.xMax) / 2; w = g.xMax - g.xMin
    konturen = _konturen(g); neu = []
    for k in konturen:
        nk = []
        for x, y, on in k:
            if skalieren:
                x = c + (x - c) * (w + delta) / w
            elif x > c + mitte:
                x += delta / 2
            elif x < c - mitte:
                x -= delta / 2
            nk.append((round(x), y, on))
        neu.append(nk)
    _setze(g, neu, glyf)
    adv, _ = hmtx[name]
    hmtx[name] = (adv + delta, g.xMin)
    # alles 0.5*delta nach rechts, damit die linke Seitenluft gleich bleibt
    k2 = [[(x + round(delta / 2), y, on) for x, y, on in k] for k in _konturen(g)]
    _setze(g, k2, glyf); hmtx[name] = (adv + delta, g.xMin)
    # zusammengesetzte Buchstaben mit diesem Grundbuchstaben (z. B. Ü aus U + Punkte) mitziehen
    for other in font.getGlyphOrder():
        o = glyf[other]
        if o.isComposite() and o.components[0].glyphName == name and o.components[0].x == 0:
            for comp in o.components[1:]:
                comp.x += round(delta / 2)
            oadv, _ = hmtx[other]; hmtx[other] = (oadv + delta, hmtx[other][1])
            o.recalcBounds(glyf)
