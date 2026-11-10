"""Independent PDF oracle of the CSS1 font witness (examples/sources/fonts.html and fonts.json).

Expected values come from CSS1 5.2, CSS 2.1 15 and the cmap, hmtx, hhea, head and GSUB tables of the supplied font
files, all read here. The emitted PDF is read through pypdf, qpdf and Poppler and cross-checked with both browsers'
observations and with the norm-derived targets of validate_fonts.cjs. No converter code or layout witness is trusted,
and no complete CSS1 or PDF conformance is claimed.
"""
from __future__ import annotations

import argparse
from collections import Counter
import copy
import hashlib
from html.parser import HTMLParser
import json
import math
from pathlib import Path
import re
import struct
import subprocess
import unicodedata

from PIL import Image
import PIL
import pypdf
from pypdf import PdfReader
from pypdf.generic import ContentStream, DictionaryObject

from pdf_text_oracle import FontTextMap, PdfTextOracle

ROOT = Path(__file__).resolve().parents[1]
SOURCE = "examples/sources/fonts.html"
BINDINGS = "examples/sources/fonts.json"
BROWSERS = ("chromium", "firefox")
PX_TO_PT = 0.75
# Page model shared with the browser harness: A4 pages, content starting at the converter's 36pt page margin, and one
# page per forced break of the source plus the first.
PAGE_WIDTH_PT, PAGE_HEIGHT_PT, CONTENT_LEFT_PT, PAGES = 595.275590551, 841.88976378, 36.0, 3
CONTENT_WIDTH_PX = (PAGE_WIDTH_PT - 2 * CONTENT_LEFT_PT) / 0.75
RENDER_DPI = 96

WEIGHTS = (100, 200, 300, 400, 500, 600, 700, 800, 900)
SLANTS = ("normal", "italic", "oblique")
# CSS1 5.2.6 leaves the table to the user agent; CSS1 measurement M1 gives these pixel values in both browsers.
ABSOLUTE_PX = {"xx-small": 9, "x-small": 10, "small": 13, "medium": 16, "large": 18, "x-large": 24, "xx-large": 32}
RELATIVE_RATIO = 1.2
# CSS 2.1 15.6: inherited weight to bolder and to lighter.
BOLDER = {100: 400, 200: 400, 300: 400, 400: 700, 500: 700, 600: 900, 700: 900, 800: 900, 900: 900}
LIGHTER = {100: 100, 200: 100, 300: 100, 400: 100, 500: 100, 600: 400, 700: 400, 800: 700, 900: 700}
SMALL_CAPS_SCALE = {"pdf": 0.8, "chromium": 0.7, "firefox": 0.8}
BODY = ("Lato", "Noto Sans")
WORD = "minimum"
REGULAR, BOLD, ITALIC, BOLD_ITALIC, NOTO = "Lato-Regular", "Lato-Bold", "Lato-Italic", "Lato-BoldItalic", "NotoSans-Regular"
# One-face families: the same word in each supplied program.
REFERENCES = {"reference-lato-regular": ("Reference Lato Regular", REGULAR), "reference-lato-bold": ("Reference Lato Bold", BOLD),
              "reference-lato-italic": ("Reference Lato Italic", ITALIC), "reference-lato-bold-italic": ("Reference Lato Bold Italic", BOLD_ITALIC),
              "reference-noto-sans": ("Reference Noto Sans", NOTO)}
# Parent of the font shorthand examples: italic small-caps bold 25px/50px Ladder.
INHERITED = {"style": "italic", "variant": "small-caps", "weight": 700, "sizePx": 25, "lineHeight": 50}
# line-height: normal is a value of the user agent. CSS1 5.4.8 suggests 1.0 to 1.2 font sizes and the hhea metrics the
# browsers use reach 1.36 with Noto Sans; the inherited 50px would be 2.5 and 2.8 font sizes in the two examples.
NORMAL_LINE_HEIGHT_RANGE = (1.0, 1.5)
# Weights the five programs bound to Scale can tell apart: each result of the CSS 2.1 15.6 chart has its own program.
SCALE_CLASSES = [[100, 200, 300], [400], [500], [600, 700], [800, 900]]

# Differences that are asserted, not ignored: the oracle fails when one of them disappears.
KNOWN_DEVIATIONS = {
    "relative-size-ratio": "larger and smaller use the ratio 1.2 in the PDF and in both browsers, so larger from medium is 14.4pt (19.2px) "
                           "and not large, 13.5pt (18px): the CSS1 5.2.6 example is not reproduced.",
    "chromium-small-caps-scale": "Chromium synthesizes small capitals at 0.7 of the font size; the PDF and Firefox use 0.8.",
    "chromium-slanted-faces-merged": "Chromium matches faces labelled italic and faces labelled oblique as one set; the PDF and Firefox keep "
                                     "the two labels apart (CSS1 5.2.1, CSS 2.1 15.2).",
    "style-family-skip": "The PDF skips a family without a face of the requested style (CSS1 5.2.1, CSS 2.1 15.2); both browsers stay in the "
                         "first listed family.",
    "labelled-small-caps-face": "The PDF draws a face labelled as a small-caps font as written (CSS1 5.2.1). @font-face has no such label: both "
                                "browsers only see the other faces of the family and synthesize from them.",
    "opentype-small-caps-feature": "With a font that has the OpenType smcp feature, both browsers draw its small-capital glyphs at the full size; "
                                   "the PDF holds capitals at 0.8 for every face that is not labelled.",
    "normal-line-height": "line-height: normal, restored by the font shorthand, is a value of the user agent (CSS1 5.4.8): both browsers add the "
                          "hhea ascent, descent and line gap of the font, rounded to pixels, and the converter uses its own value.",
}
# pdfPt: coordinates and sizes are written as full-precision reals, so only floating-point noise is allowed.
# browserWidthPt (0.12px): Chromium places glyphs on a 1/64px grid and Firefox on a 1/60px grid, so a seven-glyph
# sample can differ by 7/60px. Kerning is not part of the tolerance: the PDF glyph steps and the browsers' character
# boxes of every measured text equal the hmtx advances, so no kerning pair or ligature applies to them. The nearest
# two programs still differ by 0.07em on the specimen word, 0.74pt at the smallest size it is set in.
# browserXPt (0.3px): the same rounding, accumulated over the samples that precede on the line.
# browserLineStepPt (0.01px): block positions are quantized to 1/64px and 1/60px. browserNormalLineStepPt (1px): the
# browsers round the ascent and the descent of a font to whole pixels before adding them.
TOLERANCE = {"pdfPt": 1e-6, "computedPx": 0.005, "browserWidthPt": 0.09, "browserXPt": 0.225, "smallCapsScale": 0.004, "widthsArray": 0.01,
             "browserLineStepPt": 0.0075, "browserNormalLineStepPt": 0.75}
OPERATORS = {b"BDC", b"EMC", b"q", b"Q", b"rg", b"g", b"BT", b"ET", b"Tf", b"Tc", b"Tw", b"Tz", b"Ts", b"Tm", b"Tj"}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def compact(text):
    return re.sub(r"\s+", "", text)


def run(*args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=60, check=True)
    return (result.stdout + result.stderr).strip()


def near(actual, expected, tolerance):
    return isinstance(actual, (int, float)) and not isinstance(actual, bool) and math.isfinite(actual) and abs(actual - expected) <= tolerance


def face_key(file):
    return Path(file).stem


def sfnt_tables(program):
    assert 12 <= len(program) <= 16_777_216, "Bounded font program required"
    assert program[:4] in (b"\x00\x01\x00\x00", b"true"), "Expected a static TrueType sfnt"
    count = struct.unpack_from(">H", program, 4)[0]
    assert 1 <= count <= 256 and 12 + count * 16 <= len(program), "Malformed table directory"
    tables = {}
    for entry in range(12, 12 + count * 16, 16):
        tag, _, start, size = struct.unpack_from(">4sIII", program, entry)
        assert tag not in tables and start + size <= len(program), "Malformed table directory"
        tables[tag.decode("latin-1")] = program[start:start + size]
    return tables


def small_capital_glyphs(gsub):
    """Single substitutions of the OpenType smcp feature (GSUB): glyph to its small capital."""
    substitutes, lookups = {}, set()
    if not gsub:
        return substitutes
    word = lambda offset: struct.unpack_from(">H", gsub, offset)[0]
    features, lookup_list = word(6), word(8)
    for index in range(word(features)):
        record = features + 2 + 6 * index
        if gsub[record:record + 4] == b"smcp":
            feature = features + word(record + 4)
            lookups.update(word(feature + 4 + 2 * item) for item in range(word(feature + 2)))
    for index in sorted(lookups):
        lookup = lookup_list + word(lookup_list + 2 + 2 * index)
        for item in range(word(lookup + 4)):
            table, kind = lookup + word(lookup + 6 + 2 * item), word(lookup)
            if kind == 7:
                kind, table = word(table + 2), table + struct.unpack_from(">I", gsub, table + 4)[0]
            assert kind == 1, "The smcp feature is read as single substitutions only"
            coverage = table + word(table + 2)
            if word(coverage) == 1:
                covered = [word(coverage + 4 + 2 * glyph) for glyph in range(word(coverage + 2))]
            else:
                covered = [glyph for start in range(coverage + 4, coverage + 4 + 6 * word(coverage + 2), 6) for glyph in range(word(start), word(start + 2) + 1)]
            for order, glyph in enumerate(covered):
                substitutes[glyph] = (glyph + word(table + 4)) & 0xFFFF if word(table) == 1 else word(table + 6 + 2 * order)
    return substitutes


def clusters(text):
    """A base character with the combining marks that follow it."""
    result = []
    for character in text:
        if result and unicodedata.category(character).startswith("M"):
            result[-1] += character
        else:
            result.append(character)
    return result


