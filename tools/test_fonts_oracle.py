"""Positive and negative controls of the font PDF oracle: falsified observations must fail, without any PDF or browser."""
from __future__ import annotations

from collections import Counter
import copy
import json
from pathlib import Path
import struct
import sys
import tempfile
import unittest

from PIL import Image, ImageDraw
from pypdf.generic import ByteStringObject, DictionaryObject, FloatObject, NameObject, NumberObject

sys.path.insert(0, str(Path(__file__).resolve().parent))  # also when loaded as tools/test_fonts_oracle.py from the repository root

from validate_fonts import (ABSOLUTE_PX, BOLD, BOLD_ITALIC, BOLDER, BROWSERS, CONTENT_LEFT_PT, INHERITED, ITALIC, KNOWN_DEVIATIONS, LIGHTER, NORM, NORMAL_LINE_HEIGHT_RANGE, NOTO, PAGES,
                            PAGE_HEIGHT_PT, PAGE_WIDTH_PT, PX_TO_PT, REGULAR, RIVALS, RULES, SCALE_CLASSES, WEIGHTS, FontsPdfOracle, Witness, browser_receipt, clusters, compact, compare_browser,
                            compare_pdf, compare_targets, digest, family_face, identify_program, poppler_checks, read_commands, receipt, render_checks, small_capital_glyphs, target_view,
                            weight_order)

WITNESS = Witness()
SAMPLES = WITNESS.layout("pdf")["samples"]
# Samples in which the PDF, or a browser, holds reduced capitals.
CAPITALS = [identifier for identifier, sample in SAMPLES.items() if any(letter["sizePx"] != sample["sizePx"] for letter in sample["characters"])]
CONTROLS = Counter()


def tearDownModule():
    print(json.dumps({"positiveControls": CONTROLS["positive"], "negativeControls": CONTROLS["negative"]}))


def place(command, x):
    """Give the glyphs of a command the positions and declared widths of the program it is drawn with."""
    program = WITNESS.programs[command["font"]]
    command["declared"] = [program.advance(glyph["gid"]) * 1000 for glyph in command["glyphs"]]
    for glyph in command["glyphs"]:
        glyph["x"] = x
        x += program.advance(glyph["gid"]) * command["size"]
    return x


def relayout(observed, row):
    """Lay a row out again from its own fonts, glyphs and sizes, as a real line would follow a change."""
    commands = [command for command in observed["commands"] if command.get("row") == row]
    x = commands[0]["glyphs"][0]["x"]
    for command in commands:
        x = place(command, x)


def positive_pdf(witness=WITNESS, normal=1.25):
    """A faultless PDF observation: each row as the model lays it out, the prose between rows as plain commands. With
    another witness, what a converter following its rules would emit. `normal` is the line height it gives to normal."""
    layout, source, text = witness.layout("pdf"), witness.source, compact(witness.source.text)
    commands, state = [], {"cursor": 0, "y": 800.0, "page": 1}
    page = lambda offset: 1 + sum(1 for stop in source.breaks if stop <= offset)

    def down(step):
        if page(state["cursor"]) != state["page"]:
            state.update(page=page(state["cursor"]), y=800.0)
        state["y"] -= step

    def add(actual, font, size, glyphs, x, **keys):
        commands.append({"page": page(state["cursor"]), "actual": actual, "font": font, "size": size, "y": state["y"], "glyphs": glyphs, **keys})
        state["cursor"] += len(compact(actual))
        return place(commands[-1], x)

    def prose(end):
        for stop in [offset for offset in source.breaks if state["cursor"] < offset < end] + [end]:
            if stop > state["cursor"]:
                down(10)
                add(text[state["cursor"]:stop], REGULAR, 9.0, [{"gid": 0, "text": text[state["cursor"]:stop]}], CONTENT_LEFT_PT)

    for identifier, row in layout["rows"].items():
        prose(source.elements[identifier]["offset"])
        # A repeated block is set one used line height below the block it repeats.
        down(12 if not row.get("repeats") else (normal * row["sizePx"] if row["lineHeight"] == "normal" else row["lineHeight"]) * PX_TO_PT)
        for command in row["commands"]:
            add(command["text"], command["face"], command["sizePx"] * PX_TO_PT, [{"gid": glyph["gid"], "text": glyph["text"]} for glyph in command["glyphs"]],
                CONTENT_LEFT_PT + command["xPx"] * PX_TO_PT, row=identifier, sample=command["sample"], drawn=command["drawn"])
    prose(len(text))
    return {"pages": PAGES, "commands": commands}


def positive_browser(browser, rules=None):
    """A faultless browser observation, with the deviations the model knows for that browser; with other rules, what a
    browser following them would show."""
    witness = WITNESS if rules is None else WITNESS.following(NORM)
    if rules is not None:
        witness.rules[browser] = rules
    layout, elements, y = witness.layout(browser), {}, 100.0
    css = lambda row: {"lineHeight": "normal" if row["lineHeight"] == "normal" else "%spx" % row["lineHeight"]} if "lineHeight" in row else {}
    for identifier, row in layout["rows"].items():
        sample = layout["samples"][row["samples"][0]]
        y += 60 if not row.get("repeats") else row["lineHeight"] if row["lineHeight"] != "normal" else WITNESS.programs[sample["segments"][0]["face"]].line_spacing * row["sizePx"]
        elements[identifier] = {"kind": "row", "y": y, "computed": {"fontSizePx": row["sizePx"], "textIndentPx": row["indentPx"], **css(row)}}
    for identifier, item in layout["samples"].items():
        sample = {key: item[key] for key in ("id", "text", "sizePx", "weight", "style", "variant", "families")}
        identifies = item["face"] is not None and witness.candidates(sample, browser, item["widthPx"], 4) == [item["face"]]
        elements[identifier] = {"kind": "sample", "text": item["text"], "families": copy.deepcopy(item["families"]), "face": item["face"] if identifies else None,
                                "computed": {"fontSizePx": item["sizePx"], "fontWeight": item["weight"], "fontStyle": item["style"], "fontVariant": item["variant"]}}
        widen(elements[identifier], item["xPx"], [(letter["text"], letter["widthPx"]) for letter in item["characters"]])
    return {"order": list(WITNESS.source.order), "elements": elements}


def widen(element, x, letters):
    element.update(x=x, width=sum(width for _, width in letters), characters=[])
    for text, width in letters:
        element["characters"].append({"text": text, "x": x, "width": width})
        x += width


def positive_targets():
    """The pdfTargets of the browser oracle, as this model derives them."""
    view = target_view(WITNESS)
    keys = ("text", "drawn", "face", "fontSizePx", "widthPx")
    samples = {identifier: {**item, "segments": [dict(zip(keys, segment)) for segment in item["segments"]]} for identifier, item in view["samples"].items()}
    return {"contentLeftPt": 36, "rows": copy.deepcopy(view["rows"]), "samples": samples,
            "intendedDiagnostics": [{"code": code, "sample": sample, "circumstance": circumstance} for sample, code, circumstance in view["intendedDiagnostics"]]}


def of(observed, identifier):
    return [command for command in observed["commands"] if identifier in (command.get("sample"), command.get("row")) and (command.get("sample") is None) == identifier.startswith("row-")]


def edit(identifier, index=0, **changes):
    """Change one command of a sample, or one separator of a row, and let the line follow."""
    def falsify(observed):
        command = of(observed, identifier)[index]
        start = command["glyphs"][0]["x"]
        command.update(changes)
        for glyph in command["glyphs"]:
            glyph.setdefault("x", start)
        relayout(observed, command["row"])
    return falsify


def glyphs(face, drawn, text=None):
    """Glyphs of a program for some drawn letters; the first one carries the source text when it differs."""
    return [{"gid": WITNESS.programs[face].glyph(letter) or 0, "text": letter if text is None else text if index == 0 else ""} for index, letter in enumerate(drawn)]


def redraw(identifier, face, variant=None):
    """Draw a sample with another program (and optionally without or with capitals), and let the line follow."""
    def falsify(observed):
        for index, command in enumerate(of(observed, identifier)):
            drawn = command["drawn"] if variant is None else variant(command["actual"])
            capitals = drawn != command["actual"]
            edit(identifier, index, font=face, glyphs=[glyph for source in command["actual"] for glyph in glyphs(face, source.upper() if capitals else source, source)])(observed)
    return falsify


def small_capitals_at(scale):
    def falsify(observed):
        for identifier in CAPITALS:
            for index, command in enumerate(of(observed, identifier)):
                if command["drawn"] != command["actual"]:
                    resize(identifier, SAMPLES[identifier]["sizePx"] * scale, index)(observed)
    return falsify


def resize(identifier, size_px, segment=None):
    def falsify(observed):
        for command in of(observed, identifier) if segment is None else [of(observed, identifier)[segment]]:
            command["size"] = size_px * PX_TO_PT
        relayout(observed, of(observed, identifier)[0]["row"])
    return falsify


def merge(identifier, size_px=None):
    """Draw a sample as a single command in the face of its first segment."""
    def falsify(observed):
        first, *others = of(observed, identifier)
        program = WITNESS.programs[first["font"]]
        for other in others:
            first["actual"] += other["actual"]
            first["glyphs"] += [{"gid": program.glyph(letter) or 0, "text": text} for letter, text in zip(other["drawn"], [glyph["text"] for glyph in other["glyphs"]])]
            observed["commands"].remove(other)
        first["size"] = first["size"] if size_px is None else size_px * PX_TO_PT
        relayout(observed, first["row"])
    return falsify


def shift(identifier, delta, glyph=0, following=True):
    def falsify(observed):
        row = [command for command in observed["commands"] if command.get("row") == of(observed, identifier)[0]["row"]]
        start = row.index(of(observed, identifier)[0])
        for command in row[start:] if following else row[start:start + 1]:
            for moved in command["glyphs"][glyph if command is row[start] else 0:]:
                moved["x"] += delta
    return falsify


def lowered(row, delta):
    """Move a row, and everything after it on its page, down by some points."""
    def falsify(observed):
        commands = observed["commands"]
        start = commands.index(next(command for command in commands if command.get("row") == row))
        for command in commands[start:]:
            command["y"] -= delta if command["page"] == commands[start]["page"] else 0
    return falsify


