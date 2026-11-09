"""Bounded independent CSS1 text geometry oracle over emitted PDF operators.

No PHP layout witness is trusted. Actual Encoding/ToUnicode mappings, positioned
glyphs, link rectangles, Poppler text and browser observations are cross-checked.
"""
from __future__ import annotations

import argparse
import hashlib
from html.parser import HTMLParser
import json
import math
from pathlib import Path
import re
import struct
import subprocess
import unicodedata

import pypdf
from pypdf import PdfReader
from pypdf.generic import ContentStream

from pdf_text_oracle import FontTextMap, PdfTextOracle

ROOT = Path(__file__).resolve().parents[1]
DELTAS = [("word-positive", "word-normal", 6), ("word-negative", "word-normal", -2),
          ("nbsp-positive", "nbsp-normal", 6), ("nbsp-negative", "nbsp-normal", -2),
          ("letter-positive", "letter-normal", 2), ("letter-negative", "letter-normal", -1),
          ("combined", "word-normal", 10), ("inherited", "word-normal", 10)]


def digest(data):
    return hashlib.sha256(data).hexdigest()


def compact(text):
    return re.sub(r"\s+", "", text)


def run(*args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=60, check=True)
    return (result.stdout + result.stderr).strip()


class FixtureSource(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=True)
        self.body = False
        self.case = None
        self.cases = {}
        self.text = []
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        if tag == "body":
            self.body = True
        if tag == "p" and "id" in dict(attrs):
            self.case = dict(attrs)["id"]
            assert self.case not in self.cases
            self.cases[self.case] = ""

    def handle_endtag(self, tag):
        if tag == "body":
            self.body = False
        if tag == "p":
            self.case = None

    def handle_data(self, text):
        if self.body:
            self.text.append(text)
        if self.case:
            self.cases[self.case] += text


def glyph_positions(reader):
    """Strict profile: each Tj contains one glyph after an explicit identity Tm."""
    result = []
    for page_index, page in enumerate(reader.pages):
        fonts = {str(name): (FontTextMap(font.get_object()), TrueTypeMetrics(font.get_object())) for name, font in page["/Resources"]["/Font"].items()}
        active, metrics, matrix, size = None, None, None, None
        contents = page.get_contents()
        assert len(contents.get_data()) <= 16_777_216, "Bounded decoded page contents required"
        for operation_index, (operands, operator) in enumerate(ContentStream(contents, reader).operations):
            assert operation_index <= 300_000, "Bounded content operation count required"
            assert len(result) <= 100_000, "Bounded glyph count required"
            if operator == b"Tf":
                active, metrics = fonts[str(operands[0])]
                size = float(operands[1])
            elif operator == b"Tm":
                assert len(operands) == 6 and list(operands[:4]) == [1, 0, 0, 1], "Only identity text matrices accepted"
                matrix = tuple(map(float, operands[4:]))
            elif operator == b"Tj":
                assert active is not None and matrix is not None
                raw = PdfTextOracle.original_bytes(operands[0])
                assert len(raw) == 2, "Require separately positioned two-byte glyph operands"
                text = active.decode(raw)
                assert all(math.isfinite(number) for number in matrix)
                result.append({"text": text, "x": matrix[0], "y": matrix[1], "page": page_index + 1, "size": size,
                               "naturalAdvance": metrics.advance(active.encoding.mapping[raw]) * size})
                matrix = None  # A fresh Tm must precede every glyph.
            elif operator in (b"cm", b"Td", b"TD", b"T*", b"TJ", b"'", b'"'):
                raise AssertionError("Unsupported coordinate or text operator: " + str(operator))
            elif operator in (b"Tc", b"Tw", b"Ts"):
                assert list(operands) == [0], "Glyph coordinates must not hide additional PDF text spacing"
            elif operator == b"Tz":
                assert list(operands) == [100]
    assert result
    return result