class SourceProgram:
    """unitsPerEm (head), line metrics (hhea), advances (hmtx), the Unicode BMP map (cmap format 4) and the smcp
    substitutions (GSUB) of one supplied file."""

    def __init__(self, data):
        self.sha256 = digest(data)
        self.tables = tables = sfnt_tables(data)
        for tag in ("head", "hhea", "hmtx", "maxp", "cmap", "glyf", "loca"):
            assert tag in tables, "Missing table " + tag
        word = lambda table, offset: struct.unpack_from(">H", table, offset)[0]
        self.units, count, cmap = word(tables["head"], 18), word(tables["hhea"], 34), tables["cmap"]
        assert self.units > 0 and count >= 1 and len(tables["hmtx"]) >= count * 4, "Malformed horizontal metrics"
        self.widths = [word(tables["hmtx"], 4 * index) for index in range(count)]
        ascender, descender, gap = struct.unpack_from(">hhh", tables["hhea"], 4)
        self.line_spacing = (ascender - descender + gap) / self.units
        self.small_capitals = small_capital_glyphs(tables.get("GSUB"))
        subtable = None
        for record in range(4, 4 + word(cmap, 2) * 8, 8):
            platform, encoding, offset = struct.unpack_from(">HHI", cmap, record)
            if ((platform == 3 and encoding == 1) or platform == 0) and word(cmap, offset) == 4:
                subtable = offset
        assert subtable is not None, "A Unicode cmap format 4 subtable is required"
        doubled = word(cmap, subtable + 6)
        ends = subtable + 14
        starts = ends + doubled + 2
        deltas, ranges = starts + doubled, starts + 2 * doubled
        self.glyphs = {}
        for segment in range(0, doubled, 2):
            end, start, delta, offset = (word(cmap, base + segment) for base in (ends, starts, deltas, ranges))
            for code in range(start, min(end, 0xFFFE) + 1):
                glyph = code if offset == 0 else word(cmap, ranges + segment + offset + 2 * (code - start))
                if glyph or offset == 0:
                    glyph = (glyph + delta) & 0xFFFF
                if glyph:
                    self.glyphs[code] = glyph

    def glyph(self, character):
        return self.glyphs.get(ord(character))

    def advance(self, glyph):
        assert 0 <= glyph <= 65535
        return self.widths[min(glyph, len(self.widths) - 1)] / self.units

    def small_capital(self, character):
        """Glyph the smcp feature substitutes for a character, or None without a substitution."""
        return self.small_capitals.get(self.glyph(character))


def identify_program(embedded, programs):
    """The supplied file an embedded program was made from: every table it carries is that file's table."""
    def neutral(tag, table):  # head.checkSumAdjustment depends on the table directory, not on the font
        return table[:8] + bytes(4) + table[12:] if tag == "head" else table
    tables = sfnt_tables(embedded)
    assert {"glyf", "loca", "head", "hhea", "hmtx", "maxp"} <= set(tables), "The embedded program lacks an outline or metrics table"
    matches = [key for key, program in programs.items()
               if all(tag in program.tables and neutral(tag, table) == neutral(tag, program.tables[tag]) for tag, table in tables.items())]
    assert len(matches) == 1, "An embedded program must be exactly one of the supplied font files"
    return matches[0]


class WitnessSource(HTMLParser):
    """Body text, identified elements with their offsets in the text without white space, and forced page breaks."""

    def __init__(self, source):
        super().__init__(convert_charrefs=True)
        assert re.search(r"\.page\s*\{\s*page-break-before:\s*always\s*;?\s*\}", source), "The page class must force a page break"
        self.inside, self.length, self.parts, self.open, self.void = False, 0, [], [], {"br", "hr", "img", "input", "wbr"}
        self.order, self.elements, self.breaks = [], {}, []
        self.feed(source)
        self.close()
        assert not self.inside and not self.open and self.order, "Well-nested source with identified elements required"
        self.text = "".join(self.parts)
        for element in self.elements.values():
            element["text"] = " ".join(element["text"].split())

    def handle_starttag(self, tag, attrs):
        if tag == "body":
            self.inside = True
        elif self.inside and tag not in self.void:
            attributes = dict(attrs)
            identifier = attributes.get("id")
            if "page" in (attributes.get("class") or "").split():
                self.breaks.append(self.length)
            if identifier is not None:
                assert identifier not in self.elements, "Unique identifiers required"
                parent = next((entry for _, entry in reversed(self.open) if entry is not None), None)
                if parent is not None:
                    self.elements[parent]["kind"] = "row"
                self.order.append(identifier)
                self.elements[identifier] = {"kind": "sample", "row": parent, "offset": self.length, "length": None, "text": ""}
            self.open.append((tag, identifier))

    def handle_endtag(self, tag):
        if tag == "body":
            self.inside = False
        elif self.inside and tag not in self.void:
            assert self.open and self.open[-1][0] == tag, "Well-nested source required"
            identifier = self.open.pop()[1]
            if identifier is not None:
                self.elements[identifier]["length"] = self.length - self.elements[identifier]["offset"]

    def handle_data(self, data):
        if self.inside:
            self.parts.append(data)
            self.length += len(compact(data))
            for _, identifier in self.open:
                if identifier is not None:
                    self.elements[identifier]["text"] += data


def weight_order(desired):
    """CSS 2.1 15.6: order in which a missing weight is replaced. It never fails."""
    if desired == 400:
        return [400, 500, *below(400), *above(500)]
    if desired == 500:
        return [500, 400, *below(400), *above(500)]
    return [desired, *below(desired), *above(desired)] if desired < 400 else [desired, *above(desired), *below(desired)]


def below(limit):
    return [weight for weight in reversed(WEIGHTS) if weight < limit]


def above(limit):
    return [weight for weight in WEIGHTS if weight > limit]


# What CSS1 5.2 and CSS 2.1 15 require and the converter documents. styles: labels accepted for a requested style, in
# order, a family without any of them being skipped. Browsers and rival rules replace some entries.
NORM = {"absolute": ABSOLUTE_PX, "larger": lambda px: px * RELATIVE_RATIO, "smaller": lambda px: px / RELATIVE_RATIO, "bolder": BOLDER, "lighter": LIGHTER,
        "weight_order": weight_order, "styles": {"normal": [["normal"]], "italic": [["italic"], ["oblique"]], "oblique": [["oblique"]]},
        "generic_families": True, "skip_unbound": True, "small_caps": True, "labelled_small_caps": True, "variant_before_weight": True,
        "small_caps_scale": SMALL_CAPS_SCALE["pdf"], "small_caps_feature": False, "fallback_unit": "cluster", "shorthand_resets": True}
# Measured browser behaviour where it departs from CSS1: a style never fails, Chromium merges the italic and oblique
# labels, a small-caps label does not exist and OpenType small capitals come before the synthesis.
RULES = {"pdf": NORM,
         "chromium": {**NORM, "styles": {"normal": [["normal"]], "italic": [["italic", "oblique"], ["normal"]], "oblique": [["italic", "oblique"], ["normal"]]},
                      "labelled_small_caps": False, "small_caps_scale": SMALL_CAPS_SCALE["chromium"], "small_caps_feature": True},
         "firefox": {**NORM, "styles": {"normal": [["normal"]], "italic": [["italic"], ["oblique"], ["normal"]], "oblique": [["oblique"], ["normal"], ["italic"]]},
                     "labelled_small_caps": False, "small_caps_scale": SMALL_CAPS_SCALE["firefox"], "small_caps_feature": True}}


def table_step(direction):
    sizes = list(ABSOLUTE_PX.values())
    return lambda px: next((size for size in (sizes if direction > 0 else reversed(sizes)) if (size - px) * direction > 1e-9), px)


def light_weight(desired):
    return [desired, *below(desired), *above(desired)]


def bold_weight(desired):
    return [desired, *above(desired), *below(desired)]


def rival(separates, **rules):
    return {"rules": rules, "separates": separates}


# Plausible wrong rules. The witness must tell each of them from the norm on the listed samples: Witness.design()
# verifies it from the labels and the font tables, and the negative controls feed the comparison what such a rule draws.
RIVALS = {
    "nearest weight": rival(["match-ladder-300", "match-ladder-600", "match-ladder-oblique-500"], weight_order=lambda desired: sorted(WEIGHTS, key=lambda weight: (abs(weight - desired), weight))),
    "bold threshold at 600": rival(["match-ladder-100", "match-ladder-300", "lighter-from-400", "bolder-from-900"],
                                   weight_order=lambda desired: [700, 800, 900, 600, 500, 400, 300, 200, 100] if desired >= 600 else [400, 300, 200, 100, 500, 600, 700, 800, 900]),
    "lighter faces first": rival(["match-ladder-600", "match-ladder-italic-400", "match-lato-600"], weight_order=light_weight),
    "darker faces first": rival(["match-ladder-200", "match-ladder-300", "match-ladder-500", "match-lato-500"], weight_order=bold_weight),
    "400 and 500 without their own rule": rival(["match-ladder-italic-400"], weight_order=lambda desired: light_weight(desired) if desired <= 500 else bold_weight(desired)),
    "500 as a bold weight": rival(["match-ladder-500", "match-lato-500", "match-ladder-oblique-500"], weight_order=lambda desired: weight_order(desired) if desired < 500 else bold_weight(desired)),
    "400 then 500 then darker": rival(["match-ladder-oblique-400"], weight_order=lambda desired: [400, 500, *above(500), *below(400)] if desired == 400 else weight_order(desired)),
    "500 then 400 then darker": rival(["match-ladder-oblique-500"], weight_order=lambda desired: [500, 400, *above(500), *below(400)] if desired == 500 else weight_order(desired)),
    "bolder one step": rival(["bolder-from-100", "bolder-from-400", "bolder-from-600", "strong", "b-in-strong"], bolder={weight: min(900, weight + 100) for weight in WEIGHTS}),
    "lighter one step": rival(["lighter-from-500", "lighter-from-600", "lighter-from-700", "lighter-from-900"], lighter={weight: max(100, weight - 100) for weight in WEIGHTS}),
    "bolder as bold": rival(["bolder-from-100", "bolder-from-600", "bolder-from-900", "b-in-strong", "b-from-300"], bolder={weight: 700 for weight in WEIGHTS}),
    "lighter as normal": rival(["lighter-from-100", "lighter-from-400", "lighter-from-800"], lighter={weight: 400 for weight in WEIGHTS}),
    "bolder stops at 700": rival(["bolder-from-600", "bolder-from-700", "b-in-strong", "b-in-strong-in-b-from-300"], bolder={**BOLDER, 600: 700, 700: 700}),
    "lighter stops at 400": rival(["lighter-from-400", "lighter-from-500"], lighter={**LIGHTER, 400: 400, 500: 400}),
    "italic refuses oblique faces": rival(["style-italic-from-oblique-face"], styles={**NORM["styles"], "italic": [["italic"]]}),
    "italic prefers oblique faces": rival(["match-ladder-italic-400", "match-ladder-italic-500"], styles={**NORM["styles"], "italic": [["oblique"], ["italic"]]}),
    "oblique accepts italic faces": rival(["oblique-skips-italic"], styles={**NORM["styles"], "oblique": [["oblique"], ["italic"]]}),
    "italic and oblique as one label": rival(["match-ladder-oblique-400", "match-ladder-oblique-500", "oblique-skips-italic"],
                                             styles={**NORM["styles"], "italic": [["italic", "oblique"]], "oblique": [["italic", "oblique"]]}),
    "style never skips a family": rival(["oblique-skips-italic", "italic-skips-upright"], styles=RULES["firefox"]["styles"]),
    "generic families without binding": rival(["font-3", "font-4", "font-5"], generic_families=False),
    "unbound name ending the list": rival(["font-3", "font-4"], skip_unbound=False),
    "font-variant ignored": rival(["caps-lowercase", "caps-mixed", "caps-punctuation", "caps-sharp-s", "caps-bold", "caps-inherited", "caps-labelled", "caps-labelled-bold", "caps-feature", "font-5"],
                                  small_caps=False),
    "small capitals at 0.7": rival(["caps-lowercase", "caps-mixed", "caps-punctuation", "caps-sharp-s", "caps-bold", "caps-inherited", "caps-feature", "font-5", "font-5-again"], small_caps_scale=0.7),
    "small capitals as full capitals": rival(["caps-lowercase", "caps-mixed", "caps-punctuation", "caps-sharp-s", "caps-bold", "caps-inherited", "caps-feature", "font-5"], small_caps_scale=1),
    "small-caps label ignored": rival(["caps-labelled", "caps-labelled-bold"], labelled_small_caps=False),
    "weight before variant": rival(["caps-labelled-bold"], variant_before_weight=False),
    "OpenType small capitals": rival(["caps-feature"], small_caps_feature=True),
    "fallback by code point": rival(["fallback-cluster"], fallback_unit="codepoint"),
    "fallback by sample": rival(["fallback-lato-noto", "fallback-noto-lato", "fallback-cluster", "fallback-character-default"], fallback_unit="sample"),
    "relative sizes by table step": rival(["larger-from-medium", "smaller-from-medium", "larger-from-x-large", "smaller-from-x-large", "larger-from-12", "smaller-from-30", "inherited-larger",
                                           "nested-larger", "smaller-from-small", "indent-larger"], larger=table_step(1), smaller=table_step(-1)),
    "relative sizes by 1.5": rival(["larger-from-medium", "smaller-from-medium", "larger-from-30", "nested-larger", "smaller-from-small", "indent-larger"],
                                   larger=lambda px: px * 1.5, smaller=lambda px: px / 1.5),
    "CSS1 scaling factor 1.5": rival(["absolute-12-xx-small", "absolute-12-x-small", "absolute-12-small", "absolute-30-large", "absolute-30-x-large", "absolute-30-xx-large", "inherited-x-large",
                                      "indent-x-large", "font-3", "font-4"], absolute={keyword: 16 * 1.5 ** (index - 3) for index, keyword in enumerate(ABSOLUTE_PX)}),
    "shorthand keeping omitted values": rival(["font-1", "font-2", "font-3", "font-4", "font-5", "row-font-2-again", "row-font-4-again"], shorthand_resets=False),
}