class ModelTest(unittest.TestCase):
    """The model itself: literal values of CSS1 5.2, CSS 2.1 15.6 and the font tables."""

    def test_norm_tables_and_inventory(self):
        self.assertEqual(161, len(WITNESS.source.order))
        self.assertEqual(130, len(SAMPLES))
        self.assertEqual(2, len(WITNESS.source.breaks))
        self.assertEqual([9, 10, 13, 16, 18, 24, 32], list(ABSOLUTE_PX.values()))
        self.assertEqual([[400, 100], [400, 100], [400, 100], [700, 100], [700, 100], [900, 400], [900, 400], [900, 700], [900, 700]], [[BOLDER[weight], LIGHTER[weight]] for weight in WEIGHTS])
        for desired, available, chosen in [(300, [100, 400, 900], 100), (600, [100, 400, 900], 900), (500, [100, 400, 900], 400), (400, [300, 500], 500), (500, [300, 600], 300),
                                           (400, [300, 600], 300), (800, [400, 700], 700), (100, [400, 700], 400), (900, [400], 400)]:
            self.assertEqual(chosen, next(weight for weight in weight_order(desired) if weight in available))
        families = WITNESS.bindings["families"]
        face = lambda family, style, weight=400, variant="normal", rules=NORM: (family_face(families[family]["faces"], {"style": style, "variant": variant, "weight": weight}, rules) or {}).get("face")
        self.assertEqual([BOLD_ITALIC, BOLD_ITALIC, REGULAR], [face("Slant", style) for style in ("italic", "oblique", "normal")])
        self.assertEqual([None, None], [face("Lato", "oblique"), face("Noto Sans", "italic")])
        # Style, then variant, then weight: the small-caps label wins over the bold face; without it the face is unknown.
        self.assertEqual([NOTO, NOTO, REGULAR, BOLD], [face("Engraved", "normal", weight, variant) for weight, variant in ((400, "small-caps"), (700, "small-caps"), (400, "normal"), (700, "normal"))])
        self.assertEqual([BOLD, BOLD, BOLD], [face("Engraved", "normal", 700, "small-caps", rules) for rules in (RULES["chromium"], {**NORM, "variant_before_weight": False}, {**NORM, "small_caps": False})])
        self.assertEqual(SCALE_CLASSES, [[100, 200, 300], [400], [500], [600, 700], [800, 900]])
        self.assertEqual([NOTO, REGULAR, ITALIC, BOLD, BOLD_ITALIC], [face("Scale", "normal", group[0]) for group in SCALE_CLASSES])
        # The specimen word has a distinct width in every supplied program, far beyond the browser tolerance.
        widths = sorted(sum(program.advance(program.glyph(letter)) for letter in "minimum") for program in WITNESS.programs.values())
        self.assertGreaterEqual(min(right - left for left, right in zip(widths, widths[1:])), 0.07)
        # hhea line metrics and the smcp feature: Noto Sans 1069, -293, 0 of 1000 with small capitals, Lato 1974, -426, 0 of 2000 without.
        self.assertEqual([1.362, 1.2, 1.2], [WITNESS.programs[key].line_spacing for key in (NOTO, REGULAR, BOLD_ITALIC)])
        self.assertEqual([True, False, False], [bool(WITNESS.programs[key].small_capitals) for key in (NOTO, REGULAR, BOLD)])
        self.assertEqual(["n", "\u2192\u0301", "n"], clusters("n\u2192\u0301n"))
        self.assertEqual({}, small_capital_glyphs(None))
        CONTROLS["positive"] += 1

    def test_faces_sizes_and_segments_required_by_the_norm(self):
        faces = lambda prefix: [item["face"] for identifier, item in SAMPLES.items() if identifier.startswith(prefix)]
        drawn = lambda name, samples=SAMPLES: [[segment["drawn"], segment["sizePx"], segment["face"]] for segment in samples[name]["segments"]]
        self.assertEqual([REGULAR] * 5 + [BOLD] * 4, faces("match-lato-"))
        self.assertEqual([NOTO] * 3 + [REGULAR] * 2 + [BOLD] * 4, [SAMPLES["match-ladder-%d" % weight]["face"] for weight in WEIGHTS])
        self.assertEqual([ITALIC] * 3 + [BOLD_ITALIC] * 2, faces("match-ladder-italic-"))
        self.assertEqual([ITALIC] * 5 + [BOLD_ITALIC] * 4, faces("match-ladder-oblique-"))
        # Relative weights in Scale: every result of the chart has its own program.
        self.assertEqual([REGULAR] * 3 + [BOLD] * 2 + [BOLD_ITALIC] * 4, faces("bolder-from-"))
        self.assertEqual([NOTO] * 5 + [REGULAR] * 2 + [BOLD] * 2, faces("lighter-from-"))
        self.assertEqual([BOLD, BOLD_ITALIC, REGULAR, BOLD, BOLD_ITALIC, BOLD, BOLD, REGULAR],
                         [SAMPLES[name]["face"] for name in ("strong", "b-in-strong", "b-from-300", "strong-in-b-from-300", "b-in-strong-in-b-from-300", "inherited-bolder", "keyword-bold", "keyword-normal")])
        self.assertEqual([ITALIC, ITALIC, BOLD_ITALIC, REGULAR, BOLD_ITALIC, BOLD_ITALIC], faces("style-"))
        self.assertEqual([BOLD_ITALIC, ITALIC, NOTO, NOTO, NOTO], [SAMPLES[name]["face"] for name in ("oblique-skips-italic", "italic-skips-upright", "fallback-default", "fallback-default-style", "match-single-900")])
        self.assertEqual([NOTO] * 4 + [REGULAR] * 2 + [BOLD_ITALIC] * 2 + [REGULAR] * 2, faces("font-"))
        self.assertEqual([19.2, 13.3333, 20.16, 10.8333], [round(SAMPLES[name]["sizePx"], 4) for name in ("larger-from-medium", "smaller-from-medium", "nested-larger", "smaller-from-small")])
        rows = WITNESS.layout("pdf")["rows"]
        self.assertEqual([[16, 18.6667], [20, "normal"], [24, 26.4], [18, "normal"], [30, 36]],
                         [[rows[f"row-font-{number}"]["sizePx"], rows[f"row-font-{number}"]["lineHeight"] if rows[f"row-font-{number}"]["lineHeight"] == "normal" else round(rows[f"row-font-{number}"]["lineHeight"], 4)]
                          for number in range(1, 6)])
        self.assertEqual([None, "row-font-1", rows["row-font-1"]["lineHeight"]], [rows["row-font-1"].get("repeats"), rows["row-font-1-again"]["repeats"], rows["row-font-1-again"]["lineHeight"]])
        # hmtx: 'minimum' is 8126/2000 em in Lato Regular and 4557/1000 em in Noto Sans; the Lato space is 512/2000 em.
        self.assertAlmostEqual(65.008, SAMPLES["reference-lato-regular"]["widthPx"], 9)
        self.assertAlmostEqual(72.912, SAMPLES["reference-noto-sans"]["widthPx"], 9)
        self.assertAlmostEqual(65.008 + 4.096, SAMPLES["reference-lato-bold"]["xPx"], 9)
        self.assertEqual([["N", 20, REGULAR], ["OMINAL", 16, REGULAR]], drawn("caps-mixed"))
        self.assertEqual([["MASS", 16, REGULAR]], drawn("caps-sharp-s"))
        self.assertEqual(["\u00df", ""], [glyph["text"] for glyph in SAMPLES["caps-sharp-s"]["segments"][0]["glyphs"][2:]])
        self.assertEqual([["2008", 20, REGULAR]], drawn("caps-digits"))
        self.assertEqual([["N", 20, REGULAR], ["O", 16, REGULAR], [".3", 20, REGULAR]], drawn("caps-punctuation"))
        # A labelled small-caps face is drawn as written; any other face is synthesized, Noto Sans and its smcp glyphs included.
        self.assertEqual([[["minimum", 20, NOTO]], [["minimum", 20, NOTO]], [["minimum", 20, REGULAR]], [["MINIMUM", 16, NOTO]]],
                         [drawn(name) for name in ("caps-labelled", "caps-labelled-bold", "caps-labelled-normal", "caps-feature")])
        self.assertEqual([REGULAR, NOTO, REGULAR, NOTO, REGULAR], [letter["face"] for letter in SAMPLES["fallback-lato-noto"]["characters"]])
        self.assertEqual([NOTO, REGULAR, NOTO, REGULAR, NOTO, REGULAR, NOTO], [letter["face"] for letter in SAMPLES["fallback-noto-lato"]["characters"]])
        # The accent stays with its arrow in Lato although Noto Sans, listed first, has the accent.
        self.assertEqual([["n", NOTO, 1], ["\u2192\u0301", REGULAR, 2], ["n", NOTO, 1]], [[letter["text"], letter["face"], letter["glyphs"]] for letter in SAMPLES["fallback-cluster"]["characters"]])
        self.assertEqual(["\u2192", "\u0301"], [glyph["text"] for glyph in SAMPLES["fallback-cluster"]["segments"][1]["glyphs"]])
        self.assertEqual([REGULAR, NOTO, REGULAR], [letter["face"] for letter in SAMPLES["fallback-character-default"]["characters"]])
        self.assertEqual([("match-single-900", "font.weight-fallback"), ("caps-labelled-bold", "font.weight-fallback"), ("fallback-default", "font.default-family"),
                          ("fallback-default-style", "font.default-family"), ("fallback-default-style", "font.style-fallback"), ("fallback-default-style", "font.weight-fallback")],
                         [(note["sample"], note["code"]) for note in WITNESS.intended_diagnostics()])
        CONTROLS["positive"] += 1

    def test_browser_deviations_and_design_of_the_model(self):
        chromium, firefox, view = WITNESS.layout("chromium")["samples"], WITNESS.layout("firefox")["samples"], target_view(WITNESS)["samples"]
        drawn = lambda name, samples: [[segment["drawn"], segment["sizePx"], segment["face"]] for segment in samples[name]["segments"]]
        self.assertEqual([["N", 20, REGULAR], ["OMINAL", 14, REGULAR]], drawn("caps-mixed", chromium))
        deviating = ("match-ladder-oblique-400", "match-ladder-oblique-500", "oblique-skips-italic", "italic-skips-upright")
        self.assertEqual([BOLD_ITALIC, BOLD_ITALIC, ITALIC, NOTO], [chromium[name]["face"] for name in deviating])
        self.assertEqual([ITALIC, ITALIC, REGULAR, NOTO], [firefox[name]["face"] for name in deviating])
        self.assertEqual([[["MINIMUM", 14, REGULAR]], [["MINIMUM", 14, BOLD]], [["minimum", 20, NOTO]]], [drawn(name, chromium) for name in ("caps-labelled", "caps-labelled-bold", "caps-feature")])
        self.assertEqual([[["MINIMUM", 16, REGULAR]], [["MINIMUM", 16, BOLD]], [["minimum", 20, NOTO]]], [drawn(name, firefox) for name in ("caps-labelled", "caps-labelled-bold", "caps-feature")])
        # GSUB and hmtx: the smcp glyphs of 'minimum' are 4009/1000 em in Noto Sans.
        self.assertTrue(chromium["caps-feature"]["feature"] and not SAMPLES["caps-feature"]["feature"])
        self.assertAlmostEqual(80.18, chromium["caps-feature"]["widthPx"], 9)
        self.assertEqual([list(BROWSERS), ["firefox"], ["firefox"], ["firefox"], [], [], [], list(BROWSERS)],
                         [view[name]["witnessedBy"] for name in ("strong", "caps-lowercase", "match-ladder-oblique-500", "match-ladder-oblique-900", "oblique-skips-italic", "caps-labelled", "caps-feature", "fallback-cluster")])
        facts = WITNESS.design()
        common = {"oblique-skips-italic": "style-family-skip", "italic-skips-upright": "style-family-skip", "caps-labelled": "labelled-small-caps-face",
                  "caps-labelled-bold": "labelled-small-caps-face", "caps-feature": "opentype-small-caps-feature"}
        reduced = ("caps-lowercase", "caps-mixed", "caps-punctuation", "caps-sharp-s", "caps-bold", "caps-inherited", "font-5", "font-5-again")
        self.assertEqual(common, facts["deviations"]["firefox"])
        self.assertEqual({**common, "match-ladder-oblique-400": "chromium-slanted-faces-merged", "match-ladder-oblique-500": "chromium-slanted-faces-merged",
                          **{name: "chromium-small-caps-scale" for name in reduced}}, facts["deviations"]["chromium"])
        self.assertEqual(list(RIVALS), list(facts["separations"]))
        self.assertGreaterEqual(len(RIVALS), 33)
        self.assertEqual(["relative-size-ratio", "chromium-small-caps-scale", "chromium-slanted-faces-merged", "style-family-skip", "labelled-small-caps-face", "opentype-small-caps-feature",
                          "normal-line-height"], list(KNOWN_DEVIATIONS))
        CONTROLS["positive"] += 1