class TrueTypeMetrics:
    """Read unitsPerEm and horizontal advances from the embedded font program."""
    def __init__(self, font):
        program = font["/DescendantFonts"][0].get_object()["/FontDescriptor"]["/FontFile2"].get_data()
        assert 12 <= len(program) <= 16_777_216
        count = struct.unpack_from(">H", program, 4)[0]
        assert 1 <= count <= 256 and 12 + count * 16 <= len(program)
        tables = {}
        for offset in range(12, 12 + count * 16, 16):
            tag, _, start, size = struct.unpack_from(">4sIII", program, offset)
            assert tag not in tables and start + size <= len(program)
            tables[tag] = program[start:start + size]
        assert len(tables[b"head"]) >= 20 and len(tables[b"hhea"]) >= 36
        self.units = struct.unpack_from(">H", tables[b"head"], 18)[0]
        count = struct.unpack_from(">H", tables[b"hhea"], 34)[0]
        assert self.units > 0 and 1 <= count <= 65535 and len(tables[b"hmtx"]) >= count * 4
        self.widths = [struct.unpack_from(">H", tables[b"hmtx"], offset * 4)[0] for offset in range(count)]

    def advance(self, glyph):
        assert 0 <= glyph <= 65535
        return self.widths[min(glyph, len(self.widths) - 1)] / self.units


def case_positions(glyphs, source):
    characters = [(character, index) for index, glyph in enumerate(glyphs)
                  for character in glyph["text"] if not character.isspace()]
    text = "".join(character for character, _ in characters)
    assert text == compact("".join(source.text)), "Every source character must have a positioned, selectable glyph"
    cases = {}
    for name, expected in source.cases.items():
        needle = compact(expected)
        assert text.count(needle) == 1, "Each fixture case needs a unique text prefix"
        start = text.index(needle)
        indices = list(dict.fromkeys(index for _, index in characters[start:start + len(needle)]))
        rows = [glyphs[index] for index in indices]
        lines = []
        for glyph in rows:
            if not lines or lines[-1]["page"] != glyph["page"] or abs(lines[-1]["y"] - glyph["y"]) > .1:
                lines.append({"x": glyph["x"], "y": glyph["y"], "page": glyph["page"], "text": glyph["text"]})
            else:
                lines[-1]["text"] += glyph["text"]
        cases[name] = {"glyphs": rows, "letters": [row for row in rows[2:] if row["text"] in ("A", "B", "C", "D")], "lines": lines}
    return cases


def geometry_checks(cases):
    checks = []
    for variant, control, addition in DELTAS:
        assert len(cases[variant]["letters"]) == len(cases[control]["letters"]) == 4
        for index in range(4):
            observed = (cases[variant]["letters"][index]["x"] - cases[variant]["letters"][0]["x"]
                        - cases[control]["letters"][index]["x"] + cases[control]["letters"][0]["x"])
            expected = addition * index * .75
            checks.append({"case": variant, "property": "glyphStartDelta", "index": index,
                           "expectedPt": expected, "actualPt": observed, "passed": abs(observed - expected) <= .025})
    for name, first in [("indent-positive", 18), ("indent-negative", -9), ("indent-percent", 12)]:
        assert len(cases[name]["lines"]) >= 2
        for index, line in enumerate(cases[name]["lines"]):
            observed, expected = line["x"] - 54, first if index == 0 else 0
            checks.append({"case": name, "property": "lineIndent", "index": index, "expectedPt": expected,
                           "actualPt": observed, "passed": abs(observed - expected) <= .025})
    lines = cases["page-continuation"]["lines"]
    assert len({line["page"] for line in lines}) >= 2, "Pagination witness must actually continue onto another page"
    for index, line in enumerate(lines):
        observed, expected = line["x"] - 36, 18 if index == 0 else 0
        checks.append({"case": "page-continuation", "property": "lineIndentAcrossPages", "index": index,
                       "expectedPt": expected, "actualPt": observed, "passed": abs(observed - expected) <= .025})
    def cluster_distance(name):
        rows = cases[name]["glyphs"][2:]
        return next(row["x"] for row in rows if row["text"] == "b") - rows[0]["x"]
    distance = cluster_distance("combining-positive") - cluster_distance("combining-normal")
    checks.append({"case": "combining-positive", "property": "oneGapAfterGrapheme", "expectedPt": 1.5,
                   "actualPt": distance, "passed": abs(distance - 1.5) <= .025})
    spaced = cases["ligature-positive"]["glyphs"][2:]
    checks.append({"case": "ligature-positive", "property": "optionalLigaturesDisabled",
                   "actual": [row["text"] for row in spaced], "expected": list("office"),
                   "passed": [row["text"] for row in spaced] == list("office")})
    return checks