def family_face(faces, request, rules):
    """CSS1 5.2.1 inside one family: the style first, then the variant, then the weight, which never fails. None: the
    family has no face of the style and is skipped. A face labelled as a small-caps font is drawn as written."""
    capitals = request["variant"] == "small-caps" and rules["small_caps"]
    for labels in rules["styles"][request["style"]]:
        styled = [face for face in faces if face["style"] in labels and (rules["labelled_small_caps"] or not face.get("smallCaps"))]
        labelled, plain = [face for face in styled if face.get("smallCaps")], [face for face in styled if not face.get("smallCaps")]
        pool = labelled if capitals and labelled else plain
        if not rules["variant_before_weight"]:
            weight = next((candidate for candidate in rules["weight_order"](request["weight"]) if any(face["weight"] == candidate for face in styled)), None)
            pool = labelled if capitals and any(face["weight"] == weight for face in labelled) else plain
        weight = next((candidate for candidate in rules["weight_order"](request["weight"]) if any(face["weight"] == candidate for face in pool)), None)
        chosen = [face for face in pool if face["weight"] == weight]
        if chosen:
            assert len({face_key(face["file"]) for face in chosen}) == 1, "Labels matched as one must share a program at each weight"
            return {"face": face_key(chosen[0]["file"]), "label": {"style": chosen[0]["style"], "weight": weight, "smallCaps": bool(chosen[0].get("smallCaps"))}}
    return None


def witness_rows(rules):
    """Rows and samples of fonts.html in document order, with the computed values of one rule set."""
    rows, generic = [], lambda name: {"generic": name}
    absolute, larger, smaller, bolder = rules["absolute"], rules["larger"], rules["smaller"], lambda weight: rules["bolder"][weight]

    def sample(identifier, text, size, **style):
        return {"id": identifier, "text": text, "sizePx": size, "weight": 400, "style": "normal", "variant": "normal", "families": list(BODY), **style}

    def word(identifier, size=14, **style):
        return sample(identifier, WORD, size, **style)

    def row(identifier, size, samples, **extra):  # samples of a row are separated by one space of the row's own font
        rows.append({"id": identifier, "sizePx": size, "indentPx": 0, "samples": samples, **extra})

    row("row-reference", 16, [word(identifier, 16, families=[family]) for identifier, (family, _) in REFERENCES.items()])
    # The text of a size sample is its size in pixels as CSS1 and the measurements give it, whatever the rules compute.
    for parent in (12, 30):
        row(f"row-absolute-{parent}", parent, [sample(f"absolute-{parent}-{keyword}", str(size), absolute[keyword]) for keyword, size in ABSOLUTE_PX.items()])
    row("row-relative", 14, [item for parent, size, less, more in (("medium", absolute["medium"], "13.3", "19.2"), ("x-large", absolute["x-large"], "20", "28.8"), ("12", 12, "10", "14.4"), ("30", 30, "25", "36"))
                             for item in (sample("smaller-from-" + parent, less, smaller(size)), sample("larger-from-" + parent, more, larger(size)))])
    row("row-inheritance", 14, [sample("inherited-larger", "16.8", larger(14)), sample("nested-larger", "20.16", larger(larger(14))), sample("inherited-x-large", "24", absolute["x-large"]),
                                sample("percentage-of-x-large", "12", 0.5 * absolute["x-large"]), sample("em-of-x-large", "18", 0.75 * absolute["x-large"]),
                                sample("smaller-from-small", "10.83", smaller(absolute["small"]))])
    row("row-indent-x-large", absolute["x-large"], [sample("indent-x-large", "48", absolute["x-large"])], indentPx=2 * absolute["x-large"])
    row("row-indent-larger", larger(14), [sample("indent-larger", "84", larger(14))], indentPx=5 * larger(14))
    # Relative weights are set in Scale, where 100, 400, 500, 700 and 900 are five different programs.
    scale = lambda weight: {"weight": weight, "families": ["Scale"]}
    for name, table in (("bolder", rules["bolder"]), ("lighter", rules["lighter"])):
        row("row-" + name, 14, [word(f"{name}-from-{inherited}", **scale(table[inherited])) for inherited in WEIGHTS])
    row("row-nesting", 14, [word("strong", **scale(bolder(400))), word("b-in-strong", **scale(bolder(bolder(400)))), word("b-from-300", **scale(bolder(300))),
                            word("strong-in-b-from-300", **scale(bolder(bolder(300)))), word("b-in-strong-in-b-from-300", **scale(bolder(bolder(bolder(300))))),
                            word("inherited-bolder", **scale(bolder(400))), word("keyword-bold", **scale(700)), word("keyword-normal", **scale(400))])
    row("row-match-lato", 14, [word(f"match-lato-{weight}", weight=weight) for weight in WEIGHTS])
    row("row-match-ladder", 14, [word(f"match-ladder-{weight}", weight=weight, families=["Ladder"]) for weight in WEIGHTS])
    row("row-match-ladder-italic", 14, [word(f"match-ladder-italic-{weight}", weight=weight, style="italic", families=["Ladder"]) for weight in WEIGHTS[:5]])
    row("row-match-ladder-oblique", 14, [word(f"match-ladder-oblique-{weight}", weight=weight, style="oblique", families=["Ladder"]) for weight in WEIGHTS])
    row("row-match-single", 14, [word("match-single-900", weight=900, families=["Reference Noto Sans"])])
    row("row-style", 14, [word("style-i", style="italic"), word("style-em", style="italic"), word("style-i-in-b", style="italic", weight=bolder(400)), word("style-normal-in-i"),
                          word("style-italic-from-oblique-face", style="italic", families=["Slant"]), word("style-oblique", style="oblique", families=["Slant"])])
    row("row-oblique-skips-italic", 14, [word("oblique-skips-italic", style="oblique", families=["Lato", "Slant"])])
    row("row-italic-skips-upright", 14, [word("italic-skips-upright", style="italic", families=["Noto Sans", "Lato"])])
    caps = {"variant": "small-caps"}
    row("row-small-caps", 20, [word("caps-lowercase", 20, **caps), sample("caps-mixed", "Nominal", 20, **caps), sample("caps-digits", "2008", 20, **caps), sample("caps-punctuation", "No.3", 20, **caps),
                               sample("caps-sharp-s", "ma\u00df", 20, **caps), word("caps-bold", 20, weight=bolder(400), **caps), sample("caps-inherited", "nominal", 20, **caps),
                               word("caps-restored", 20)])
    row("row-small-caps-faces", 20, [word("caps-labelled", 20, families=["Engraved"], **caps), word("caps-labelled-bold", 20, weight=bolder(400), families=["Engraved"], **caps),
                                     word("caps-labelled-normal", 20, families=["Engraved"]), word("caps-feature", 20, families=["Noto Sans"], **caps)])
    row("row-fallback", 20, [sample("fallback-lato-noto", "n\u211dn\u0377n", 20), sample("fallback-noto-lato", "n\u2192n\u25cbn\u25aan", 20, families=["Noto Sans", "Lato"]),
                             sample("fallback-cluster", "n\u2192\u0301n", 20, families=["Noto Sans", "Lato"]), word("fallback-default", 20, families=["Unlisted Grotesk", generic("cursive")]),
                             sample("fallback-character-default", "n\u211dn", 20, families=["Lato"]),
                             word("fallback-default-style", 20, style="italic", weight=700, families=["Unlisted Grotesk"])])
    # CSS1 5.2.7 examples under a parent set to italic small-caps bold 25px/50px: what a shorthand omits is reset. Each
    # example is set twice: the second block starts one line height below the first.
    kept = {"style": "normal", "variant": "normal", "weight": 400, "lineHeight": "normal"} if rules["shorthand_resets"] else INHERITED
    for number, declared in ((1, {"sizePx": 12 / 0.75, "lineHeight": 14 / 0.75, "families": [generic("sans-serif")]}),
                             (2, {"sizePx": 0.8 * INHERITED["sizePx"], "families": [generic("sans-serif")]}),
                             (3, {"sizePx": absolute["x-large"], "lineHeight": 1.1 * absolute["x-large"], "families": ["new century schoolbook", generic("serif")]}),
                             (4, {"weight": 700, "style": "italic", "sizePx": absolute["large"], "families": ["Palatino", generic("serif")]}),
                             (5, {"variant": "small-caps", "sizePx": 1.2 * INHERITED["sizePx"], "lineHeight": 1.2 * (1.2 * INHERITED["sizePx"]), "families": [generic("fantasy")]})):
        computed = {**kept, **declared}
        size, line_height = computed.pop("sizePx"), computed.pop("lineHeight")
        for suffix in ("", "-again"):
            row(f"row-font-{number}{suffix}", size, [word(f"font-{number}{suffix}", size, **computed)], lineHeight=line_height, **({"repeats": f"row-font-{number}"} if suffix else {}))
    return rows