class Falsification(unittest.TestCase):
    def failed(self, checks):
        return [check["case"] + ":" + check["property"] for check in checks if not check["passed"]]

    def rejected(self, description, checks, marker):
        self.assertIn(marker, self.failed(checks), description + " must fail on " + marker)
        CONTROLS["negative"] += 1

    def refused(self, description, call, pattern="Complete fixture inventory required"):
        with self.assertRaisesRegex(AssertionError, pattern, msg=description):
            call()
        CONTROLS["negative"] += 1


class DesignTest(Falsification):
    """A binding or a document that can no longer tell the norm from a rival rule is refused, whatever the PDF shows."""

    def weakened(self, change):
        witness = WITNESS.following(NORM)
        witness.bindings = copy.deepcopy(WITNESS.bindings)
        change(witness.bindings)
        return witness

    def test_positive_control(self):
        self.assertEqual(list(RIVALS), list(self.weakened(lambda bindings: None).design()["separations"]))
        CONTROLS["positive"] += 1

    def test_weakened_bindings_are_refused(self):
        face = lambda bindings, family, **label: next(face for face in bindings["families"][family]["faces"] if all(face.get(key, False) == value for key, value in label.items()))
        regular, bold, noto = (WITNESS.files[key]["file"] for key in (REGULAR, BOLD, NOTO))
        for description, change, pattern in [
                ("Ladder 100 drawn by the program of 400", lambda bindings: face(bindings, "Ladder", weight=100).update(file=regular), "nearest weight"),
                ("Ladder oblique 600 drawn by the program of 300", lambda bindings: face(bindings, "Ladder", weight=600, style="oblique").update(file=face(bindings, "Ladder", weight=300, style="oblique")["file"]), "separate the norm"),
                ("Scale 900 drawn by the program of 700", lambda bindings: face(bindings, "Scale", weight=900).update(file=bold), "Scale must draw"),
                ("Scale without its 500 face", lambda bindings: bindings["families"]["Scale"]["faces"].remove(face(bindings, "Scale", weight=500)), "Scale must draw"),
                ("Slant oblique face drawn by the regular program", lambda bindings: face(bindings, "Slant", style="oblique").update(file=regular), "separate the norm"),
                ("Engraved small-caps label on the regular program", lambda bindings: face(bindings, "Engraved", smallCaps=True).update(file=regular), "font-variant ignored"),
                ("Generic serif bound to the default program", lambda bindings: [item.update(file=noto) for item in bindings["generic"]["serif"]["faces"]], "generic families without binding"),
                ("Engraved without a small-caps label", lambda bindings: bindings["families"]["Engraved"]["faces"].remove(face(bindings, "Engraved", smallCaps=True)), "separate the norm"),
                ("Reference family bound to another program", lambda bindings: bindings["families"]["Reference Lato Bold"]["faces"][0].update(file=regular), "reference family"),
                ("Default family with a second face", lambda bindings: bindings["default"]["faces"].append({"file": regular, "weight": 700, "style": "normal"}), "default family"),
                ("Second family of the fallback bound to the first program", lambda bindings: bindings["families"]["Noto Sans"]["faces"][0].update(file=regular), "")]:
            with self.subTest(description=description):
                self.refused(description, self.weakened(change).design, pattern)


