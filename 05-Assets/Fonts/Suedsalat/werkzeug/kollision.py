# Prueft alle Grossbuchstaben-Paare: wie nah kommen sich die beiden Buchstaben (Tinte an Tinte)?
import sys, freetype, uharfbuzz as hb
from fontTools.ttLib import TTFont
sys.path.insert(0, '.')
from satz import versal

def profil(face, gid):
    """Je Zeile: linkester und rechtester Tintenpunkt, relativ zum Ursprung des Buchstabens."""
    face.load_glyph(gid, freetype.FT_LOAD_RENDER)
    g = face.glyph; bm = g.bitmap; buf = bytes(bm.buffer)
    left, right = {}, {}
    for r in range(bm.rows):
        zeile = buf[r * bm.pitch: r * bm.pitch + bm.width]
        cols = [c for c, v in enumerate(zeile) if v > 100]
        if cols:
            y = r - g.bitmap_top
            left[y] = g.bitmap_left + cols[0]
            right[y] = g.bitmap_left + cols[-1]
    return left, right

def pruefe(path, cap_px=150):
    font = TTFont(path); upem = font['head'].unitsPerEm; cap = versal(font); s = cap_px / cap
    face = freetype.Face(path); face.set_char_size(int(upem * s * 64))
    hbf = hb.Font(hb.Face(hb.Blob.from_file_path(path)))
    buchst = list('ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ')
    cmap = font.getBestCmap(); order = font.getGlyphOrder()
    prof = {c: profil(face, font.getGlyphID(cmap[ord(c)])) for c in buchst}
    erg = []
    for a in buchst:
        for b in buchst:
            buf = hb.Buffer(); buf.add_str(a + b); buf.guess_segment_properties(); hb.shape(hbf, buf, {'kern': True})
            dx = buf.glyph_positions[0].x_advance * s
            r1 = prof[a][1]; l2 = prof[b][0]
            rows = set(r1) & set(l2)
            gap = min(l2[y] + dx - r1[y] for y in rows) if rows else 999
            erg.append((round(100 * gap / cap_px, 1), a + b))
    return sorted(erg)

if __name__ == '__main__':
    for path in sys.argv[1:]:
        erg = pruefe(path)
        print(path, '- engste Paare (Abstand in % der Versalhoehe):')
        print('  ', ', '.join(f'{p} {g}' for g, p in erg[:14]))
        print('   L-A:', [g for g, p in erg if p == 'LA'][0], '| Paare unter 2 %:', [p for g, p in erg if g < 2])