class Witness:
    """The inventory of fonts.html with the faces, sizes and positions the norm and the supplied font tables require.
    Another rule set than the norm gives what a converter following that rule would draw."""

    def __init__(self, rules=NORM):
        self.source_bytes, self.binding_bytes = (ROOT / SOURCE).read_bytes(), (ROOT / BINDINGS).read_bytes()
        assert len(self.source_bytes) < 100_000 and len(self.binding_bytes) < 100_000, "Bounded source and bindings required"
        self.bindings = json.loads(self.binding_bytes)
        assert set(self.bindings) == {"default", "families", "generic"}, "Bindings declare default, families and generic"
        self.files, self.programs = {}, {}
        for group in (self.bindings["default"], *self.bindings["families"].values(), *self.bindings["generic"].values()):
            labels = [(face["style"], bool(face.get("smallCaps")), face["weight"]) for face in group["faces"]]
            assert len(set(labels)) == len(labels) and ("normal", False, 400) in labels, "Distinct labels and a normal 400 face are required"
            for face in group["faces"]:
                assert face["weight"] in WEIGHTS and face["style"] in SLANTS, "Faces are labelled 100 to 900 and normal, italic or oblique"
                assert isinstance(face.get("smallCaps", False), bool), "smallCaps is a boolean label"
                assert re.fullmatch(r"[A-Za-z0-9/_.-]+\.ttf", face["file"]) and ".." not in face["file"], "Repository-relative TrueType path required"
                key = face_key(face["file"])
                if key not in self.files:
                    data = (ROOT / face["file"]).read_bytes()
                    self.files[key], self.programs[key] = {"file": face["file"], "sha256": digest(data)}, SourceProgram(data)
                assert self.files[key]["file"] == face["file"], "Face keys must identify one file"
        self.source = WitnessSource(self.source_bytes.decode("utf-8"))
        assert len(self.source.breaks) == PAGES - 1, "One page per forced break of the source, plus the first"
        self.rules, self.layouts = {**RULES, "pdf": rules}, {}
        self.rows = witness_rows(rules)
        order = [identifier for row in self.rows for identifier in (row["id"], *(sample["id"] for sample in row["samples"]))]
        assert order == self.source.order, "Complete fixture inventory required: the model and fonts.html list different elements"
        for row in self.rows:
            assert self.source.elements[row["id"]]["kind"] == "row", "Complete fixture inventory required: " + row["id"] + " is not a row"
            for sample in row["samples"]:
                element = self.source.elements[sample["id"]]
                assert (element["kind"], element["row"], element["text"]) == ("sample", row["id"], sample["text"]), "Complete fixture inventory required: " + sample["id"]
                assert not re.search(r"\s", sample["text"]) and not unicodedata.category(sample["text"][0]).startswith("M"), "One measured word per sample, starting with a base character"

    def following(self, rules):
        """The same sources and programs under another rule set for the PDF."""
        other = copy.copy(self)
        other.rules, other.layouts, other.rows = {**RULES, "pdf": rules}, {}, witness_rows(rules)
        return other

    def chain(self, sample, rules):
        """Faces tried in order for every character: one per listed family that has the style, then the default family,
        which cannot fail and supplies the best match it has. Names without a binding are ignored."""
        elements = []
        for index, name in enumerate(sample["families"]):
            family = self.bindings["families"].get(name) if isinstance(name, str) else self.bindings["generic"].get(name["generic"]) if rules["generic_families"] else None
            if not family and not rules["skip_unbound"]:
                break
            matched = family_face(family["faces"], sample, rules) if family else None
            if matched:
                elements.append({**matched, "family": index})
        default = self.bindings["default"]["faces"]
        return elements + [{**(family_face(default, sample, rules) or family_face(default, {**sample, "style": "normal"}, rules)), "family": None}]

    def diagnostics(self, sample, elements, used, rules):
        """The informative diagnostics the converter documents for a sample: the default family because no listed family
        supplied a face, a default face of another style, and a face across the bold threshold (600) from the request."""
        notes = []
        if len(elements) == 1:
            notes.append(("font.default-family", json.dumps(sample["families"], separators=(",", ":"), ensure_ascii=False) + " " + sample["style"]))
        for element in used:
            label = element["label"]
            if element["family"] is None and not any(label["style"] in labels for labels in rules["styles"][sample["style"]]):
                notes.append(("font.style-fallback", sample["style"] + " to " + label["style"]))
            if (label["weight"] >= 600) != (sample["weight"] >= 600):
                notes.append(("font.weight-fallback", "%d to %s%s%d" % (sample["weight"], label["style"], " small-caps " if label["smallCaps"] else " ", label["weight"])))
        return [{"code": code, "circumstance": circumstance} for code, circumstance in dict.fromkeys(notes)]

    def shape(self, sample, target, only=None):
        """Text commands of a sample for the PDF, a browser or an explicit rule set: one per face and size. Small
        capitals are the face as written when it is labelled, its smcp glyphs when the rules use them, and otherwise its
        capitals at a reduced size. `only` draws the whole sample with one program; None when it lacks a glyph."""
        rules = self.rules[target] if isinstance(target, str) else target
        elements = self.chain(sample, rules)
        candidates, used, segments, characters = elements if only is None else [{**elements[0], "face": only}], [], [], []
        units = clusters(sample["text"]) if rules["fallback_unit"] == "cluster" else list(sample["text"]) if rules["fallback_unit"] == "codepoint" else [sample["text"]]
        for source in units:
            character = None
            for element in candidates:
                program, capitals = self.programs[element["face"]], sample["variant"] == "small-caps" and rules["small_caps"] and not element["label"]["smallCaps"]
                feature = capitals and rules["small_caps_feature"] and bool(program.small_capitals)
                drawn = source.upper() if capitals and not feature else source
                size = sample["sizePx"] * (rules["small_caps_scale"] if drawn != source else 1)
                if any(program.glyph(letter) is None for letter in drawn):
                    continue
                gids = [program.small_capital(letter) or program.glyph(letter) if feature else program.glyph(letter) for letter in drawn]
                texts = list(source) if len(drawn) == len(source) else [source] + [""] * (len(drawn) - 1)
                glyphs = [{"gid": gid, "text": text, "advancePx": program.advance(gid) * size} for gid, text in zip(gids, texts)]
                character = {"text": source, "drawn": drawn, "face": element["face"], "sizePx": size, "feature": bool(feature), "glyphs": len(glyphs),
                             "widthPx": sum(program.advance(gid) for gid in gids) * size}
                if element not in used:
                    used.append(element)
                break
            if character is None and only is not None:
                return None
            assert character is not None, "No listed family draws " + source
            characters.append(character)
            if not segments or (segments[-1]["face"], segments[-1]["sizePx"], segments[-1]["feature"]) != (character["face"], size, character["feature"]):
                segments.append({"text": "", "drawn": "", "face": character["face"], "sizePx": size, "feature": character["feature"], "glyphs": [], "widthPx": 0})
            last = segments[-1]
            last.update(text=last["text"] + source, drawn=last["drawn"] + drawn, glyphs=last["glyphs"] + glyphs, widthPx=last["widthPx"] + character["widthPx"])
        faces = {letter["face"] for letter in characters}
        return {"segments": segments, "characters": characters, "widthPx": sum(segment["widthPx"] for segment in segments), "face": characters[0]["face"] if len(faces) == 1 else None,
                "family": used[0]["family"], "asWritten": sample["variant"] == "small-caps" and used[0]["label"]["smallCaps"], "feature": any(letter["feature"] for letter in characters),
                "diagnostics": self.diagnostics(sample, elements, used, rules)}

    def layout(self, target):
        """Expected rows and samples for the PDF or for one browser, in CSS pixels from the left content edge."""
        assert target in self.rules, "Unknown target " + str(target)
        if target not in self.layouts:
            rows, samples = {}, {}
            for row in (self.rows if target == "pdf" else witness_rows(self.rules[target])):
                space = {"id": None, "text": " ", "sizePx": row["sizePx"], "weight": 400, "style": "normal", "variant": "normal", "families": list(BODY)}
                x, commands = row["indentPx"], []
                for index, sample in enumerate(row["samples"]):
                    if index:
                        commands.append({**self.shape(space, target)["segments"][0], "sample": None, "xPx": x})
                        x += commands[-1]["widthPx"]
                    shaped = self.shape(sample, target)
                    samples[sample["id"]] = {**sample, "row": row["id"], **shaped, "xPx": x}
                    for segment in shaped["segments"]:
                        commands.append({**segment, "sample": sample["id"], "xPx": x})
                        x += segment["widthPx"]
                rows[row["id"]] = {**{key: value for key, value in row.items() if key != "id"}, "samples": [sample["id"] for sample in row["samples"]], "commands": commands, "endPx": x}
            self.layouts[target] = {"rows": rows, "samples": samples}
        return self.layouts[target]

    def candidates(self, sample, target, width_px, margin=1):
        """Supplied programs whose own advances, under the rules of the target, explain a measured width of the whole sample."""
        shaped = {key: self.shape(sample, target, key) for key in self.programs}
        return [key for key, drawn in shaped.items() if drawn is not None and near(width_px, drawn["widthPx"], margin * TOLERANCE["browserWidthPt"] / PX_TO_PT)]

    def deviation(self, identifier, browser):
        """Why a browser draws a sample differently from the PDF, or None when it draws the same commands."""
        pdf, other = self.layout("pdf")["samples"][identifier], self.layout(browser)["samples"][identifier]
        if drawing(pdf) == drawing(other):
            return None
        if pdf["asWritten"]:
            return "labelled-small-caps-face"
        if other["feature"]:
            return "opentype-small-caps-feature"
        if [letter["face"] for letter in pdf["characters"]] == [letter["face"] for letter in other["characters"]]:
            return "chromium-small-caps-scale"
        return "chromium-slanted-faces-merged" if pdf["family"] == other["family"] else "style-family-skip"

    def intended_diagnostics(self):
        return [{"sample": identifier, **note} for identifier, sample in self.layout("pdf")["samples"].items() for note in sample["diagnostics"]]

    def design(self):
        """Design conditions of the witness itself, from the labels and the font tables: it must be able to tell the norm
        from every rival rule, and every known deviation must be visible. A weakened binding or document is refused."""
        pdf, programs, pixel = self.layout("pdf"), self.programs, TOLERANCE["browserWidthPt"] / PX_TO_PT
        word = lambda face: sum(programs[face].advance(programs[face].glyph(letter)) for letter in WORD)
        widths = sorted(word(face) for _, face in REFERENCES.values())
        facts = {"wordSeparationEm": min(right - left for left, right in zip(widths, widths[1:]))}
        assert facts["wordSeparationEm"] >= 0.05, "The specimen word must have a distinct width in every program"
        assert [pdf["samples"][identifier]["face"] for identifier in REFERENCES] == [face for _, face in REFERENCES.values()], "Every reference family binds its own program"
        assert sorted(programs) == sorted([REGULAR, BOLD, ITALIC, BOLD_ITALIC, NOTO]), "The five supplied programs"
        default = self.bindings["default"]["faces"]
        assert len(default) == 1 and face_key(default[0]["file"]) == NOTO, "The default family binds the single Noto Sans face"
        # Characters of the fallback samples: each in one program only, the combining accent in both and without advance.
        facts["coverage"] = {"U+%04X" % code: [face for face in (REGULAR, NOTO) if programs[face].glyph(chr(code)) is not None] for code in (0x211D, 0x0377, 0x2192, 0x25CB, 0x25AA, 0x0301)}
        assert facts["coverage"] == {"U+211D": [NOTO], "U+0377": [NOTO], "U+2192": [REGULAR], "U+25CB": [REGULAR], "U+25AA": [REGULAR], "U+0301": [REGULAR, NOTO]}, \
            "Fallback characters must each exist in one program only and the combining accent in both"
        assert all(programs[face].advance(programs[face].glyph("\u0301")) == 0 for face in (REGULAR, NOTO)), "The combining accent has no advance"
        facts["smallCapitalSubstitutions"] = {face: len(program.small_capitals) for face, program in programs.items()}
        assert all(programs[NOTO].small_capital(letter) is not None for letter in WORD), "Noto Sans has an smcp small capital for every letter of the specimen word"
        assert not any(programs[face].small_capitals for face in (REGULAR, BOLD, ITALIC, BOLD_ITALIC)), "Lato has no smcp feature: its small capitals are synthesized everywhere"
        # Scale: the programs separate the weights exactly as SCALE_CLASSES says.
        classes = {}
        for weight in WEIGHTS:
            classes.setdefault(family_face(self.bindings["families"]["Scale"]["faces"], {"style": "normal", "variant": "normal", "weight": weight}, NORM)["face"], []).append(weight)
        facts["scaleClasses"] = list(classes.values())
        assert facts["scaleClasses"] == SCALE_CLASSES, "Scale must draw 100, 400, 500, 700 and 900 with five different programs"
        # Every rival rule changes what the converter would draw for its samples, or where it would set a repeated block.
        facts["separations"] = {}
        block = lambda row: (row["sizePx"], row["indentPx"], row.get("lineHeight"))
        for name, other in RIVALS.items():
            drawn = self.following({**NORM, **other["rules"]}).layout("pdf")
            differing = [identifier for identifier in self.source.order
                         if (block(drawn["rows"][identifier]) != block(pdf["rows"][identifier]) if identifier in pdf["rows"] else drawing(drawn["samples"][identifier]) != drawing(pdf["samples"][identifier]))]
            assert other["separates"] and set(other["separates"]) <= set(differing), "The witness must separate the norm from: " + name
            facts["separations"][name] = [identifier for identifier in differing if identifier not in pdf["rows"] or identifier in other["separates"]]
        # Every known deviation is carried by at least one sample, visibly.
        facts["deviations"] = {}
        for browser in BROWSERS:
            other = self.layout(browser)
            facts["deviations"][browser] = {identifier: self.deviation(identifier, browser) for identifier in pdf["samples"] if self.deviation(identifier, browser)}
            assert all(abs(other["samples"][identifier]["widthPx"] - pdf["samples"][identifier]["widthPx"]) > 8 * pixel for identifier in facts["deviations"][browser]), "A deviation must change the width of its sample"
            assert all(row["endPx"] <= CONTENT_WIDTH_PX - 1 for row in other["rows"].values()), "Every row must fit on one line"
        named = {"relative-size-ratio", "normal-line-height", *(name for names in facts["deviations"].values() for name in names.values())}
        assert named == set(KNOWN_DEVIATIONS), "Every known deviation must be carried by a sample, and nothing else"
        heights = {row["lineHeight"] == "normal" for row in pdf["rows"].values() if row.get("repeats")}
        assert heights == {True, False}, "The shorthand examples must cover explicit and normal line heights"
        assert all(INHERITED["lineHeight"] > 1.5 * NORMAL_LINE_HEIGHT_RANGE[1] * row["sizePx"] for row in pdf["rows"].values() if row.get("lineHeight") == "normal"), \
            "The inherited line height must lie far outside the range accepted for normal"
        assert all(row["endPx"] <= CONTENT_WIDTH_PX - 1 for row in pdf["rows"].values()), "Every row must fit on one line"
        notes = [(note["code"], note["circumstance"]) for note in self.intended_diagnostics()]
        assert len(set(notes)) == len(notes), "Every intended diagnostic must have its own circumstance"
        return facts