class PdfComparisonTest(Falsification):
    def rejects(self, description, falsify, marker):
        observed = positive_pdf()
        falsify(observed)
        self.rejected(description, compare_pdf(WITNESS, observed)[0], marker)

    def refuses(self, description, falsify, pattern="Complete fixture inventory required"):
        observed = positive_pdf()
        replaced = falsify(observed)
        self.refused(description, lambda: compare_pdf(WITNESS, replaced if isinstance(replaced, dict) else observed), pattern)

    def test_positive_control(self):
        checks, measured = compare_pdf(WITNESS, positive_pdf())
        self.assertEqual([], self.failed(checks))
        self.assertGreaterEqual(len(checks), 2300)
        self.assertEqual(130, len(measured))
        self.assertEqual({"relative-size-ratio": 3, "chromium-small-caps-scale": 9}, dict(Counter(check["knownDeviation"] for check in checks if "knownDeviation" in check)))
        self.assertEqual(5, sum(check["property"] == "lineStepPt" for check in checks))
        # Any value of the user agent inside the accepted range is a faultless normal line height.
        for normal in NORMAL_LINE_HEIGHT_RANGE:
            self.assertEqual([], self.failed(compare_pdf(WITNESS, positive_pdf(normal=normal))[0]))
        CONTROLS["positive"] += 1

    def test_incomplete_or_foreign_inventory_is_refused(self):
        def without(identifier):
            return lambda observed: [observed["commands"].remove(command) for command in of(observed, identifier)]
        self.refuses("Missing sample", without("caps-digits"))
        self.refuses("Missing repeated block", without("font-3-again"))
        self.refuses("Missing row", lambda observed: [observed["commands"].remove(command) for command in list(observed["commands"]) if command.get("row") == "row-fallback"])
        self.refuses("Sample drawn twice", lambda observed: observed["commands"].insert(observed["commands"].index(of(observed, "strong")[0]), copy.deepcopy(of(observed, "strong")[0])))
        self.refuses("Samples in another order", lambda observed: observed["commands"].reverse())
        self.refuses("Capitals in ActualText", lambda observed: of(observed, "caps-lowercase")[0].update(actual="MINIMUM"))
        self.refuses("Accent missing from ActualText", lambda observed: of(observed, "fallback-cluster")[1].update(actual="\u2192"))
        self.refuses("Sample merged with the preceding prose", lambda observed: (observed["commands"][observed["commands"].index(of(observed, "font-1")[0]) - 1].update(
            actual=observed["commands"][observed["commands"].index(of(observed, "font-1")[0]) - 1]["actual"] + "minimum"), observed["commands"].remove(of(observed, "font-1")[0])))
        self.refuses("No text command", lambda observed: {"pages": PAGES, "commands": []}, "Observations required")
        self.refuses("No observation", lambda observed: {}, "Observations required")

    def test_rival_rules_are_rejected(self):
        """What a converter following each rival rule would emit fails on every sample that separates the rule, or on the
        row of the sample when the rule cuts it into other text commands."""
        for name, other in RIVALS.items():
            with self.subTest(rival=name):
                checks, _ = compare_pdf(WITNESS, positive_pdf(WITNESS.following({**NORM, **other["rules"]})))
                failed = {check["case"] for check in checks if not check["passed"]} | {check["case"] + ":" + check["property"] for check in checks if not check["passed"]}
                missed = [identifier for identifier in other["separates"] if identifier not in failed and SAMPLES.get(identifier, {}).get("row", "") + ":commands" not in failed]
                self.assertEqual([], missed, "A converter following '" + name + "' must fail on its samples")
                CONTROLS["negative"] += 1

    def test_every_entry_of_the_relative_weight_charts(self):
        """A converter with one wrong entry in the bolder or lighter chart fails, unless the wrong value shares its Scale
        program with the right one: 200 and 300 with 100, 600 with 700, 800 with 900."""
        group = lambda weight: next(index for index, weights in enumerate(SCALE_CLASSES) if weight in weights)
        undetected = []
        for name, table in (("bolder", BOLDER), ("lighter", LIGHTER)):
            for inherited in WEIGHTS:
                for value in WEIGHTS:
                    if value != table[inherited]:
                        checks, _ = compare_pdf(WITNESS, positive_pdf(WITNESS.following({**NORM, name: {**table, inherited: value}})))
                        failed = f"{name}-from-{inherited}:face" in self.failed(checks)
                        self.assertEqual(group(value) != group(table[inherited]), failed, f"{name} from {inherited} giving {value}")
                        self.assertEqual(failed, bool(self.failed(checks)))
                        undetected += [] if failed else [(name, inherited, value)]
                        CONTROLS["negative"] += failed
        self.assertEqual([("bolder", 400, 600), ("bolder", 500, 600), *(("bolder", inherited, 800) for inherited in (600, 700, 800, 900)),
                          *(("lighter", inherited, value) for inherited in (100, 200, 300, 400, 500) for value in (200, 300)), ("lighter", 800, 600), ("lighter", 900, 600)], undetected)

    def test_weight_matching(self):
        self.rejects("Nearest weight for 300", redraw("match-ladder-300", REGULAR), "match-ladder-300:face")
        self.rejects("Nearest weight for 600", redraw("match-ladder-600", REGULAR), "match-ladder-600:face")
        self.rejects("500 treated as bold", redraw("match-lato-500", BOLD), "match-lato-500:face")
        self.rejects("600 treated as regular", redraw("match-lato-600", REGULAR), "match-lato-600:face")
        self.rejects("Italic 400 choosing 300 before 500", redraw("match-ladder-italic-400", ITALIC), "match-ladder-italic-400:face")
        self.rejects("Oblique 500 choosing the nearer 600", redraw("match-ladder-oblique-500", BOLD_ITALIC), "match-ladder-oblique-500:face")
        self.rejects("Oblique 400 answered by the italic 500 face", redraw("match-ladder-oblique-400", BOLD_ITALIC), "match-ladder-oblique-400:face")
        self.rejects("Computed 900 drawn with the regular face", redraw("bolder-from-900", REGULAR), "bolder-from-900:face")
        self.rejects("Bolder from 600 stopping at 700", redraw("bolder-from-600", BOLD), "bolder-from-600:face")
        self.rejects("Bolder from 400 as one step", redraw("bolder-from-400", ITALIC), "bolder-from-400:face")
        self.rejects("Lighter from 800 drawn with the regular face", redraw("lighter-from-800", REGULAR), "lighter-from-800:face")
        self.rejects("Lighter from 400 staying at 400", redraw("lighter-from-400", REGULAR), "lighter-from-400:face")
        self.rejects("Lighter from 600 as one step", redraw("lighter-from-600", ITALIC), "lighter-from-600:face")
        self.rejects("b as bold instead of bolder", redraw("b-from-300", BOLD), "b-from-300:face")
        self.rejects("Nested strong and b not accumulating", redraw("b-in-strong", BOLD), "b-in-strong:face")
        self.rejects("Keyword bolder inherited", redraw("inherited-bolder", BOLD_ITALIC), "inherited-bolder:face")
        self.rejects("Normal not restoring 400", redraw("keyword-normal", BOLD), "keyword-normal:face")
        self.rejects("Relative weight sample left in the body family", redraw("lighter-from-100", REGULAR), "lighter-from-100:face")
        self.rejects("Reference family bound to another program", redraw("reference-lato-bold", REGULAR), "reference-lato-bold:face")
        self.rejects("One-face family answering 900 with another family", redraw("match-single-900", BOLD), "match-single-900:face")

    def test_style_matching_and_family_order(self):
        self.rejects("Italic drawn upright", redraw("style-i", REGULAR), "style-i:face")
        self.rejects("Bold italic drawn with the regular italic", redraw("style-i-in-b", ITALIC), "style-i-in-b:face")
        self.rejects("Normal not restored inside i", redraw("style-normal-in-i", ITALIC), "style-normal-in-i:face")
        self.rejects("Italic refusing an oblique face", redraw("style-italic-from-oblique-face", REGULAR), "style-italic-from-oblique-face:face")
        self.rejects("Oblique answered by the italic face of the first family", redraw("oblique-skips-italic", ITALIC), "oblique-skips-italic:face")
        self.rejects("Oblique answered by the upright face of the first family", redraw("oblique-skips-italic", REGULAR), "oblique-skips-italic:face")
        self.rejects("Italic answered by the upright-only first family", redraw("italic-skips-upright", NOTO), "italic-skips-upright:face")
        self.rejects("Unlisted family drawn with the first bound family", redraw("fallback-default", REGULAR), "fallback-default:face")
        self.rejects("Unlisted bold italic drawn with a Lato face", redraw("fallback-default-style", BOLD_ITALIC), "fallback-default-style:face")
        self.rejects("Generic family bound to another program", redraw("font-3", NOTO), "font-3:face")
        self.rejects("Shorthand keeping the inherited italic", redraw("font-1", ITALIC), "font-1:face")
        self.rejects("Shorthand italic drawn upright", redraw("font-4", BOLD), "font-4:face")
        self.rejects("Shorthand bold ignored", redraw("font-4", ITALIC), "font-4:face")

    def test_sizes(self):
        self.rejects("Absolute size depending on its parent", resize("absolute-30-small", 14), "absolute-30-small:fontSizePt")
        self.rejects("Absolute size one table step off", resize("absolute-12-x-large", 18), "absolute-12-x-large:fontSizePt")
        self.rejects("CSS1 example reproduced: larger from medium is large", resize("larger-from-medium", 18), "larger-from-medium:differenceFromTableStepPt")
        self.rejects("Smaller from medium is small", resize("smaller-from-medium", 13), "smaller-from-medium:differenceFromTableStepPt")
        self.rejects("Smaller from small is x-small", resize("smaller-from-small", 10), "smaller-from-small:differenceFromTableStepPt")
        self.rejects("Relative size with the ratio 1.5", resize("larger-from-30", 45), "larger-from-30:fontSizePt")
        self.rejects("Keyword inherited instead of the computed size", resize("inherited-larger", 20.16), "inherited-larger:fontSizePt")
        self.rejects("Nested larger applied once", resize("nested-larger", 16.8), "nested-larger:fontSizePt")
        self.rejects("Percentage of the wrong parent", resize("percentage-of-x-large", 7), "percentage-of-x-large:fontSizePt")
        self.rejects("Font-size em of the element instead of its parent", resize("em-of-x-large", 10.5), "em-of-x-large:fontSizePt")
        self.rejects("Pixels written as points", resize("reference-lato-regular", 16 / PX_TO_PT), "reference-lato-regular:fontSizePt")
        self.rejects("Size off by a thousandth of a point", resize("absolute-12-medium", 16 + 0.001 / PX_TO_PT), "absolute-12-medium:fontSizePt")
        self.rejects("Shorthand percentage of the wrong size", resize("font-2", 12.8), "font-2:fontSizePt")
        self.rejects("Shorthand size keyword ignored", resize("font-3", 25), "font-3:fontSizePt")
        self.rejects("Indent em resolved against the parent size", shift("indent-x-large", (28 - 48) * PX_TO_PT), "indent-x-large:xPt")
        self.rejects("Indent declared but not applied", shift("indent-larger", -84 * PX_TO_PT), "indent-larger:xPt")

    def test_line_height_of_the_font_shorthand(self):
        step = lambda number, points: lowered(f"row-font-{number}-again", points - (of(positive_pdf(), f"font-{number}")[0]["y"] - of(positive_pdf(), f"font-{number}-again")[0]["y"]))
        self.rejects("Shorthand keeping the inherited 50px as normal", step(2, INHERITED["lineHeight"] * PX_TO_PT), "row-font-2-again:lineStepPt")
        self.rejects("Shorthand keeping the inherited 50px beside bold italic", step(4, INHERITED["lineHeight"] * PX_TO_PT), "row-font-4-again:lineStepPt")
        self.rejects("Normal line height below the font size", step(2, 0.9 * 15), "row-font-2-again:lineStepPt")
        self.rejects("Normal line height of two font sizes", step(4, 2 * 13.5), "row-font-4-again:lineStepPt")
        self.rejects("Line height in points read as pixels", step(1, 14 * PX_TO_PT), "row-font-1-again:lineStepPt")
        self.rejects("Explicit line height replaced by a normal one", step(1, 1.25 * 12), "row-font-1-again:lineStepPt")
        self.rejects("Percentage line height of the parent size", step(3, 1.1 * 25 * PX_TO_PT), "row-font-3-again:lineStepPt")
        self.rejects("Percentage line height of 120% taken once", step(5, 30 * PX_TO_PT), "row-font-5-again:lineStepPt")
        self.rejects("Line height off by a thousandth of a point", step(5, 27.001), "row-font-5-again:lineStepPt")
        self.rejects("Repeated block on the next page", lambda observed: [command.update(page=PAGES + 1) for command in of(observed, "font-3-again")], "row-font-3-again:lineStepPt")

    def test_small_capitals(self):
        self.rejects("Small capitals at 0.7", small_capitals_at(0.7), "caps-lowercase:smallCapsScale")
        self.rejects("Small capitals at 0.7 after a capital", small_capitals_at(0.7), "caps-punctuation:smallCapsScale")
        self.rejects("Small capitals at the full size", resize("caps-bold", 20), "caps-bold:smallCapsScale")
        self.rejects("Shorthand small capitals at 0.7", resize("font-5", 21), "font-5:smallCapsScale")
        self.rejects("Digits reduced", resize("caps-digits", 16), "caps-digits:fontSizePt")
        self.rejects("Capital reduced with the lowercase letters", merge("caps-mixed", 16), "row-small-caps:commands")
        self.rejects("Lowercase letters kept at the size of the capital", merge("caps-mixed"), "row-small-caps:commands")
        self.rejects("Punctuation and digit reduced with the lowercase letter", resize("caps-punctuation", 16, 2), "caps-punctuation:fontSizePt")
        sharp = lambda drawn: edit("caps-sharp-s", glyphs=glyphs(REGULAR, "M", "m") + glyphs(REGULAR, "A", "a") + glyphs(REGULAR, drawn, "\u00df"))
        self.rejects("Sharp s drawn as one S", sharp("S"), "caps-sharp-s:glyphs")
        self.rejects("Sharp s kept as a lowercase glyph", sharp("\u00df"), "caps-sharp-s:glyphs")
        self.rejects("font-variant ignored", redraw("caps-lowercase", REGULAR, lambda text: text), "caps-lowercase:glyphs")
        self.rejects("Small capitals not inherited", redraw("caps-inherited", REGULAR, lambda text: text), "caps-inherited:glyphs")
        self.rejects("Normal not restored inside small-caps", redraw("caps-restored", REGULAR, str.upper), "caps-restored:glyphs")
        self.rejects("Shorthand small capitals lost", redraw("font-5", REGULAR, lambda text: text), "font-5:glyphs")
        self.rejects("Shorthand keeping the inherited small capitals", redraw("font-4", BOLD_ITALIC, str.upper), "font-4:glyphs")
        self.rejects("Bold small capitals drawn with the regular face", redraw("caps-bold", REGULAR), "caps-bold:face")
        self.rejects("Capitals in the extracted text", edit("caps-lowercase", glyphs=glyphs(REGULAR, "MINIMUM")), "caps-lowercase:decodedText")
        self.rejects("Second S of the sharp s extracted", edit("caps-sharp-s", glyphs=glyphs(REGULAR, "M", "m") + glyphs(REGULAR, "A", "a") + glyphs(REGULAR, "S", "\u00df") + glyphs(REGULAR, "S", "s")),
                     "caps-sharp-s:decodedText")

    def test_labelled_small_caps_face_and_opentype_feature(self):
        self.rejects("Small-caps label ignored: capitals of the regular face", redraw("caps-labelled", REGULAR, str.upper), "caps-labelled:face")
        self.rejects("Labelled face drawn as capitals", redraw("caps-labelled", NOTO, str.upper), "caps-labelled:glyphs")
        self.rejects("Labelled face reduced", resize("caps-labelled", 16), "caps-labelled:fontSizePt")
        self.rejects("Weight matched before the small-caps label", redraw("caps-labelled-bold", BOLD, str.upper), "caps-labelled-bold:face")
        self.rejects("Labelled face drawn for normal text", redraw("caps-labelled-normal", NOTO), "caps-labelled-normal:face")
        feature = WITNESS.layout("chromium")["samples"]["caps-feature"]["segments"][0]["glyphs"]
        self.rejects("OpenType small capitals of the font used", lambda observed: (resize("caps-feature", 20)(observed), edit("caps-feature", glyphs=[{"gid": glyph["gid"], "text": glyph["text"]} for glyph in feature])(observed)),
                     "caps-feature:glyphs")
        self.rejects("Unlabelled Noto Sans drawn as written", redraw("caps-feature", NOTO, lambda text: text), "caps-feature:glyphs")

    def test_fallback_character_by_character(self):
        self.rejects("Whole run moved to the second family", merge("fallback-noto-lato"), "row-fallback:commands")
        self.rejects("No return to the first family", edit("fallback-lato-noto", 4, font=NOTO, glyphs=glyphs(NOTO, "n")), "fallback-lato-noto:face")
        self.rejects("Missing glyph drawn as .notdef of the first family", edit("fallback-lato-noto", 1, font=REGULAR, glyphs=glyphs(REGULAR, "\u211d")), "fallback-lato-noto:glyphs")
        self.rejects("Present character taken from the second family", edit("fallback-noto-lato", 0, font=REGULAR, glyphs=glyphs(REGULAR, "n")), "fallback-noto-lato:face")
        self.rejects("Character missing from the only listed family drawn as .notdef", edit("fallback-character-default", 1, font=REGULAR, glyphs=glyphs(REGULAR, "\u211d")), "fallback-character-default:face")

        def split(observed):
            """The accent taken alone from the first family that has it, as a fallback by code point would."""
            arrow, after = of(observed, "fallback-cluster")[1:]
            accent = {**copy.deepcopy(arrow), "actual": "\u0301", "font": NOTO, "glyphs": glyphs(NOTO, "\u0301")}
            arrow.update(actual="\u2192", glyphs=arrow["glyphs"][:1])
            observed["commands"].insert(observed["commands"].index(after), accent)
            relayout(observed, arrow["row"])
        self.rejects("Accent split from its arrow", split, "row-fallback:commands")
        self.rejects("Accent without glyph", edit("fallback-cluster", 1, glyphs=[{"gid": WITNESS.programs[REGULAR].glyph("\u2192"), "text": "\u2192\u0301"}]), "fallback-cluster:glyphs")

    def test_glyphs_and_geometry(self):
        self.rejects("Glyph of another character under the right text", edit("strong", glyphs=glyphs(BOLD, "n", "m") + glyphs(BOLD, "inimum")), "strong:glyphs")

        def foreign_advances(observed):
            command = of(observed, "keyword-normal")[0]
            declared = command["declared"]
            command["font"] = BOLD
            place(command, command["glyphs"][0]["x"])
            command.update(font=REGULAR, declared=declared)
        self.rejects("Line laid out with the advances of another face", foreign_advances, "keyword-normal:glyphAdvancesPt")
        self.rejects("Tracking inside a sample", shift("style-em", 0.3, glyph=3, following=False), "style-em:glyphAdvancesPt")
        self.rejects("Gap after a change of size", lambda observed: [glyph.update(x=glyph["x"] + 0.5) for glyph in of(observed, "caps-mixed")[1]["glyphs"]], "caps-mixed:startAfterPreviousAdvances")
        self.rejects("Gap after a change of face", lambda observed: [glyph.update(x=glyph["x"] + 0.2) for command in of(observed, "fallback-lato-noto")[1:] for glyph in command["glyphs"]],
                     "fallback-lato-noto:startAfterPreviousAdvances")
        self.rejects("Accent given an advance", lambda observed: [glyph.update(x=glyph["x"] + 3) for command in of(observed, "fallback-cluster")[2:] for glyph in command["glyphs"]],
                     "fallback-cluster:startAfterPreviousAdvances")
        self.rejects("Wider inter-sample space", shift("style-em", 1.0), "style-em:startAfterPreviousAdvances")
        self.rejects("Row shifted by one point", shift("reference-lato-regular", 1.0), "reference-lato-regular:xPt")
        self.rejects("Sample on another baseline", lambda observed: of(observed, "b-in-strong")[0].update(y=of(observed, "b-in-strong")[0]["y"] + 2), "b-in-strong:baseline")
        self.rejects("Sample on another page", lambda observed: of(observed, "b-in-strong")[0].update(page=2), "b-in-strong:baseline")
        self.rejects("Separator in the face of the preceding sample", edit("row-reference", 1, font=BOLD), "row-reference:face")
        self.rejects("Separator at the size of the sample", edit("row-absolute-30", 5, size=24 * PX_TO_PT), "row-absolute-30:fontSizePt")

        def second_space(observed):
            space = of(observed, "row-style")[0]
            observed["commands"].insert(observed["commands"].index(space), copy.deepcopy(space))

        def raised(observed):
            for command in observed["commands"]:
                command["y"] += 30 if command.get("row") == "row-lighter" else 0
        self.rejects("Two spaces between samples", second_space, "row-style:commands")
        self.rejects("Row above the previous one", raised, "row-lighter:belowPreviousRow")

    def test_document(self):
        self.rejects("Forced page breaks ignored", lambda observed: [command.update(page=1) for command in observed["commands"]], "document:forcedPageBreaks")
        self.rejects("Second forced page break ignored", lambda observed: [command.update(page=2) for command in observed["commands"] if command["page"] == 3], "document:forcedPageBreaks")
        self.rejects("Page break one row early", lambda observed: [command.update(page=2) for command in observed["commands"] if command.get("row") == "row-nesting"], "document:forcedPageBreaks")
        self.rejects("Fourth page", lambda observed: observed.update(pages=PAGES + 1), "document:pages")
        self.rejects("ToUnicode differing from ActualText in the prose", lambda observed: observed["commands"][0]["glyphs"][0].update(text="CSS"), "document:decodedText")
        self.rejects("Declared width differing from hmtx", lambda observed: of(observed, "strong")[0]["declared"].__setitem__(0, 500.0), "document:widthsArray")
        self.rejects("Glyph without a declared width", lambda observed: of(observed, "strong")[0]["declared"].pop(), "document:widthsArray")