class TextSpacingPdfOracle:
    def __init__(self, pdf, manifest, output, browser_report, qpdf="qpdf", pdftotext="pdftotext", pdftoppm="pdftoppm"):
        self.pdf, self.manifest, self.output = Path(pdf), Path(manifest), Path(output)
        self.browser_report = Path(browser_report)
        self.qpdf, self.pdftotext, self.pdftoppm = qpdf, pdftotext, pdftoppm

    def inspect(self):
        self.output.mkdir(parents=True, exist_ok=True)
        report = {"status": "fail", "scope": "Selected CSS1 spacing, grapheme preservation, first-line indentation and pagination; no complete CSS1 or PDF conformance claim", "pypdf": pypdf.__version__}
        try:
            assert self.pdf.stat().st_size <= 16_777_216 and self.manifest.stat().st_size <= 1_048_576
            assert self.browser_report.stat().st_size <= 4_194_304
            source_bytes = (ROOT / "examples/sources/text-spacing.html").read_bytes()
            font = (ROOT / "tests/Fixtures/fonts/NotoSans-Regular.ttf").read_bytes()
            source = FixtureSource(source_bytes.decode("utf-8"))
            record = json.loads(self.manifest.read_text())
            assert record["engine"] == "native-php" and record["diagnostics"] == []
            assert record["sourceSha256"] == digest(source_bytes) and record["pdfSha256"] == digest(self.pdf.read_bytes())
            assert record["fonts"] == [{"family": "Noto Sans (explicit fallback)", "sha256": digest(font)}]
            report["receipt"] = record
            report["qpdf"] = run(self.qpdf, "--check", str(self.pdf))
            reader = PdfReader(self.pdf, strict=True)
            assert len(reader.pages) == record["pages"] and 3 <= len(reader.pages) <= 5
            assert reader.trailer["/Root"]["/MarkInfo"]["/Marked"] and "/StructTreeRoot" in reader.trailer["/Root"]
            for page in reader.pages:
                assert abs(float(page.mediabox.width) - 595.275590551) < .01 and abs(float(page.mediabox.height) - 841.88976378) < .01
                assert tuple(page.cropbox) == tuple(page.mediabox) and int(page.get("/Rotate", 0)) == 0 and float(page.get("/UserUnit", 1)) == 1
            report["textOracle"] = PdfTextOracle().inspect(reader)
            assert compact(report["textOracle"]["text"]) == compact("".join(source.text))
            poppler = run(self.pdftotext, "-raw", "-enc", "UTF-8", str(self.pdf), "-")
            assert unicodedata.normalize("NFC", compact(poppler)) == unicodedata.normalize("NFC", compact("".join(source.text))), "Poppler must independently preserve the complete Unicode source"
            (self.output / "poppler-text.txt").write_text(poppler)
            glyphs = glyph_positions(reader)
            assert all(0 <= row["x"] < 595.28 and 0 < row["y"] < 841.89 for row in glyphs), "Glyph outside its physical page"
            cases = case_positions(glyphs, source)
            checks = geometry_checks(cases)
            space = next(row["naturalAdvance"] for row in glyphs if row["text"] == " " and row["size"] == 12)
            for case, gap in [("word-normal", space), ("nbsp-normal", space), ("letter-normal", 0)]:
                letters = cases[case]["letters"]
                for index in range(1, 4):
                    observed = letters[index]["x"] - letters[index - 1]["x"]
                    expected = letters[index - 1]["naturalAdvance"] + gap
                    checks.append({"case": case, "property": "embeddedTrueTypeAdvance", "index": index,
                                   "actualPt": observed, "expectedPt": expected, "passed": abs(observed - expected) <= .025})
            browser = json.loads(self.browser_report.read_text())
            assert browser["status"] == "pass" and browser["sourceSha256"] == digest(source_bytes) and browser["fontSha256"] == digest(font)
            assert set(browser["browsers"]) == {"chromium", "firefox"}
            for name, observation in browser["browsers"].items():
                assert observation["passed"]
                assert observation["fontBinding"] == {"family": "ExplicitWitness", "status": "loaded", "faces": 1}
                for case, control, _ in DELTAS:
                    actual, baseline = cases[case]["letters"], cases[control]["letters"]
                    expected = observation["observed"][case]["letters"]
                    browser_baseline = observation["observed"][control]["letters"]
                    for index in range(4):
                        pdf_x = actual[index]["x"] - actual[0]["x"] - baseline[index]["x"] + baseline[0]["x"]
                        browser_x = (expected[index]["x"] - expected[0]["x"] - browser_baseline[index]["x"] + browser_baseline[0]["x"]) * .75
                        checks.append({"case": case, "property": "browserGlyphStartDelta", "browser": name, "index": index,
                                       "actualPt": pdf_x, "expectedPt": browser_x, "passed": abs(pdf_x - browser_x) <= .08})
            report["browserComparisonScope"] = "Glyph start changes against each engine's unspaced control; raw font metrics differ with browser pixel hinting. PDF natural advances independently checked against embedded TrueType hmtx."
            annotations = [(number + 1, annotation.get_object()) for number, page in enumerate(reader.pages) for annotation in page.get("/Annots", [])]
            assert len(annotations) == 1
            page, annotation = annotations[0]
            assert annotation["/Subtype"] == "/Link" and annotation["/A"]["/URI"] == "https://example.org/css1-spacing"
            rectangle = tuple(map(float, annotation["/Rect"]))
            linked = cases["letter-positive"]["glyphs"]
            assert all(row["page"] == page and rectangle[0] - .01 <= row["x"] < rectangle[2]
                       and rectangle[1] <= row["y"] <= rectangle[3] for row in linked), "Spaced glyphs must remain inside their link annotation"
            assert abs(rectangle[0] - linked[0]["x"]) < .025
            assert abs(rectangle[2] - linked[-1]["x"] - linked[-1]["naturalAdvance"] - 1.5) < .025, "Link width must include the final letter-spacing advance"
            report["link"] = {"page": page, "rectangle": rectangle, "lastGlyphX": linked[-1]["x"]}
            report["cases"] = cases
            report["checks"] = checks
            report["comparisons"] = len(checks)
            assert all(check["passed"] for check in checks), "Native spacing, indentation or browser glyph comparison failed"
            run(self.pdftotext, "-bbox-layout", str(self.pdf), str(self.output / "text-bounds.xhtml"))
            run(self.pdftoppm, "-r", "96", "-png", str(self.pdf), str(self.output / "native"))
            report["renders"] = [{"file": path.name, "sha256": digest(path.read_bytes())} for path in sorted(self.output.glob("native-*.png"))]
            assert len(report["renders"]) == len(reader.pages)
            report["status"] = "pass"
        except Exception as error:
            report["error"] = str(error) or type(error).__name__
        (self.output / "pdf-report.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
        return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pdf", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--browser-report", type=Path, required=True)
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--pdftoppm", default="pdftoppm")
    args = parser.parse_args()
    report = TextSpacingPdfOracle(args.pdf, args.manifest, args.output, args.browser_report, args.qpdf, args.pdftotext, args.pdftoppm).inspect()
    print(json.dumps({"status": report["status"], "comparisons": report.get("comparisons"), "error": report.get("error")}))
    return 0 if report["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