def drawing(sample):
    return [[segment["text"], segment["drawn"], segment["face"], segment["sizePx"], segment["feature"]] for segment in sample["segments"]]


class EmbeddedFont:
    """One font resource: the supplied program its FontFile2 carries, its code maps and its declared widths."""

    def __init__(self, font, programs):
        self.maps = FontTextMap(font)
        descendant = font["/DescendantFonts"][0].get_object()
        program = descendant["/FontDescriptor"]["/FontFile2"].get_data()
        self.key, self.sha256, self.base = identify_program(program, programs), digest(program), str(font["/BaseFont"])
        self.default, self.widths, entries, index = float(descendant.get("/DW", 1000)), {}, [entry.get_object() for entry in descendant.get("/W", [])], 0
        while index < len(entries):
            if isinstance(entries[index + 1], list):
                self.widths.update({int(entries[index]) + offset: float(width) for offset, width in enumerate(entries[index + 1])})
                index += 2
            else:
                self.widths.update({glyph: float(entries[index + 2]) for glyph in range(int(entries[index]), int(entries[index + 1]) + 1)})
                index += 3

    def decode(self, raw):
        assert raw in self.maps.encoding.mapping and raw in self.maps.unicode.mapping, "Text uses an unmapped character code"
        return self.maps.encoding.mapping[raw], self.maps.unicode.mapping[raw]

    def width(self, glyph):
        return self.widths.get(glyph, self.default)


def read_commands(operations, fonts, page):
    """Strict profile of one page: every ActualText scope holds one BT/ET with one Tf, and every glyph is one
    two-byte Tj placed by its own text matrix, which may only translate and never goes back."""
    commands, scopes, saved, command, matrix = [], [], 0, None, None
    for index, (operands, operator) in enumerate(operations):
        assert index <= 300_000 and len(commands) <= 20_000, "Bounded content required"
        assert operator in OPERATORS, "Operator outside the strict text profile: " + operator.decode("latin-1")
        if operator == b"BDC":
            assert len(operands) == 2 and isinstance(operands[1], DictionaryObject), "Inline marked-content properties required"
            scope = None
            if "/ActualText" in operands[1]:
                raw = PdfTextOracle.original_bytes(operands[1]["/ActualText"])
                assert raw.startswith(b"\xfe\xff"), "ActualText must explicitly declare UTF-16BE"
                assert all(outer is None for outer in scopes), "No nested ActualText scopes"
                scope = {"actual": raw[2:].decode("utf-16-be", errors="strict"), "commands": 0}
            scopes.append(scope)
        elif operator == b"EMC":
            assert scopes and command is None, "Unbalanced marked content"
            scope = scopes.pop()
            assert scope is None or scope["commands"] == 1, "Every ActualText scope holds exactly one text command"
        elif operator == b"q":
            saved += 1
        elif operator == b"Q":
            assert saved > 0 and command is None, "Unbalanced graphics state"
            saved -= 1
        elif operator == b"BT":
            scope = next((outer for outer in scopes if outer is not None), None)
            assert command is None and scope is not None and scope["commands"] == 0, "Every text command needs its own ActualText scope"
            scope["commands"] += 1
            command, matrix = {"page": page, "actual": scope["actual"], "font": None, "size": None, "y": None, "glyphs": [], "declared": []}, None
        elif operator == b"ET":
            assert command is not None and command["glyphs"], "A text command draws at least one glyph"
            commands.append(command)
            command = None
        elif operator in (b"rg", b"g"):
            assert command is None, "Colours are set outside text objects"
        else:
            assert command is not None, "Text operator outside a text object"
            numbers = [float(value) for value in operands[1 if operator == b"Tf" else 0:]] if operator != b"Tj" else []
            assert all(math.isfinite(number) for number in numbers), "Finite operands required"
            if operator == b"Tf":
                assert command["font"] is None and len(operands) == 2 and str(operands[0]) in fonts, "One known font and size per text command"
                command["resource"], command["font"], command["size"] = fonts[str(operands[0])], fonts[str(operands[0])].key, numbers[0]
                assert command["size"] > 0, "Positive font size required"
            elif operator == b"Tm":
                assert len(numbers) == 6 and numbers[:4] == [1, 0, 0, 1], "Text matrices may only translate: no synthetic oblique, emboldening or scaling"
                matrix = (numbers[4], numbers[5])
            elif operator == b"Tj":
                assert command["font"] is not None and matrix is not None, "Every glyph needs a font and its own text matrix"
                raw = PdfTextOracle.original_bytes(operands[0])
                assert len(raw) == 2, "Separately positioned two-byte glyph operands required"
                glyph, text = command["resource"].decode(raw)
                assert command["y"] in (None, matrix[1]), "One baseline per text command"
                # Only a glyph without advance, a combining mark, may be followed by a glyph at the same place.
                assert not command["glyphs"] or matrix[0] > command["glyphs"][-1]["x"] or (matrix[0] == command["glyphs"][-1]["x"] and command["declared"][-1] == 0), \
                    "Glyphs advance from left to right: no overstrike"
                command["y"] = matrix[1]
                command["glyphs"].append({"gid": glyph, "text": text, "x": matrix[0]})
                command["declared"].append(command["resource"].width(glyph))
                matrix = None
            else:
                assert numbers == [100 if operator == b"Tz" else 0], "No character spacing, word spacing, horizontal scaling or rise"
    assert not scopes and saved == 0 and command is None, "Unbalanced marked content, graphics state or text object"
    for command in commands:
        del command["resource"]
    return commands