def operations(tm=(1, 0, 0, 1), spacing=(0, 0, 100, 0), actual="ab", codes=(b"\x00\x61", b"\x00\x62"), positions=((36, 700), (42, 700))):
    """One text command in the profile of the converter."""
    number = lambda value: FloatObject(value) if isinstance(value, float) else NumberObject(value)
    span = lambda **keys: ([NameObject("/Span"), DictionaryObject({NameObject("/" + key): value for key, value in keys.items()})], b"BDC")
    result = [span(MCID=NumberObject(0)), ([], b"q"), ([number(0.1), number(0.2), number(0.3)], b"rg"), span(ActualText=ByteStringObject(b"\xfe\xff" + actual.encode("utf-16-be"))),
              ([], b"BT"), ([NameObject("/F1"), number(12)], b"Tf"), *(([number(value)], operator) for value, operator in zip(spacing, (b"Tc", b"Tw", b"Tz", b"Ts")))]
    for code, (x, y) in zip(codes, positions):
        result += [([number(value) for value in (*tm, x, y)], b"Tm"), ([ByteStringObject(code)], b"Tj")]
    return result + [([], b"ET"), ([], b"EMC"), ([], b"Q"), ([], b"EMC")]


class Resource:
    key = REGULAR

    def decode(self, raw):
        assert raw[0] == 0, "Text uses an unmapped character code"
        return raw[1], chr(raw[1])

    def width(self, glyph):
        return 0.0 if glyph == 0x7E else 500.0  # the tilde stands for a combining mark without advance


class ContentProfileTest(Falsification):
    """The strict text profile: anything that could hide a synthetic face, a scaled size or unverified text is refused."""

    def read(self, sequence):
        return read_commands(sequence, {"/F1": Resource()}, 1)

    def refuses(self, description, sequence, pattern):
        self.refused(description, lambda: self.read(sequence), pattern)

    def edited(self, index, *replacement, **keys):
        sequence = operations(**keys)
        sequence[index:index + 1] = replacement
        return sequence

    def test_positive_control(self):
        self.assertEqual([{"page": 1, "actual": "ab", "font": REGULAR, "size": 12.0, "y": 700.0, "declared": [500.0, 500.0],
                           "glyphs": [{"gid": 0x61, "text": "a", "x": 36.0}, {"gid": 0x62, "text": "b", "x": 42.0}]}], self.read(operations()))
        # A mark without advance leaves the next glyph where it stands itself.
        marked = self.read(operations(actual="a~b", codes=(b"\x00\x61", b"\x00\x7e", b"\x00\x62"), positions=((36, 700), (42, 700), (42, 700))))
        self.assertEqual([36.0, 42.0, 42.0], [glyph["x"] for glyph in marked[0]["glyphs"]])
        CONTROLS["positive"] += 1

    def test_synthetic_faces_and_sizes_are_refused(self):
        number = lambda value: [FloatObject(value)]
        self.refuses("Synthetic oblique", operations(tm=(1, 0, 0.2126, 1)), "only translate")
        self.refuses("Horizontally scaled text matrix", operations(tm=(1.05, 0, 0, 1)), "only translate")
        self.refuses("Size carried by the text matrix", operations(tm=(0.8, 0, 0, 0.8)), "only translate")
        self.refuses("Stroked text as synthetic bold", self.edited(6, (number(2), b"Tr")), "outside the strict text profile")
        self.refuses("Stroke width", self.edited(2, (number(0.4), b"w")), "outside the strict text profile")
        self.refuses("Transformation of the page", self.edited(2, ([FloatObject(value) for value in (1, 0, 0.2, 1, 0, 0)], b"cm")), "outside the strict text profile")
        self.refuses("Overstruck glyph as synthetic bold", operations(codes=(b"\x00\x61", b"\x00\x61"), positions=((36, 700), (36, 700)), actual="aa"), "no overstrike")
        self.refuses("Glyph drawn before the mark that precedes it", operations(actual="a~b", codes=(b"\x00\x61", b"\x00\x7e", b"\x00\x62"), positions=((36, 700), (42, 700), (41, 700))), "no overstrike")
        self.refuses("Glyph going back", operations(positions=((42, 700), (36, 700))), "no overstrike")
        self.refuses("Horizontal scaling", operations(spacing=(0, 0, 105, 0)), "No character spacing")
        self.refuses("Character spacing", operations(spacing=(0.3, 0, 100, 0)), "No character spacing")
        self.refuses("Word spacing", operations(spacing=(0, 2, 100, 0)), "No character spacing")
        self.refuses("Text rise", operations(spacing=(0, 0, 100, 3)), "No character spacing")
        self.refuses("Second font in one command", self.edited(12, ([NameObject("/F1"), FloatObject(9)], b"Tf")), "One known font and size")
        self.refuses("Unknown font resource", self.edited(5, ([NameObject("/F9"), FloatObject(12)], b"Tf")), "One known font and size")
        self.refuses("Font size of zero", self.edited(5, ([NameObject("/F1"), FloatObject(0)], b"Tf")), "Positive font size")

    def test_unverifiable_text_is_refused(self):
        sequence = operations()
        self.refuses("Glyph positioned by the previous advance", self.edited(12), "own text matrix")
        self.refuses("Two glyphs in one string", operations(codes=(b"\x00\x61\x00\x62",), positions=((36, 700),)), "two-byte glyph operands")
        self.refuses("Array of strings and adjustments", self.edited(11, ([ByteStringObject(b"\x00\x61")], b"TJ")), "outside the strict text profile")
        self.refuses("Relative positioning", self.edited(10, ([FloatObject(36), FloatObject(700)], b"Td")), "outside the strict text profile")
        self.refuses("Unmapped character code", operations(codes=(b"\x01\x61", b"\x00\x62")), "unmapped character code")
        self.refuses("Second glyph on another baseline", operations(positions=((36, 700), (42, 703))), "One baseline")
        self.refuses("Text without ActualText", sequence[:3] + sequence[4:-1], "own ActualText scope")
        self.refuses("Two text commands under one ActualText", sequence[:-3] + sequence[4:-3] + sequence[-3:], "own ActualText scope")
        self.refuses("Nested ActualText", sequence[:4] + sequence[3:] + [([], b"EMC")], "No nested ActualText")
        self.refuses("ActualText without text", sequence[:4] + sequence[-3:], "exactly one text command")
        self.refuses("Text command without glyph", sequence[:10] + sequence[-4:], "at least one glyph")
        self.refuses("Unbalanced marked content", sequence[:-1], "Unbalanced")
        self.refuses("Form or image", self.edited(2, ([NameObject("/X1")], b"Do")), "outside the strict text profile")


