# Setzt Text mit HarfBuzz (inkl. Kerning) und rendert ihn mit FreeType - fuer Vergleiche mit dem Logo.
import io, tempfile, os
import freetype, uharfbuzz as hb
from PIL import Image
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

VF = 'LibreFranklin-VF.ttf'

def instanz(wght):
    return instantiateVariableFont(TTFont(VF), {'wght': wght}, updateFontNames=False)

def als_datei(font):
    fd, path = tempfile.mkstemp(suffix='.ttf'); os.close(fd)
    font.save(path)
    return path

def versal(font):
    gs = font.getGlyphSet(); from fontTools.pens.boundsPen import BoundsPen
    p = BoundsPen(gs); gs['H'].draw(p); return p.bounds[3]

def rendern(path, text, cap_px, tracking=0, extra=None):
    """tracking: Einheiten je Buchstabe; extra: {Index: Einheiten} zusaetzlich nach Buchstabe Nr."""
    font = TTFont(path); upem = font['head'].unitsPerEm; cap = versal(font)
    blob = hb.Blob.from_file_path(path); f = hb.Font(hb.Face(blob))
    buf = hb.Buffer(); buf.add_str(text); buf.guess_segment_properties(); hb.shape(f, buf, {'kern': True})
    scale = cap_px / cap
    face = freetype.Face(path); face.set_char_size(int(upem * scale * 64))
    W = int(sum(p.x_advance for p in buf.glyph_positions) * scale + len(text) * abs(tracking) * scale + 200)
    H = int(cap_px * 2.2)
    img = Image.new('RGB', (W, H), (255, 255, 255))
    x = 50.0; base = int(cap_px * 1.6)
    for i, (info, pos) in enumerate(zip(buf.glyph_infos, buf.glyph_positions)):
        face.load_glyph(info.codepoint, freetype.FT_LOAD_RENDER)
        bm = face.glyph.bitmap
        if bm.width:
            g = Image.frombytes('L', (bm.width, bm.rows), bytes(bm.buffer))
            img.paste((16, 32, 36), (int(x + pos.x_offset * scale) + face.glyph.bitmap_left, base - face.glyph.bitmap_top), g)
        x += (pos.x_advance + tracking + (extra or {}).get(i, 0)) * scale
    return img