def observe(reader, programs):
    """Text commands of every page, with the supplied program each of them draws."""
    commands, fonts = [], {}
    for number, page in enumerate(reader.pages, 1):
        resources = {str(name): EmbeddedFont(font.get_object(), programs) for name, font in page["/Resources"]["/Font"].get_object().items()}
        contents = page.get_contents()
        assert contents is not None and len(contents.get_data()) <= 16_777_216, "Bounded decoded page contents required"
        commands += read_commands(ContentStream(contents, reader).operations, resources, number)
        fonts[str(number)] = {name: {"program": font.key, "baseFont": font.base, "fontFile2Sha256": font.sha256, "codes": len(font.maps.encoding.mapping)}
                              for name, font in resources.items()}
    return {"pages": len(reader.pages), "fonts": fonts, "commands": commands}


class Checks(list):
    def add(self, case, name, expected, actual, passed, **extra):
        self.append({"case": case, "property": name, "expected": expected, "actual": actual, "passed": bool(passed), **extra})

    def exact(self, case, name, expected, actual, **extra):
        self.add(case, name, expected, actual, actual == expected, **extra)

    def near(self, case, name, expected, actual, tolerance, **extra):
        self.add(case, name, expected, actual, near(actual, expected, tolerance), **extra)


def compare_pdf(witness, observed):
    """The text commands of the PDF against the norm-derived layout. An incomplete inventory is an error, not a failure."""
    assert isinstance(observed, dict) and isinstance(observed.get("commands"), list) and observed["commands"], "Observations required"
    expected, source, programs, commands = witness.layout("pdf"), witness.source, witness.programs, observed["commands"]
    checks, measured, tolerance = Checks(), {}, TOLERANCE["pdfPt"]
    assert compact("".join(command["actual"] for command in commands)) == compact(source.text), "Complete fixture inventory required: the text commands must restitute the whole source in order"
    starts, total = [], 0
    for command in commands:
        starts.append(total)
        total += len(compact(command["actual"]))
    decoded = lambda command: "".join(glyph["text"] for glyph in command["glyphs"])
    advances = lambda command: [programs[command["font"]].advance(glyph["gid"]) * command["size"] for glyph in command["glyphs"]]
    end = lambda command: command["glyphs"][0]["x"] + sum(advances(command))

    checks.exact("document", "pages", PAGES, observed.get("pages"))
    wrong = [command["actual"] for command in commands if decoded(command) != command["actual"]]
    checks.exact("document", "decodedText", [], wrong[:5], count=len(wrong))
    wrong = [command["actual"] for command, start in zip(commands, starts) if compact(command["actual"]) and command["page"] != 1 + sum(1 for offset in source.breaks if offset <= start)]
    checks.exact("document", "forcedPageBreaks", [], wrong[:5], count=len(wrong), breaks=source.breaks)
    # The widths a viewer reads from /W are the hmtx advances of the supplied program, in thousandths of the em.
    wrong = [abs(width - programs[command["font"]].advance(glyph["gid"]) * 1000) if len(command.get("declared", [])) == len(command["glyphs"]) else math.inf
             for command in commands for glyph, width in zip(command["glyphs"], command.get("declared", command["glyphs"]))]
    checks.add("document", "widthsArray", 0, max(wrong) if math.isfinite(max(wrong)) else None, max(wrong) <= TOLERANCE["widthsArray"], glyphs=len(wrong))

    previous_row = None
    for identifier, row in expected["rows"].items():
        element = source.elements[identifier]
        inside = [index for index, start in enumerate(starts) if compact(commands[index]["actual"]) and element["offset"] <= start < element["offset"] + element["length"]]
        assert inside and starts[inside[0]] == element["offset"] and starts[inside[-1]] + len(compact(commands[inside[-1]]["actual"])) == element["offset"] + element["length"], \
            "Complete fixture inventory required: " + identifier + " does not start and end on text command boundaries"
        actual = commands[inside[0]:inside[-1] + 1]
        checks.exact(identifier, "commands", [command["text"] for command in row["commands"]], [command["actual"] for command in actual])
        first = actual[0]
        if previous_row is not None and previous_row["page"] == first["page"]:
            checks.add(identifier, "belowPreviousRow", "< " + repr(previous_row["y"]), first["y"], first["y"] < previous_row["y"])
        previous_row = first
        if len(actual) != len(row["commands"]):
            continue
        for index, (wanted, command) in enumerate(zip(row["commands"], actual)):
            case, glyphs = wanted["sample"] or identifier, command["glyphs"]
            extra = {"command": index, "segment": wanted["text"]}
            checks.exact(case, "text", wanted["text"], command["actual"], **extra)
            checks.exact(case, "decodedText", wanted["text"], decoded(command), **extra)
            checks.exact(case, "face", wanted["face"], command["font"], **extra)
            checks.near(case, "fontSizePt", wanted["sizePx"] * PX_TO_PT, command["size"], tolerance, **extra)
            checks.exact(case, "glyphs", [glyph["gid"] for glyph in wanted["glyphs"]], [glyph["gid"] for glyph in glyphs], drawn=wanted["drawn"], **extra)
            steps = [abs(after["x"] - before["x"] - advance) for before, after, advance in zip(glyphs, glyphs[1:], advances(command))]
            checks.add(case, "glyphAdvancesPt", 0, max(steps, default=0), max(steps, default=0) <= tolerance, **extra)
            checks.near(case, "xPt", CONTENT_LEFT_PT + wanted["xPx"] * PX_TO_PT, glyphs[0]["x"], tolerance, **extra)
            checks.exact(case, "baseline", [first["page"], first["y"]], [command["page"], command["y"]], **extra)
            if index:
                checks.near(case, "startAfterPreviousAdvances", end(actual[index - 1]), glyphs[0]["x"], tolerance, **extra)
        for name in row["samples"]:
            wanted = expected["samples"][name]
            own = [command for command, target in zip(actual, row["commands"]) if target["sample"] == name]
            # Advance of every cluster (a base character with its marks), from the glyphs the model assigns to it.
            drawn, letters = [advance for command in own for advance in advances(command)], []
            for letter in wanted["characters"]:
                letters.append(sum(drawn[:letter["glyphs"]]))
                drawn = drawn[letter["glyphs"]:]
            measured[name] = {"page": own[0]["page"], "y": own[0]["y"], "xPt": own[0]["glyphs"][0]["x"], "widthPt": end(own[-1]) - own[0]["glyphs"][0]["x"],
                              "faces": sorted({command["font"] for command in own}), "sizesPt": [command["size"] for command in own], "characterAdvancesPt": letters}
            checks.near(name, "widthPt", wanted["widthPx"] * PX_TO_PT, measured[name]["widthPt"], tolerance)
            if any(letter["sizePx"] != wanted["sizePx"] for letter in wanted["characters"]):
                scale = min(command["size"] for command in own) / (wanted["sizePx"] * PX_TO_PT)
                checks.near(name, "smallCapsScale", SMALL_CAPS_SCALE["pdf"], scale, 1e-9, chromium=SMALL_CAPS_SCALE["chromium"], knownDeviation="chromium-small-caps-scale")

    # Two identical one-line blocks without margins: the second baseline is one used line height below the first.
    for identifier, row in expected["rows"].items():
        if row.get("repeats"):
            step, size = line_step(expected, measured, identifier), row["sizePx"] * PX_TO_PT
            if row["lineHeight"] != "normal":
                checks.near(identifier, "lineStepPt", row["lineHeight"] * PX_TO_PT, step, tolerance)
            else:
                low, high = (factor * size for factor in NORMAL_LINE_HEIGHT_RANGE)
                checks.add(identifier, "lineStepPt", "a value of the user agent from %g to %gpt, not the inherited %gpt" % (low, high, INHERITED["lineHeight"] * PX_TO_PT), step,
                           step is not None and low - tolerance <= step <= high + tolerance, fontSizes=None if step is None else step / size)

    # The CSS1 example (larger from medium is large) must stay unreproduced: the ratio is 1.2, not a table step.
    size = lambda name: measured[name]["sizesPt"][0] if name in measured else None
    for relative, step, difference in (("larger-from-medium", "absolute-12-large", 16 * RELATIVE_RATIO - 18), ("smaller-from-medium", "absolute-12-small", 16 / RELATIVE_RATIO - 13),
                                       ("smaller-from-small", "absolute-12-x-small", 13 / RELATIVE_RATIO - 10)):
        actual = size(relative) - size(step) if None not in (size(relative), size(step)) else None
        checks.add(relative, "differenceFromTableStepPt", difference * PX_TO_PT, actual, near(actual, difference * PX_TO_PT, tolerance) and abs(actual) > 0.1,
                   tableStep=step, knownDeviation="relative-size-ratio")
    return checks, measured


def line_step(layout, measured, identifier):
    """Distance in points between the baseline of a repeated block and the baseline of the block it repeats."""
    first, again = (measured.get(layout["rows"][name]["samples"][0]) for name in (layout["rows"][identifier]["repeats"], identifier))
    return first["y"] - again["y"] if first and again and first["page"] == again["page"] else None