def sfnt(tables):
    directory, body, start = b"", b"", 12 + 16 * len(tables)
    for tag, table in tables.items():
        directory += struct.pack(">4sIII", tag.encode("latin-1"), 0, start + len(body), len(table))
        body += table + bytes(-len(table) % 4)
    return struct.pack(">4sHHHH", b"\x00\x01\x00\x00", len(tables), 0, 0, 0) + directory + body


class EmbeddedProgramTest(Falsification):
    """An embedded FontFile2 is recognized by its tables: all of them, and only those of one supplied file."""

    def embedded(self, key=REGULAR, **changes):
        tables = {tag: table for tag, table in WITNESS.programs[key].tables.items() if tag != "cmap"}
        tables["head"] = tables["head"][:8] + b"\x12\x34\x56\x78" + tables["head"][12:]
        tables.update(changes)
        return sfnt({tag: table for tag, table in tables.items() if table is not None})

    def refuses(self, description, program, pattern="exactly one of the supplied font files"):
        self.refused(description, lambda: identify_program(program, WITNESS.programs), pattern)

    def test_positive_control(self):
        for key in WITNESS.programs:
            self.assertEqual(key, identify_program(self.embedded(key), WITNESS.programs))
        self.assertEqual(REGULAR, identify_program((Path(__file__).resolve().parents[1] / WITNESS.files[REGULAR]["file"]).read_bytes(), WITNESS.programs))
        CONTROLS["positive"] += 1

    def test_foreign_or_altered_programs_are_refused(self):
        regular, bold = WITNESS.programs[REGULAR].tables, WITNESS.programs[BOLD].tables
        self.refuses("Advances of another face", self.embedded(hmtx=bold["hmtx"]))
        self.refuses("Outlines of another face", self.embedded(glyf=bold["glyf"], loca=bold["loca"]))
        self.refuses("Emboldened outline", self.embedded(glyf=regular["glyf"][:4000] + bytes([regular["glyf"][4000] ^ 1]) + regular["glyf"][4001:]))
        self.refuses("Other unitsPerEm", self.embedded(head=regular["head"][:18] + struct.pack(">H", 1000) + regular["head"][20:]))
        self.refuses("Slanted italic angle in post", self.embedded(post=regular["post"][:4] + struct.pack(">i", -12 << 16) + regular["post"][8:]))
        self.refuses("Character map of another face", self.embedded(cmap=WITNESS.programs[NOTO].tables["cmap"]))
        self.refuses("Substitutions of another face", self.embedded(GSUB=WITNESS.programs[NOTO].tables["GSUB"]))
        self.refuses("Unknown table", self.embedded(XTRA=b"\x00\x00\x00\x00"))
        self.refuses("Program without outlines", self.embedded(glyf=None), "lacks an outline or metrics table")
        self.refuses("Not a TrueType program", b"OTTO" + self.embedded()[4:], "static TrueType")


class BrowserComparisonTest(Falsification):
    def setUp(self):
        self.measured = compare_pdf(WITNESS, positive_pdf())[1]

    def rejects(self, description, falsify, marker, browsers=BROWSERS):
        for browser in browsers:
            observation = positive_browser(browser)
            falsify(observation["elements"], browser)
            self.assertIn(marker, self.failed(compare_browser(WITNESS, self.measured, browser, observation)), description + " must fail on " + marker + " in " + browser)
        CONTROLS["negative"] += 1

    def redraw(self, identifier, face=None, like=None):
        """A browser drawing a sample with another program, or as another target or rule set draws it."""
        def falsify(elements, browser):
            shaped = WITNESS.shape(SAMPLES[identifier], like(browser) if callable(like) else like or browser, face)
            widen(elements[identifier], elements[identifier]["x"], [(letter["text"], letter["widthPx"]) for letter in shaped["characters"]])
            elements[identifier]["face"] = face or (elements[identifier]["face"] if like is None else shaped["face"])
        return falsify

    def test_positive_control(self):
        asserted = Counter()
        for browser in BROWSERS:
            checks = compare_browser(WITNESS, self.measured, browser, positive_browser(browser))
            self.assertEqual([], self.failed(checks))
            self.assertGreaterEqual(len(checks), 650)
            asserted.update(check["knownDeviation"] + ":" + browser for check in checks if check["property"].startswith(("browserDeviation", "browserNormal")))
            CONTROLS["positive"] += 1
        self.assertEqual({"chromium-small-caps-scale:chromium": 8, "chromium-slanted-faces-merged:chromium": 4, "style-family-skip:chromium": 4, "style-family-skip:firefox": 4,
                          "labelled-small-caps-face:chromium": 4, "labelled-small-caps-face:firefox": 4, "opentype-small-caps-feature:chromium": 2, "opentype-small-caps-feature:firefox": 2,
                          "normal-line-height:chromium": 3, "normal-line-height:firefox": 3}, dict(asserted))

    def test_incomplete_or_foreign_inventory_is_refused(self):
        for description, falsify in [("Missing sample", lambda observation: (observation["elements"].pop("caps-digits"), observation["order"].remove("caps-digits"))),
                                     ("Listed but unobserved sample", lambda observation: observation["elements"].pop("font-5")),
                                     ("Unknown extra element", lambda observation: observation["order"].append("extra")),
                                     ("Reordered inventory", lambda observation: observation["order"].reverse())]:
            observation = positive_browser("firefox")
            falsify(observation)
            self.refused(description, lambda: compare_browser(WITNESS, self.measured, "firefox", observation))
        self.refused("Absent observation", lambda: compare_browser(WITNESS, self.measured, "chromium", None))
        self.refused("Unknown browser", lambda: compare_browser(WITNESS, self.measured, "webkit", positive_browser("firefox")), "Unknown browser")

    def test_known_deviations_must_persist(self):
        self.rejects("Chromium synthesizing small capitals at 0.8", lambda elements, browser: [self.redraw(identifier, like={**RULES["chromium"], "small_caps_scale": 0.8})(elements, browser) for identifier in CAPITALS],
                     "caps-lowercase:browserSmallCapsScale", ["chromium"])
        self.rejects("Chromium shorthand small capitals at 0.8", self.redraw("font-5", like="pdf"), "font-5:browserDeviationWidthPt", ["chromium"])
        stretch = lambda identifier, factor: lambda elements, browser: widen(elements[identifier], elements[identifier]["x"], [(letter["text"], letter["width"] * factor) for letter in elements[identifier]["characters"]])
        self.rejects("Chromium synthesizing small capitals at 0.75", stretch("caps-bold", 0.75 / 0.7), "caps-bold:browserSmallCapsScale", ["chromium"])
        self.rejects("Chromium keeping italic and oblique labels apart", self.redraw("match-ladder-oblique-500", ITALIC), "match-ladder-oblique-500:browserDeviationFace", ["chromium"])
        self.rejects("Chromium answering oblique 400 with the oblique 300 face", self.redraw("match-ladder-oblique-400", ITALIC), "match-ladder-oblique-400:browserDeviationWidthPt", ["chromium"])
        self.rejects("Browser skipping the italic-only family", self.redraw("oblique-skips-italic", BOLD_ITALIC), "oblique-skips-italic:browserDeviationFace")
        self.rejects("Firefox answering oblique with the italic face", self.redraw("oblique-skips-italic", ITALIC), "oblique-skips-italic:browserDeviationFace", ["firefox"])
        self.rejects("Chromium answering oblique with the upright face", self.redraw("oblique-skips-italic", REGULAR), "oblique-skips-italic:browserDeviationFace", ["chromium"])
        self.rejects("Browser skipping the upright-only family", self.redraw("italic-skips-upright", ITALIC), "italic-skips-upright:browserDeviationFace")
        self.rejects("Browser honouring a small-caps label", self.redraw("caps-labelled", like="pdf"), "caps-labelled:browserDeviationWidthPt")
        self.rejects("Browser matching the weight after a small-caps label", self.redraw("caps-labelled-bold", like="pdf"), "caps-labelled-bold:browserDeviationFace")
        self.rejects("Browser synthesizing the labelled family from another face", self.redraw("caps-labelled", like=lambda browser: {**RULES[browser], "weight_order": lambda weight: [700, *WEIGHTS]}),
                     "caps-labelled:browserDeviationFace")
        self.rejects("Browser synthesizing the labelled family at the scale of the other browser", self.redraw("caps-labelled", like=lambda browser: RULES[BROWSERS[1 - BROWSERS.index(browser)]]),
                     "caps-labelled:browserSmallCapsScale")
        self.rejects("Browser synthesizing small capitals of a font that has them", self.redraw("caps-feature", like="pdf"), "caps-feature:browserDeviationWidthPt")
        self.rejects("Browser synthesizing them at its own scale", self.redraw("caps-feature", like=lambda browser: {**RULES[browser], "small_caps_feature": False}), "caps-feature:browserDeviationWidthPt")
        self.rejects("Browser oracle not classifying the small capitals of the font", lambda elements, browser: elements["caps-feature"].update(face=None), "caps-feature:browserDeviationFace")

    def test_normal_line_height_deviation(self):
        move = lambda identifier, step: lambda elements, browser: elements[identifier].update(y=elements[identifier.removesuffix("-again")]["y"] + step)
        self.rejects("Browser keeping the inherited 50px", move("row-font-2-again", 50), "row-font-2-again:browserNormalLineStepPt")
        self.rejects("Browser normal line height far from the font metrics", move("row-font-4-again", 1.5 * 18), "row-font-4-again:browserNormalLineStepPt")
        self.rejects("Browser not reporting its normal line height", lambda elements, browser: elements["row-font-4-again"].pop("y"), "row-font-4-again:browserLineStepPt")
        # The converter adopting the metrics of the font: the deviation has disappeared and must be re-examined.
        for browser in BROWSERS:
            observation = positive_browser(browser)
            observed = positive_pdf()
            for number in (2, 4):
                first, again = observation["elements"][f"row-font-{number}"], observation["elements"][f"row-font-{number}-again"]
                lowered(f"row-font-{number}-again", (again["y"] - first["y"]) * PX_TO_PT - (of(observed, f"font-{number}")[0]["y"] - of(observed, f"font-{number}-again")[0]["y"]))(observed)
            checks, measured = compare_pdf(WITNESS, observed)
            self.assertEqual([], self.failed(checks))
            self.assertEqual(["document:browserNormalLineHeightDiffersPt"], self.failed(compare_browser(WITNESS, measured, browser, observation)))
        CONTROLS["negative"] += 1

    def test_explicit_line_heights(self):
        move = lambda identifier, step: lambda elements, browser: elements[identifier].update(y=elements[identifier.removesuffix("-again")]["y"] + step)
        computed = lambda identifier, value: lambda elements, browser: elements[identifier]["computed"].update(lineHeight=value)
        self.rejects("Browser laying the points of a line height out as pixels", move("row-font-1-again", 14), "row-font-1-again:browserLineStepPt")
        self.rejects("Browser line step off by a tenth of a pixel", move("row-font-3-again", 26.5), "row-font-3-again:browserLineStepPt")
        self.rejects("Browser computing the inherited line height", computed("row-font-2", "50px"), "row-font-2:browserComputed")
        self.rejects("Browser computing normal for a percentage", computed("row-font-5-again", "normal"), "row-font-5-again:browserComputed")
        self.rejects("Browser computing another length", computed("row-font-1", "14px"), "row-font-1:browserComputed")
        self.rejects("Browser not computing a line height", lambda elements, browser: elements["row-font-3"]["computed"].pop("lineHeight"), "row-font-3:browserComputed")
        # A PDF whose explicit line step differs from the browsers'.
        observed = positive_pdf()
        lowered("row-font-3-again", 0.5)(observed)
        self.rejected("PDF line step differing from the browser", compare_browser(WITNESS, compare_pdf(WITNESS, observed)[1], "firefox", positive_browser("firefox")), "row-font-3-again:browserLineStepPt")

    def test_browsers_drawing_other_faces_or_sizes_than_the_pdf(self):
        self.rejects("Firefox synthesizing small capitals at 0.7", self.redraw("caps-mixed", like="chromium"), "caps-mixed:browserWidthPt", ["firefox"])
        self.rejects("Firefox merging italic and oblique labels", self.redraw("match-ladder-oblique-500", BOLD_ITALIC), "match-ladder-oblique-500:browserFace", ["firefox"])
        self.rejects("Browser drawing 500 with the bold face", self.redraw("match-lato-500", BOLD), "match-lato-500:browserFace")
        self.rejects("Browser using the nearest weight for 300", self.redraw("match-ladder-300", REGULAR), "match-ladder-300:browserWidthPt")
        self.rejects("Browser drawing bolder from 600 with the 700 face", self.redraw("bolder-from-600", BOLD), "bolder-from-600:browserFace")
        self.rejects("Browser drawing italic upright", self.redraw("style-i", REGULAR), "style-i:browserFace")
        self.rejects("Browser drawing the default family with the body font", self.redraw("fallback-default", REGULAR), "fallback-default:browserFace")
        self.rejects("Browser taking a present character from the second family", self.redraw("fallback-lato-noto", NOTO), "fallback-lato-noto:browserCharacterAdvancesPt")
        self.rejects("Browser measuring the accent apart from its arrow", self.redraw("fallback-cluster", like=lambda browser: {**RULES[browser], "fallback_unit": "codepoint"}),
                     "fallback-cluster:browserCharacterAdvancesPt")
        self.rejects("Browser oracle classifying another face", lambda elements, browser: elements["strong"].update(face=REGULAR), "strong:browserFace")
        self.rejects("Browser oracle not classifying an identifying sample", lambda elements, browser: elements["strong"].update(face=None), "strong:browserFace")
        self.rejects("Digits wider than in any supplied program", lambda elements, browser: widen(elements["absolute-12-large"], elements["absolute-12-large"]["x"], [("1", 12.0), ("8", 12.0)]),
                     "absolute-12-large:browserFace")

    def test_browsers_following_rival_rules(self):
        """A browser following a rival rule fails on every sample that tells the rule from its own. Three rivals change
        nothing in a browser, which has no small-caps label and uses OpenType small capitals."""
        without_effect = []
        for name, other in RIVALS.items():
            effective = 0
            for browser in BROWSERS:
                rules, own = {**RULES[browser], **other["rules"]}, positive_browser(browser)["elements"]
                observation = positive_browser(browser, rules)
                view = lambda element: [element.get("y"), element["computed"], [[letter["text"], letter["width"]] for letter in element.get("characters", [])]]
                changed = [identifier for identifier in other["separates"] if view(observation["elements"][identifier]) != view(own[identifier])]
                if changed:
                    failed = {check["case"] for check in compare_browser(WITNESS, self.measured, browser, observation) if not check["passed"]}
                    self.assertEqual([], [identifier for identifier in changed if identifier not in failed], "A browser following '" + name + "' must fail on its samples in " + browser)
                    effective += 1
            without_effect += [] if effective else [name]
            CONTROLS["negative"] += bool(effective)
        self.assertEqual(["small-caps label ignored", "weight before variant", "OpenType small capitals"], without_effect)

    def test_computed_values_and_geometry(self):
        computed = lambda identifier, key, value: lambda elements, browser: elements[identifier]["computed"].update({key: value})
        self.rejects("Bolder from 600 computed as 700", computed("bolder-from-600", "fontWeight", 700), "bolder-from-600:browserComputed")
        self.rejects("Larger from medium computed as large", computed("larger-from-medium", "fontSizePx", 18), "larger-from-medium:browserComputed")
        self.rejects("Oblique computed as italic", computed("style-oblique", "fontStyle", "italic"), "style-oblique:browserComputed")
        self.rejects("Small capitals computed as normal", computed("caps-inherited", "fontVariant", "normal"), "caps-inherited:browserComputed")
        self.rejects("Row at another size", computed("row-absolute-30", "fontSizePx", 12), "row-absolute-30:browserComputed")
        self.rejects("Row indent computed against the parent size", computed("row-indent-larger", "textIndentPx", 70), "row-indent-larger:browserComputed")
        self.rejects("Family list parsed differently", lambda elements, browser: elements["fallback-noto-lato"].update(families=["Noto", "Sans", "Lato"]), "fallback-noto-lato:browserComputed")
        self.rejects("Generic family read as a name", lambda elements, browser: elements["font-3"].update(families=["new century schoolbook", "serif"]), "font-3:browserComputed")
        self.rejects("Relative weight sample observed in the body family", lambda elements, browser: elements["lighter-from-600"].update(families=["Lato", "Noto Sans"]), "lighter-from-600:browserComputed")
        self.rejects("Another text measured", lambda elements, browser: elements["style-em"].update(text="maximum"), "style-em:browserComputed")
        self.rejects("Sample shifted by one pixel", lambda elements, browser: elements["match-lato-900"].update(x=elements["match-lato-900"]["x"] + 1), "match-lato-900:browserXPt")
        self.rejects("Samples after small capitals not moved in Chromium", lambda elements, browser: elements["caps-restored"].update(x=SAMPLES["caps-restored"]["xPx"]),
                     "caps-restored:browserXPt", ["chromium"])
        self.rejects("Width that is not a number", lambda elements, browser: elements["strong"].update(width=float("nan")), "strong:browserWidthPt")
        self.rejects("Missing character boxes", lambda elements, browser: elements["style-em"].pop("characters"), "style-em:browserCharacterAdvancesPt")
        self.rejects("Characters merged in one box", lambda elements, browser: elements["fallback-noto-lato"]["characters"].pop(), "fallback-noto-lato:browserCharacterAdvancesPt")

    def test_pdf_sample_that_was_not_measured(self):
        del self.measured["strong"]
        self.rejects("Sample missing from the PDF measurements", lambda elements, browser: None, "strong:browserWidthPt")

    def test_pdf_drawn_as_a_browser_deviates(self):
        feature = WITNESS.layout("chromium")["samples"]["caps-feature"]["segments"][0]["glyphs"]
        for description, falsify, marker, browser in [
                ("PDF small capitals at 0.7 hide the Chromium deviation", small_capitals_at(0.7), "caps-lowercase:browserDeviationWidthPt", "chromium"),
                ("PDF staying in the italic-only family hides the Chromium deviation", redraw("oblique-skips-italic", ITALIC), "oblique-skips-italic:browserDeviationWidthPt", "chromium"),
                ("PDF merging italic and oblique labels hides the Chromium deviation", redraw("match-ladder-oblique-500", BOLD_ITALIC),
                 "match-ladder-oblique-500:browserDeviationWidthPt", "chromium"),
                ("PDF ignoring the small-caps label hides the Firefox deviation", lambda observed: (redraw("caps-labelled", REGULAR, str.upper)(observed), resize("caps-labelled", 16)(observed)),
                 "caps-labelled:browserDeviationWidthPt", "firefox"),
                ("PDF using the OpenType small capitals hides the deviation", lambda observed: (resize("caps-feature", 20)(observed), edit("caps-feature", glyphs=[{"gid": glyph["gid"], "text": glyph["text"]} for glyph in feature])(observed)),
                 "caps-feature:browserDeviationWidthPt", "firefox"),
                ("PDF drawn with another face than Firefox", redraw("match-lato-600", REGULAR), "match-lato-600:browserWidthPt", "firefox")]:
            observed = positive_pdf()
            falsify(observed)
            self.rejected(description, compare_browser(WITNESS, compare_pdf(WITNESS, observed)[1], browser, positive_browser(browser)), marker)


