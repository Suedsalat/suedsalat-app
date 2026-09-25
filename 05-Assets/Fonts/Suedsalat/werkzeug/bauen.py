# Baut die Hausschrift "Suedsalat" (Fett + Normal) aus Libre Franklin (SIL OFL 1.1).
# Abgestimmt auf das Logo (U:/Logo/Suedsalat_Logo.png): Strichstaerke, Laufweite, L-A-Abstand.
import sys
from fontTools.ttLib import TTFont
from fontTools.ttLib.tables import otTables, otBase
from fontTools.otlLib.builder import buildPairPosGlyphsSubtable, buildValue, buildLookup
from fontTools.varLib.instancer import instantiateVariableFont

VF = 'LibreFranklin-VF.ttf'
A_VARIANTEN = ['A', 'Agrave', 'Aacute', 'Acircumflex', 'Atilde', 'Adieresis', 'Aring']


def verdichte(font, faktor):
    """Alle Buchstaben horizontal auf faktor (z. B. 0.95) zusammenziehen - inkl. Abstaende,
    Kerning und Akzent-Ankerpunkte, damit nichts verrutscht."""
    glyf, hmtx = font['glyf'], font['hmtx']
    for name in font.getGlyphOrder():
        g = glyf[name]
        if g.isComposite():
            for c in g.components:
                c.x = round(c.x * faktor)
        elif g.numberOfContours > 0:
            g.coordinates.scale((faktor, 1))
            g.coordinates.toInt()
    for name in font.getGlyphOrder():
        g = glyf[name]
        g.recalcBounds(glyf)
        adv, _ = hmtx[name]
        hmtx[name] = (round(adv * faktor), getattr(g, 'xMin', 0) if g.numberOfContours != 0 else 0)

    def walk(obj, seen):
        if id(obj) in seen:
            return
        seen.add(id(obj))
        if isinstance(obj, otTables.Anchor):
            obj.XCoordinate = round(obj.XCoordinate * faktor)
        if isinstance(obj, otBase.ValueRecord):
            for a in ('XPlacement', 'XAdvance'):
                if hasattr(obj, a) and getattr(obj, a):
                    setattr(obj, a, round(getattr(obj, a) * faktor))
            return
        if isinstance(obj, list):
            for x in obj:
                walk(x, seen)
        elif hasattr(obj, '__dict__'):
            for x in list(vars(obj).values()):
                walk(x, seen)
    if 'GPOS' in font:
        walk(font['GPOS'].table, set())


def laufweite(font, einheiten, leerzeichen_extra=0):
    hmtx = font['hmtx']
    for name in font.getGlyphOrder():
        adv, lsb = hmtx[name]
        if adv > 0:
            hmtx[name] = (adv + einheiten, lsb)
    for sp in ('space', 'uni00A0', 'nbspace'):
        if sp in hmtx.metrics:
            adv, lsb = hmtx[sp]
            hmtx[sp] = (adv + leerzeichen_extra, lsb)


def kerning_dazu(font, paare):
    """Zusaetzliches Paar-Kerning als eigene Lookup im kern-Feature (addiert sich zum vorhandenen)."""
    if not paare:
        return
    gpos = font['GPOS'].table
    werte = {(a, b): (buildValue({'XAdvance': v}), None) for (a, b), v in paare.items()}
    sub = buildPairPosGlyphsSubtable(werte, font.getReverseGlyphMap())
    lookup = buildLookup([sub])
    gpos.LookupList.Lookup.append(lookup)
    gpos.LookupList.LookupCount = len(gpos.LookupList.Lookup)
    idx = gpos.LookupList.LookupCount - 1
    for fr in gpos.FeatureList.FeatureRecord:
        if fr.FeatureTag == 'kern':
            fr.Feature.LookupListIndex.append(idx)
            fr.Feature.LookupCount = len(fr.Feature.LookupListIndex)


def benenne(font, stil, gewicht):
    name = font['name']
    fam = 'Suedsalat'
    voll = f'{fam} {stil}' if stil != 'Regular' else f'{fam} Regular'
    ps = f'{fam}-{stil}'
    lizenz_cr = name.getDebugName(0)
    for rec in list(name.names):
        if rec.nameID in (1, 2, 3, 4, 6, 16, 17, 21, 22, 25) or rec.nameID >= 256:
            name.removeNames(nameID=rec.nameID)
    name.setName(f'{lizenz_cr} Modified Version "Suedsalat" 2026 for the Suedsalat podcast app.', 0, 3, 1, 0x409)
    name.setName(fam, 1, 3, 1, 0x409)
    name.setName(stil, 2, 3, 1, 0x409)
    name.setName(f'2026;SUEDSALAT;{ps}', 3, 3, 1, 0x409)
    name.setName(voll, 4, 3, 1, 0x409)
    name.setName(ps, 6, 3, 1, 0x409)
    name.setName('Hausschrift des Podcasts Südsalat, abgeleitet von Libre Franklin '
                 '(The Libre Franklin Project Authors). Lizenz: SIL Open Font License 1.1.', 10, 3, 1, 0x409)
    name.setName('This Font Software is licensed under the SIL Open Font License, Version 1.1.', 13, 3, 1, 0x409)
    name.setName('https://openfontlicense.org', 14, 3, 1, 0x409)
    os2 = font['OS/2']
    os2.usWeightClass = gewicht
    bold = stil == 'Bold'
    os2.fsSelection = (os2.fsSelection & ~0b1100001) | (0b100000 if bold else 0b1000000)
    font['head'].macStyle = 1 if bold else 0
    for tag in ('STAT', 'MVAR', 'HVAR', 'fvar', 'gvar', 'avar'):
        if tag in font:
            del font[tag]


def baue(stil, wght, faktor, track, space_extra, paare, gewicht, ziel, formen=None):
    font = instantiateVariableFont(TTFont(VF), {'wght': wght}, updateFontNames=False)
    if faktor != 1:
        verdichte(font, faktor)
    if formen is not None:
        from formen import ecken_schaerfen, punkte_rund, umlaute_hoehe, enthinten
        enthinten(font)
        formen['punkte'] = punkte_rund(font, formen.get('punkt_faktor', 1.0), formen.get('dieresis'))
        formen['ecken'] = ecken_schaerfen(font)
        from formen import breite
        for name, (d, mitte, skal) in formen.get('breiten', {}).items():
            breite(font, name, d, mitte, skal)
        if formen.get('umlaut_abstand') is not None:
            umlaute_hoehe(font, formen['umlaut_abstand'])
        glyf, hmtx = font['glyf'], font['hmtx']
        for name in font.getGlyphOrder():
            g = glyf[name]; g.recalcBounds(glyf); adv, _ = hmtx[name]
            hmtx[name] = (adv, getattr(g, 'xMin', 0) if g.numberOfContours != 0 else 0)
    laufweite(font, track, space_extra)
    kerning_dazu(font, paare)
    benenne(font, stil, gewicht)
    font.save(ziel)
    return ziel


def la_paare(wert):
    return {('L', a): wert for a in A_VARIANTEN}


if __name__ == '__main__':
    faktor, track, la = float(sys.argv[1]), int(sys.argv[2]), int(sys.argv[3])
    baue('Bold', 645, faktor, track, 0, la_paare(la), 700, 'Suedsalat-Bold.ttf')
    baue('Regular', 400, 1.0, int(sys.argv[4]), int(sys.argv[5]), {}, 400, 'Suedsalat-Regular.ttf')
    print('gebaut')