def target_view(witness):
    """The model in the shape of the pdfTargets of validate_fonts.cjs, for comparison between the two derivations."""
    pdf, browsers = witness.layout("pdf"), {name: witness.layout(name) for name in BROWSERS}
    view = lambda item: [[segment["text"], segment["drawn"], segment["face"], segment["sizePx"], segment["widthPx"]] for segment in item["segments"]]
    samples = {}
    for identifier, item in pdf["samples"].items():
        element = witness.source.elements[identifier]
        others = {name: browsers[name]["samples"][identifier] for name in BROWSERS}
        samples[identifier] = {"row": item["row"], "text": item["text"], "compact": {"offset": element["offset"], "length": element["length"]},
                               "fontSizePx": item["sizePx"], "fontWeight": item["weight"], "fontStyle": item["style"], "fontVariant": item["variant"], "face": item["face"],
                               "xPx": item["xPx"], "widthPx": item["widthPx"], "segments": view(item),
                               "witnessedBy": [name for name in BROWSERS if drawing(others[name]) == drawing(item) and abs(others[name]["xPx"] - item["xPx"]) < 1e-9],
                               "knownDeviations": {name: witness.deviation(identifier, name) for name in BROWSERS if witness.deviation(identifier, name)}}
    return {"rows": {identifier: {key: value for key, value in row.items() if key not in ("commands", "endPx")} for identifier, row in pdf["rows"].items()}, "samples": samples,
            "intendedDiagnostics": sorted((note["sample"], note["code"], note["circumstance"]) for note in witness.intended_diagnostics())}


def compare_targets(witness, targets):
    """Two derivations of the norm must agree: this model and the pdfTargets written by the browser oracle."""
    own, checks = target_view(witness), Checks()
    assert isinstance(targets, dict) and list(targets.get("rows", {})) == list(own["rows"]) and list(targets.get("samples", {})) == list(own["samples"]), \
        "Complete fixture inventory required: the browser report targets list different elements"
    same = lambda left, right: left == right or (isinstance(left, float) or isinstance(right, float)) and near(left, right, 1e-9) and near(right, left, 1e-9)
    checks.exact("document", "normContentLeftPt", CONTENT_LEFT_PT, targets.get("contentLeftPt"))
    checks.exact("document", "normIntendedDiagnostics", own["intendedDiagnostics"], sorted((item.get("sample"), item.get("code"), item.get("circumstance")) for item in targets.get("intendedDiagnostics", [])))
    for identifier, row in own["rows"].items():
        theirs = targets["rows"][identifier]
        checks.add(identifier, "normTarget", row, theirs, set(row) == set(theirs) and all(same(row[key], theirs[key]) for key in row))
    for identifier, item in own["samples"].items():
        theirs = dict(targets["samples"][identifier])
        theirs["segments"] = [[segment.get(key) for key in ("text", "drawn", "face", "fontSizePx", "widthPx")] for segment in theirs.get("segments", [])]
        passed = all(same(item[key], theirs.get(key)) for key in item if key != "segments") and len(item["segments"]) == len(theirs["segments"]) \
            and all(same(left, right) for mine, other in zip(item["segments"], theirs["segments"]) for left, right in zip(mine, other))
        checks.add(identifier, "normTarget", item, {key: theirs.get(key) for key in item}, passed)
    return checks


def compare_browser(witness, measured, browser, observation):
    """The PDF against one browser: same advances where the browser draws the same faces, asserted deviation elsewhere."""
    assert browser in BROWSERS, "Unknown browser " + str(browser)
    pdf, model, checks = witness.layout("pdf"), witness.layout(browser), Checks()
    assert isinstance(observation, dict) and observation.get("order") == witness.source.order and set(observation.get("elements", {})) == set(witness.source.order), \
        "Complete fixture inventory required: " + browser + " observed different elements"
    elements, width_tolerance, pixel = observation["elements"], TOLERANCE["browserWidthPt"], TOLERANCE["browserWidthPt"] / PX_TO_PT
    number = lambda value: value if isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value) else None

    def line_height(computed):
        value = computed.get("lineHeight")
        return value if value == "normal" else float(value[:-2]) if isinstance(value, str) and re.fullmatch(r"\d+(\.\d+)?px", value) else None

    normal = {}
    for identifier, row in pdf["rows"].items():
        computed = elements[identifier].get("computed", {})
        passed = near(computed.get("fontSizePx"), row["sizePx"], TOLERANCE["computedPx"]) and near(computed.get("textIndentPx"), row["indentPx"], TOLERANCE["computedPx"])
        wanted, seen = {"fontSizePx": row["sizePx"], "textIndentPx": row["indentPx"]}, {key: computed.get(key) for key in ("fontSizePx", "textIndentPx")}
        if "lineHeight" in row:
            wanted["lineHeight"], seen["lineHeight"] = row["lineHeight"], line_height(computed)
            passed = passed and (seen["lineHeight"] == "normal" if row["lineHeight"] == "normal" else near(seen["lineHeight"], row["lineHeight"], TOLERANCE["browserLineStepPt"] / PX_TO_PT))
        checks.add(identifier, "browserComputed", wanted, seen, passed, browser=browser)
        if row.get("repeats"):
            mine, top, above = line_step(pdf, measured, identifier), number(elements[identifier].get("y")), number(elements[row["repeats"]].get("y"))
            theirs = None if None in (top, above) else (top - above) * PX_TO_PT
            if None in (mine, theirs):
                checks.add(identifier, "browserLineStepPt", "a line step measured in the PDF and in the browser", None, False, browser=browser)
            elif row["lineHeight"] != "normal":
                checks.near(identifier, "browserLineStepPt", theirs, mine, TOLERANCE["browserLineStepPt"], browser=browser)
            else:
                # The browser must still use the hhea metrics of the face it draws; the difference is asserted below.
                metrics = witness.programs[model["samples"][row["samples"][0]]["segments"][0]["face"]].line_spacing * row["sizePx"] * PX_TO_PT
                checks.near(identifier, "browserNormalLineStepPt", metrics, theirs, TOLERANCE["browserNormalLineStepPt"], pdf=mine, browser=browser, knownDeviation="normal-line-height")
                normal[identifier] = abs(mine - theirs)
    # The known deviation must persist: at least one example is set with another normal line height than the browser's,
    # beyond the rounding of the browser.
    checks.add("document", "browserNormalLineHeightDiffersPt", "> %g in at least one example" % TOLERANCE["browserNormalLineStepPt"], normal,
               any(difference > TOLERANCE["browserNormalLineStepPt"] for difference in normal.values()), browser=browser, knownDeviation="normal-line-height")

    for identifier, wanted in pdf["samples"].items():
        element, drawn, mine, known = elements[identifier], model["samples"][identifier], measured.get(identifier), witness.deviation(identifier, browser)
        sample = {key: wanted[key] for key in ("id", "text", "sizePx", "weight", "style", "variant", "families")}
        extra = {"browser": browser, **({"knownDeviation": known} if known else {})}
        computed = element.get("computed", {})
        norm = {"text": wanted["text"], "fontWeight": wanted["weight"], "fontStyle": wanted["style"], "fontVariant": wanted["variant"], "families": wanted["families"]}
        seen = {"text": element.get("text"), **{key: computed.get(key) for key in ("fontWeight", "fontStyle", "fontVariant")}, "families": element.get("families")}
        checks.add(identifier, "browserComputed", {"fontSizePx": wanted["sizePx"], **norm}, {"fontSizePx": computed.get("fontSizePx"), **seen},
                   near(computed.get("fontSizePx"), wanted["sizePx"], TOLERANCE["computedPx"]) and seen == norm, browser=browser)
        width, letters = element.get("width"), element.get("characters") if isinstance(element.get("characters"), list) else []
        if mine is None or not near(width, 0, math.inf) or not near(element.get("x"), 0, math.inf):
            checks.add(identifier, "browserWidthPt", "a sample measured in the PDF and in the browser", None, False, **extra)
            continue
        # Supplied programs whose own advances, under the rules of the browser, explain what it measured. A one-face
        # sample identifies that face when no other program comes within four tolerances of its width; the browser
        # oracle must then have classified it the same way.
        whole, classified = witness.candidates(sample, browser, width), element.get("face")
        identifies = drawn["face"] is not None and witness.candidates(sample, browser, drawn["widthPx"], 4) == [drawn["face"]]
        agreed = (whole == [drawn["face"]] and classified == drawn["face"]) if identifies else (drawn["face"] is None or drawn["face"] in whole) and classified in (None, drawn["face"])
        if known is None:
            checks.near(identifier, "browserWidthPt", width * PX_TO_PT, mine["widthPt"], width_tolerance, **extra)
            worst = max((abs(letter.get("width", math.inf) * PX_TO_PT - advance) if letter.get("text") == cluster["text"] else math.inf
                         for letter, advance, cluster in zip(letters, mine["characterAdvancesPt"], wanted["characters"])), default=math.inf)
            checks.add(identifier, "browserCharacterAdvancesPt", 0, None if math.isinf(worst) else worst, len(letters) == len(wanted["characters"]) and worst <= width_tolerance, **extra)
            # Every cluster must admit the face the PDF draws it with.
            explained = [witness.candidates({**sample, "text": cluster["text"]}, browser, observed.get("width")) for observed, cluster in zip(letters, wanted["characters"])]
            faces = [cluster["face"] for cluster in wanted["characters"]]
            passed = len(explained) == len(faces) and all(face in found for face, found in zip(faces, explained)) and mine["faces"] == sorted(set(faces)) and agreed
            checks.add(identifier, "browserFace", mine["faces"], {"sample": whole, "characters": explained, "classifiedByBrowserOracle": classified}, passed, identifies=identifies, **extra)
        else:
            # The browser must still draw what the deviation describes, and that must still differ from the PDF.
            visible = abs(mine["widthPt"] - width * PX_TO_PT) > 4 * width_tolerance
            checks.add(identifier, "browserDeviationWidthPt", drawn["widthPx"] * PX_TO_PT, width * PX_TO_PT, near(width, drawn["widthPx"], pixel) and visible, pdf=mine["widthPt"], **extra)
            if any(letter["sizePx"] != drawn["sizePx"] for letter in drawn["characters"]):
                kept = sum(letter["widthPx"] for letter in drawn["characters"] if letter["sizePx"] == drawn["sizePx"])
                full = sum(letter["widthPx"] * drawn["sizePx"] / letter["sizePx"] for letter in drawn["characters"] if letter["sizePx"] != drawn["sizePx"])
                checks.near(identifier, "browserSmallCapsScale", SMALL_CAPS_SCALE[browser], (width - kept) / full, TOLERANCE["smallCapsScale"], pdf=SMALL_CAPS_SCALE["pdf"], **extra)
            if known != "chromium-small-caps-scale":
                checks.add(identifier, "browserDeviationFace", [drawn["face"]], {"sample": whole, "classifiedByBrowserOracle": classified},
                           agreed and identifies and (drawn["feature"] or whole != mine["faces"]), pdf=mine["faces"], feature=drawn["feature"], **extra)
        # Position on the line, after the samples the browser draws differently have moved it.
        shift = (wanted["xPx"] - drawn["xPx"]) * PX_TO_PT
        checks.near(identifier, "browserXPt", shift, mine["xPt"] - CONTENT_LEFT_PT - element["x"] * PX_TO_PT, TOLERANCE["browserXPt"], **extra)
    return checks