class TargetsTest(Falsification):
    def rejects(self, description, falsify, marker):
        targets = positive_targets()
        falsify(targets)
        self.rejected(description, compare_targets(WITNESS, targets), marker)

    def test_positive_control(self):
        checks = compare_targets(WITNESS, positive_targets())
        self.assertEqual([], self.failed(checks))
        self.assertEqual(2 + 161, len(checks))
        CONTROLS["positive"] += 1

    def test_disagreeing_derivations_fail(self):
        segment = lambda identifier, index, **keys: lambda targets: targets["samples"][identifier]["segments"][index].update(keys)
        sample = lambda identifier, **keys: lambda targets: targets["samples"][identifier].update(keys)
        row = lambda identifier, **keys: lambda targets: targets["rows"][identifier].update(keys)
        self.rejects("Other face", segment("match-ladder-300", 0, face=REGULAR), "match-ladder-300:normTarget")
        self.rejects("Other Scale face", segment("bolder-from-600", 0, face=BOLD), "bolder-from-600:normTarget")
        self.rejects("Other size", segment("caps-mixed", 1, fontSizePx=14), "caps-mixed:normTarget")
        self.rejects("Other drawn text", segment("caps-sharp-s", 0, drawn="MAS"), "caps-sharp-s:normTarget")
        self.rejects("Labelled face synthesized", segment("caps-labelled", 0, drawn="MINIMUM"), "caps-labelled:normTarget")
        self.rejects("Other segmentation", lambda targets: targets["samples"]["fallback-lato-noto"]["segments"].pop(), "fallback-lato-noto:normTarget")
        self.rejects("Cluster split", segment("fallback-cluster", 1, text="\u2192"), "fallback-cluster:normTarget")
        self.rejects("Other position", sample("b-in-strong", xPx=70.0), "b-in-strong:normTarget")
        self.rejects("Other computed weight", sample("bolder-from-600", fontWeight=700), "bolder-from-600:normTarget")
        self.rejects("Other computed size", sample("larger-from-medium", fontSizePx=18), "larger-from-medium:normTarget")
        self.rejects("Other text offset", sample("font-5", compact={"offset": 0, "length": 7}), "font-5:normTarget")
        self.rejects("Deviation no longer recorded", sample("caps-lowercase", witnessedBy=["chromium", "firefox"]), "caps-lowercase:normTarget")
        self.rejects("Deviation recorded under another name", sample("caps-feature", knownDeviations={"chromium": "chromium-small-caps-scale", "firefox": "opentype-small-caps-feature"}), "caps-feature:normTarget")
        self.rejects("Unexpected deviation recorded", sample("strong", witnessedBy=["firefox"]), "strong:normTarget")
        self.rejects("Other row indent", row("row-indent-larger", indentPx=70), "row-indent-larger:normTarget")
        self.rejects("Other line height", row("row-font-3", lineHeight=27.5), "row-font-3:normTarget")
        self.rejects("Inherited line height instead of normal", row("row-font-2-again", lineHeight=50), "row-font-2-again:normTarget")
        self.rejects("Block not marked as repeated", lambda targets: targets["rows"]["row-font-4-again"].pop("repeats"), "row-font-4-again:normTarget")
        self.rejects("Other content edge", lambda targets: targets.update(contentLeftPt=54), "document:normContentLeftPt")
        self.rejects("Fewer intended diagnostics", lambda targets: targets["intendedDiagnostics"].pop(), "document:normIntendedDiagnostics")
        self.rejects("Diagnostic of another sample", lambda targets: targets["intendedDiagnostics"][0].update(sample="match-lato-900"), "document:normIntendedDiagnostics")

    def test_incomplete_targets_are_refused(self):
        for description, falsify in [("Missing sample", lambda targets: targets["samples"].pop("caps-digits")), ("Missing row", lambda targets: targets["rows"].pop("row-font-5")),
                                     ("Extra sample", lambda targets: targets["samples"].update(extra={}))]:
            targets = positive_targets()
            falsify(targets)
            self.refused(description, lambda: compare_targets(WITNESS, targets))
        self.refused("Absent targets", lambda: compare_targets(WITNESS, None))


class ReceiptTest(Falsification):
    def setUp(self):
        self.pdf = b"bounded test PDF bytes"
        codes = ["font.weight-fallback", "font.weight-fallback", "font.default-family", "font.default-family", "font.style-fallback", "font.weight-fallback"]
        self.record = {"engine": "native-php", "sourceSha256": digest(WITNESS.source_bytes), "bindingsSha256": digest(WITNESS.binding_bytes), "pdfSha256": digest(self.pdf), "pages": PAGES,
                       "diagnostics": [{"code": code, "message": "", "nodeId": None} for code in codes],
                       "bindings": copy.deepcopy(WITNESS.bindings), "fontFiles": {entry["file"]: entry["sha256"] for entry in WITNESS.files.values()}}
        self.report = {"status": "pass", "sourceSha256": digest(WITNESS.source_bytes), "bindingsSha256": digest(WITNESS.binding_bytes), "fontFiles": copy.deepcopy(WITNESS.files),
                       "browsers": {name: {"passed": True, "version": "1"} for name in BROWSERS}, "knownDeviations": dict(KNOWN_DEVIATIONS), "cssPxToPdfPt": 0.75, "contentLeftCssPx": 48,
                       "inventory": list(WITNESS.source.order), "compactTextLength": len(compact(WITNESS.source.text))}

    def test_positive_control(self):
        self.assertEqual(self.record, receipt(self.record, self.pdf, WITNESS))
        self.assertEqual({"chromium": "1", "firefox": "1"}, browser_receipt(self.report, WITNESS))
        CONTROLS["positive"] += 1

    def test_foreign_manifest_is_refused(self):
        swapped = dict(zip(self.record["fontFiles"], [*list(self.record["fontFiles"].values())[1:], next(iter(self.record["fontFiles"].values()))]))
        exchanged = copy.deepcopy(self.record["bindings"])
        exchanged["families"]["Lato"]["faces"][0]["file"], exchanged["families"]["Lato"]["faces"][1]["file"] = (face["file"] for face in reversed(exchanged["families"]["Lato"]["faces"][:2]))
        for key, value in [("engine", "chromium"), ("sourceSha256", "0" * 64), ("bindingsSha256", "0" * 64), ("pdfSha256", "0" * 64), ("pages", PAGES - 1), ("pages", PAGES + 1), ("diagnostics", []),
                           ("diagnostics", self.record["diagnostics"][:-1]), ("diagnostics", self.record["diagnostics"] + [{"code": "css.unsupported"}]),
                           ("diagnostics", self.record["diagnostics"][:1] * 6), ("diagnostics", self.record["diagnostics"] + [{"code": "font.cluster-split"}]),
                           ("diagnostics", self.record["diagnostics"] + [{"code": "font.small-caps-fallback"}]), ("bindings", {**self.record["bindings"], "generic": {}}),
                           ("bindings", exchanged), ("fontFiles", swapped), ("fontFiles", {**self.record["fontFiles"], "examples/assets/fonts/Lato-Bold.ttf": "0" * 64})]:
            with self.subTest(key=key, value=value):
                self.refused("Manifest with another " + key, lambda: receipt({**self.record, key: value}, self.pdf, WITNESS), "")
        self.refused("Another PDF than the manifest names", lambda: receipt(self.record, self.pdf + b" ", WITNESS), "another PDF")

    def test_foreign_browser_report_is_refused(self):
        for key, value in [("status", "fail"), ("sourceSha256", "0" * 64), ("bindingsSha256", "0" * 64), ("fontFiles", {}), ("contentLeftCssPx", 72), ("compactTextLength", 1),
                           ("browsers", {"chromium": {"passed": True, "version": "1"}}), ("browsers", {**self.report["browsers"], "firefox": {"passed": False, "version": "1"}}),
                           ("knownDeviations", {"relative-size-ratio": ""}), ("knownDeviations", {name: "" for name in list(KNOWN_DEVIATIONS)[:-1]}), ("inventory", self.report["inventory"][:-1])]:
            with self.subTest(key=key, value=value):
                self.refused("Browser report with another " + key, lambda: browser_receipt({**self.report, key: value}, WITNESS), "")


class PopplerTextTest(Falsification):
    def rejects(self, description, raw, reading, marker):
        self.rejected(description, poppler_checks(WITNESS, raw, reading), marker)

    def test_positive_control(self):
        source = WITNESS.source.text
        self.assertEqual([], self.failed(poppler_checks(WITNESS, source, source)))
        # Poppler in content order may drop the space after a much larger word; in reading order it may move words.
        self.assertEqual(2, source.count("24\n    32"))
        self.assertEqual([], self.failed(poppler_checks(WITNESS, source.replace("24\n    32", "2432"), " ".join(reversed(source.split())))))
        CONTROLS["positive"] += 1

    def test_altered_text_fails(self):
        source = WITNESS.source.text
        self.assertEqual([1, 1, 1], [source.count(text) for text in ("Nominal", "No.3", "n\u2192\u0301n")])
        self.rejects("Space at a change of size", source.replace("Nominal", "N ominal"), source, "document:popplerInsertedSpaces")
        self.rejects("Word cut at a change of size", source.replace("Nominal", "N ominal"), source, "caps-mixed:popplerWholeWord")
        self.rejects("Word cut at a change of size in reading order", source, source.replace("Nominal", "N ominal"), "caps-mixed:popplerWholeWord")
        self.rejects("Word cut before its punctuation", source.replace("No.3", "No .3"), source, "caps-punctuation:popplerWholeWord")
        self.rejects("Space at a change of face", source.replace("n\u211dn\u0377", "n \u211d n\u0377"), source, "fallback-lato-noto:popplerWholeWord")
        self.rejects("Space before a combining accent", source.replace("\u2192\u0301", "\u2192 \u0301"), source, "fallback-cluster:popplerWholeWord")
        self.rejects("Combining accent lost", source.replace("\u0301", ""), source, "document:popplerText")
        self.rejects("Space inside a word of the prose", source.replace("TrueType", "True Type"), source, "document:popplerInsertedSpaces")
        self.rejects("Capitals extracted for small capitals", source.replace("Nominal", "NOMINAL"), source, "document:popplerText")
        self.rejects("Sharp s extracted as SS", source.replace("ma\u00df", "maSS"), source, "document:popplerText")
        self.rejects("Fallback character lost", source.replace("\u211d", ""), source, "document:popplerText")
        self.rejects("Missing last line", source[:source.rindex("minimum")], source, "document:popplerText")
        self.rejects("Words merged in reading order", source, " ".join(source.split()).replace("Nominal 2008", "Nominal2008"), "document:popplerWords")
        self.rejects("Word missing in reading order", source, source.replace("Nominal", ""), "document:popplerWords")


class RenderTest(Falsification):
    def setUp(self):
        self.measured = compare_pdf(WITNESS, positive_pdf())[1]
        self.images = [Image.new("RGB", (794, 1123), "white") for _ in range(PAGES)]
        for sample in self.measured.values():
            ImageDraw.Draw(self.images[sample["page"] - 1]).rectangle(self.pixels(sample, 4), fill=(29, 51, 64))
        for image in self.images:
            ImageDraw.Draw(image).rectangle((100, 1090, 300, 1110), fill="black")

    @staticmethod
    def pixels(sample, above, below=0, beside=0):
        """Pixel rectangle around the baseline of a measured sample, at 96 pixels per inch."""
        baseline = PAGE_HEIGHT_PT - sample["y"]
        return [value * 96 / 72 for value in (sample["xPt"] - beside, baseline - above, sample["xPt"] + sample["widthPt"] + beside, baseline + below)]

    def rejects(self, description, images, marker):
        self.rejected(description, render_checks(images, self.measured), marker)

    def test_positive_control(self):
        self.assertTrue(all(0 < sample["y"] < PAGE_HEIGHT_PT for sample in self.measured.values()))
        checks = render_checks(self.images, self.measured)
        self.assertEqual([], self.failed(checks))
        self.assertEqual(1 + 2 * PAGES + 130, len(checks))
        CONTROLS["positive"] += 1

    def test_blank_missing_or_misplaced_rendering_fails(self):
        blank = Image.new("RGB", (794, 1123), "white")
        self.rejects("Blank page", [*self.images[:-1], blank], "page-%d:renderedInk" % PAGES)
        self.rejects("Sample missing from a page with other ink", [*self.images[:-1], blank], "font-5:renderedInk")
        self.rejects("Missing page", self.images[:-1], "document:renderedPages")
        self.rejects("Sample of a page that was not rendered", self.images[:-1], "font-5-again:renderedInk")
        self.rejects("Points confused with pixels", [image.resize((595, 842)) for image in self.images], "page-1:renderedSize")
        erased = self.images[-1].copy()
        ImageDraw.Draw(erased).rectangle(self.pixels(self.measured["fallback-default"], 20, 5, 1), fill="white")
        self.rejects("One sample not painted", [*self.images[:-1], erased], "fallback-default:renderedInk")
        shifted = Image.new("RGB", (794, 1123), "white")
        shifted.paste(self.images[0], (0, 60))
        self.rejects("Page content painted elsewhere", [shifted, *self.images[1:]], "strong:renderedInk")
        pale = Image.eval(self.images[0], lambda value: 255 - (255 - value) // 8)
        self.rejects("Ink too pale to read", [pale, *self.images[1:]], "page-1:renderedInk")
        self.assertAlmostEqual(PAGE_WIDTH_PT * 96 / 72, 794, delta=1)


class CommandLineTest(unittest.TestCase):
    def test_failed_validation_preserves_report(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            report = FontsPdfOracle(target / "missing.pdf", target / "missing.json", target, target / "browser.json").inspect()
            self.assertEqual("fail", report["status"])
            self.assertIn("error", report)
            self.assertEqual("fail", json.loads((target / "pdf-report.json").read_text(encoding="utf-8"))["status"])
        CONTROLS["negative"] += 1


if __name__ == "__main__":
    unittest.main()