def text_breaks(text):
    """Offsets, in the text without white space, at which white space occurs."""
    breaks, offset = set(), 0
    for character in text:
        if character.isspace():
            breaks.add(offset)
        else:
            offset += 1
    return breaks - {0, offset}


def poppler_checks(witness, raw, reading):
    """Poppler in content order (-raw) keeps the source without a space inside a word; in reading order it keeps every word."""
    normal = lambda text: unicodedata.normalize("NFC", text.replace("\f", " "))
    source, raw, reading, checks = normal(witness.source.text), normal(raw), normal(reading), Checks()
    wanted, found = compact(source), compact(raw)
    different = next((index for index, (left, right) in enumerate(zip(wanted, found)) if left != right), min(len(wanted), len(found)))
    checks.exact("document", "popplerText", len(wanted), len(found) if wanted == found else {"characters": len(found), "firstDifference": different, "found": found[different:different + 20]})
    inserted = sorted(text_breaks(raw) - text_breaks(source))
    checks.exact("document", "popplerInsertedSpaces", [], inserted[:10], count=len(inserted))
    missing = Counter(source.split()) - Counter(reading.split())
    extra = Counter(reading.split()) - Counter(source.split())
    checks.exact("document", "popplerWords", {}, {"missing": dict(missing), "unexpected": dict(extra)} if missing or extra else {}, words=len(source.split()))
    # Words cut by a change of face or size must come out whole.
    for identifier, sample in witness.layout("pdf")["samples"].items():
        if len(sample["segments"]) > 1:
            checks.exact(identifier, "popplerWholeWord", [source.split().count(sample["text"])] * 2, [raw.split().count(sample["text"]), reading.split().count(sample["text"])],
                         segments=[segment["text"] for segment in sample["segments"]])
    return checks


def render_checks(images, measured, pages=PAGES):
    """Every page renders at A4 size with ink, and every measured sample is painted where the PDF places it."""
    checks, scale = Checks(), RENDER_DPI / 72
    checks.exact("document", "renderedPages", pages, len(images))
    inks = []
    for number, image in enumerate(images, 1):
        ink = image.convert("L").point(lambda value: 255 if value < 160 else 0)
        inks.append(ink)
        checks.add("page-%d" % number, "renderedSize", [round(PAGE_WIDTH_PT * scale), round(PAGE_HEIGHT_PT * scale)], list(image.size),
                   abs(image.width - PAGE_WIDTH_PT * scale) <= 1 and abs(image.height - PAGE_HEIGHT_PT * scale) <= 1)
        dark = ink.histogram()[255]
        checks.add("page-%d" % number, "renderedInk", ">= 2000 dark pixels", dark, dark >= 2000)
    for identifier, sample in measured.items():
        if not 1 <= sample["page"] <= len(inks):
            checks.add(identifier, "renderedInk", "rendered page", sample["page"], False)
            continue
        ink, size = inks[sample["page"] - 1], max(sample["sizesPt"])
        box = (math.floor(sample["xPt"] * scale), math.floor((PAGE_HEIGHT_PT - sample["y"] - 0.75 * size) * scale),
               math.ceil((sample["xPt"] + sample["widthPt"]) * scale), math.ceil((PAGE_HEIGHT_PT - sample["y"]) * scale))
        dark = ink.crop(box).histogram()[255]
        checks.add(identifier, "renderedInk", ">= 4 dark pixels", dark, dark >= 4, box=list(box))
    return checks


def receipt(record, pdf, witness):
    """The manifest describes this PDF, this source, these bindings and nothing but the intended diagnostics."""
    assert record["engine"] == "native-php", "The PDF must come from the native converter"
    assert record["sourceSha256"] == digest(witness.source_bytes), "The manifest names another source"
    assert record["bindingsSha256"] == digest(witness.binding_bytes) and record["bindings"] == witness.bindings, "The manifest names other bindings"
    assert record["fontFiles"] == {entry["file"]: entry["sha256"] for entry in witness.files.values()}, "The manifest names other font files"
    assert record["pdfSha256"] == digest(pdf), "The manifest names another PDF"
    assert record["pages"] == PAGES, "One page per forced break and no overflow expected"
    # One informative note per circumstance the model derives from the documented meaning of each code.
    assert sorted(item["code"] for item in record["diagnostics"]) == sorted(note["code"] for note in witness.intended_diagnostics()), \
        "Exactly the intended informative diagnostics are expected"
    return record


def browser_receipt(report, witness):
    """The browser report is a passing run over the same source, bindings, font files and inventory."""
    assert report["status"] == "pass", "The browser oracle must have passed"
    assert report["sourceSha256"] == digest(witness.source_bytes) and report["bindingsSha256"] == digest(witness.binding_bytes), "The browser report names another source or other bindings"
    assert report["fontFiles"] == witness.files, "The browser report names other font files"
    assert set(report["browsers"]) == set(BROWSERS) and all(report["browsers"][name]["passed"] is True for name in BROWSERS), "Both browsers must have passed"
    assert set(report["knownDeviations"]) == set(KNOWN_DEVIATIONS), "Both oracles must assert the same known deviations"
    assert report["cssPxToPdfPt"] == PX_TO_PT and report["contentLeftCssPx"] * PX_TO_PT == CONTENT_LEFT_PT, "The browser harness must use the same page model"
    assert report["inventory"] == witness.source.order and report["compactTextLength"] == len(compact(witness.source.text)), \
        "Complete fixture inventory required: the browser report lists other elements"
    return {name: report["browsers"][name]["version"] for name in BROWSERS}


class FontsPdfOracle:
    def __init__(self, pdf, manifest, output, browser_report, qpdf="qpdf", pdftotext="pdftotext", pdftoppm="pdftoppm"):
        self.pdf, self.manifest, self.output, self.browser_report = Path(pdf), Path(manifest), Path(output), Path(browser_report)
        self.qpdf, self.pdftotext, self.pdftoppm = qpdf, pdftotext, pdftoppm

    def inspect(self):
        self.output.mkdir(parents=True, exist_ok=True)
        report = {"status": "fail", "scope": "CSS1 5.2 font properties in the emitted PDF: embedded programs, sizes, glyphs, advances, line steps of the font "
                                             "shorthand, Unicode text and rendering of the identified samples of fonts.html; no complete CSS1 or PDF conformance claim",
                  "pypdf": pypdf.__version__, "pillow": PIL.__version__, "tolerance": TOLERANCE, "knownDeviations": KNOWN_DEVIATIONS}
        try:
            assert self.pdf.stat().st_size <= 16_777_216 and self.manifest.stat().st_size <= 1_048_576 and self.browser_report.stat().st_size <= 8_388_608, "Bounded inputs required"
            witness, pdf = Witness(), self.pdf.read_bytes()
            report["design"] = witness.design()
            report["receipt"] = receipt(json.loads(self.manifest.read_text(encoding="utf-8")), pdf, witness)
            browsers = json.loads(self.browser_report.read_text(encoding="utf-8"))
            report["browsers"] = browser_receipt(browsers, witness)
            report["qpdf"] = run(self.qpdf, "--check", str(self.pdf))
            reader = PdfReader(self.pdf, strict=True)
            assert len(reader.pages) == PAGES, "One page per forced break expected"
            assert reader.trailer["/Root"]["/MarkInfo"]["/Marked"] and "/StructTreeRoot" in reader.trailer["/Root"], "Tagged PDF expected"
            for page in reader.pages:
                assert abs(float(page.mediabox.width) - PAGE_WIDTH_PT) < .01 and abs(float(page.mediabox.height) - PAGE_HEIGHT_PT) < .01, "A4 pages expected"
                assert tuple(page.cropbox) == tuple(page.mediabox) and int(page.get("/Rotate", 0)) == 0 and float(page.get("/UserUnit", 1)) == 1, "Unrotated, unscaled pages expected"
            report["textOracle"] = {key: value for key, value in PdfTextOracle().inspect(reader).items() if key != "text"}
            observed = observe(reader, witness.programs)
            report["fonts"] = observed["fonts"]
            assert {font["program"] for fonts in observed["fonts"].values() for font in fonts.values()} == set(witness.programs), "Every supplied program is embedded, and nothing else"
            checks, measured = compare_pdf(witness, observed)
            checks += compare_targets(witness, browsers["pdfTargets"])
            for name in BROWSERS:
                checks += compare_browser(witness, measured, name, browsers["browsers"][name]["observed"])
            raw = run(self.pdftotext, "-raw", "-enc", "UTF-8", str(self.pdf), "-")
            reading = run(self.pdftotext, "-enc", "UTF-8", str(self.pdf), "-")
            (self.output / "poppler-text.txt").write_text(raw, encoding="utf-8")
            (self.output / "poppler-reading-order.txt").write_text(reading, encoding="utf-8")
            checks += poppler_checks(witness, raw, reading)
            for stale in self.output.glob("native-*.png"):
                stale.unlink()
            run(self.pdftoppm, "-r", str(RENDER_DPI), "-png", str(self.pdf), str(self.output / "native"))
            renders = sorted(self.output.glob("native-*.png"))
            report["renders"] = [{"file": path.name, "sha256": digest(path.read_bytes())} for path in renders]
            checks += render_checks([Image.open(path) for path in renders], measured)
            asserted = Counter(check["knownDeviation"] for check in checks if "knownDeviation" in check)
            assert set(asserted) == set(KNOWN_DEVIATIONS), "Every known deviation must be asserted by at least one comparison"
            report.update(inventory={"rows": len(witness.rows), "samples": len(measured), "textCommands": len(observed["commands"])},
                          samples=measured, checks=checks, comparisons=len(checks), knownDeviationChecks=dict(asserted),
                          failed=[check["case"] + ":" + check["property"] for check in checks if not check["passed"]][:50])
            assert all(check["passed"] for check in checks), "PDF fonts, sizes, glyphs, geometry, text or rendering differ from the norm or from the browsers: " + ", ".join(report["failed"][:8])
            report["status"] = "pass"
        except Exception as error:
            report["error"] = str(error) or type(error).__name__
        (self.output / "pdf-report.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pdf", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--browser-report", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--pdftoppm", default="pdftoppm")
    args = parser.parse_args()
    report = FontsPdfOracle(args.pdf, args.manifest, args.output, args.browser_report, args.qpdf, args.pdftotext, args.pdftoppm).inspect()
    print(json.dumps({"status": report["status"], "comparisons": report.get("comparisons"), "knownDeviationChecks": report.get("knownDeviationChecks"), "error": report.get("error")}))
    return 0 if report["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
