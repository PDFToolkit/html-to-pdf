"""Bounded independent PDF oracle for one unchanged historical CSS1 fixture.

This tool does not claim full CSS1, PDF/A or PDF/UA conformance. It checks actual
PDF bytes and Poppler raster output; it never trusts PHP layout coordinates.
"""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
import math
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

from PIL import Image, ImageDraw
import PIL
import pypdf
from pypdf import PdfReader

from pdf_text_oracle import PdfTextOracle

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "resources/css1/w3c"
SOURCE_HASH = "6dcacbb4314074283548010682dcb632abe459852f38f18c41abc8a33d321097"
REFERENCE_HASH = "ee74c43a59b9622ed6fd25381a7cd7f282a90fe0a1c147de90090a5d8cda6e08"
NAMESPACE = "{http://www.w3.org/1999/xhtml}"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def compact(text):
    return re.sub(r"\s+", "", text)


def run(*args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=60, check=True)
    return (result.stdout + result.stderr).strip()


class SourceText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.inside_body = False
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag == "body":
            self.inside_body = True

    def handle_endtag(self, tag):
        if tag == "body":
            self.inside_body = False

    def handle_data(self, data):
        if self.inside_body:
            self.parts.append(data)

    def text(self):
        return " ".join(self.parts)


class HistoricalRasterOracle:
    """Source-derived opaque rectangles, excluding edges and font/widget areas.

    Coordinates are CSS pixels. The body bottom depends on footer font metrics,
    so the footer band is excluded here and checked with independent text boxes.
    Geometry is fixed by the source declarations, before inspecting PHP output.
    """

    COLORS = {"blue": (0, 0, 255), "white": (255, 255, 255), "black": (0, 0, 0),
              "red": (204, 0, 0), "yellow": (255, 204, 0)}
    # (left, top, width, height, color). Later rectangles paint over earlier ones.
    RECTANGLES = [
        (15, 15, 490, 320, "black"), (20, 20, 480, 315, "white"),
        (25, 25, 79.9986, 310, "black"), (30, 30, 69.9986, 300, "red"),
        (115, 25, 380, 310, "black"), (125, 35, 360, 290, "white"),
        (135, 45, 80, 120, "black"), (140, 50, 70, 110, "yellow"),
        (225, 45, 159.978, 110, "black"),
        (394.978, 45, 80, 120, "black"), (399.978, 50, 70, 110, "yellow"),
        (135, 175, 120, 120, "black"), (275, 175, 70, 140, "black"),
        (280, 185, 50, 110, "yellow"), (355, 175, 120, 120, "black"),
    ]
    # Known text/widget areas, not the whole colored boxes. Keep backgrounds and
    # all border segments outside these small regions independently observable.
    EXCLUSIONS = [
        (38, 33, 62, 37), (148, 53, 62, 37), (233, 53, 142, 88),
        (408, 53, 62, 47), (143, 183, 102, 42), (278, 193, 54, 62),
        (363, 183, 104, 62), (13, 333, 494, 117),
    ]

    @staticmethod
    def box(x, y, width, height):
        # Include pixel centers within the half-open CSS box.
        return (math.ceil(x - .5), math.ceil(y - .5),
                math.ceil(x + width - .5) - 1, math.ceil(y + height - .5) - 1)

    def model(self, size=(794, 1123)):
        assert size in ((794, 1123), (794, 1124)), "96 dpi exact-A4 raster required"
        expected = Image.new("RGB", size, self.COLORS["blue"])
        mask = Image.new("L", size, 255)
        draw, masked = ImageDraw.Draw(expected), ImageDraw.Draw(mask)
        for x, y, width, height, color in self.RECTANGLES:
            draw.rectangle(self.box(x, y, width, height), fill=self.COLORS[color])
            # 1.25 px edge exclusion admits subpixel quantization / antialiasing,
            # not box displacement. Negative tests reject a 5 px movement.
            for edge in [(x - 1.25, y - 1.25, width + 2.5, 2.5),
                         (x - 1.25, y + height - 1.25, width + 2.5, 2.5),
                         (x - 1.25, y - 1.25, 2.5, height + 2.5),
                         (x + width - 1.25, y - 1.25, 2.5, height + 2.5)]:
                masked.rectangle(self.box(*edge), fill=0)
        for rectangle in self.EXCLUSIONS:
            masked.rectangle(self.box(*rectangle), fill=0)
        # Rendering the last fractional row/column varies with page rounding.
        masked.rectangle((size[0] - 2, 0, size[0] - 1, size[1] - 1), fill=0)
        masked.rectangle((0, size[1] - 2, size[0] - 1, size[1] - 1), fill=0)
        return expected, mask

    def inspect(self, actual, output=None):
        actual = actual.convert("RGB")
        expected, mask = self.model(actual.size)
        names = {value: key for key, value in self.COLORS.items()}
        totals, failures = Counter(), Counter()
        mismatch = Image.new("RGB", actual.size, "white")
        mismatch_pixels = mismatch.load()
        for index, (wanted, observed, keep) in enumerate(zip(expected.get_flattened_data(), actual.get_flattened_data(), mask.get_flattened_data())):
            if not keep:
                continue
            color = names[wanted]
            totals[color] += 1
            if max(abs(a - b) for a, b in zip(wanted, observed)) > 4:
                failures[color] += 1
                mismatch_pixels[index % actual.width, index // actual.width] = (255, 0, 255)
        assert set(totals) == set(self.COLORS) and min(totals.values()) >= 8_000
        checks = {color: {"pixels": totals[color], "mismatched": failures[color],
                          "maximumMismatched": max(4, math.floor(totals[color] * .002)),
                          "passed": failures[color] <= max(4, math.floor(totals[color] * .002))}
                  for color in self.COLORS}
        if output is not None:
            expected.save(output / "expected-colors.png")
            mask.save(output / "compared-mask.png")
            mismatch.save(output / "color-mismatches.png")
        return {"passed": all(check["passed"] for check in checks.values()), "colors": checks,
                "edgeExclusionCssPx": 1.25, "channelTolerance": 4, "maximumMismatchFractionPerColor": .002,
                "scope": "Opaque box fills, border interiors, float placement, gaps and page canvas; font/widget areas and footer band excluded"}


class HistoricalPdfOracle:
    def __init__(self, pdf, manifest, output, qpdf="qpdf", pdftotext="pdftotext", pdftoppm="pdftoppm"):
        self.pdf, self.manifest, self.output = Path(pdf), Path(manifest), Path(output)
        self.qpdf, self.pdftotext, self.pdftoppm = qpdf, pdftotext, pdftoppm

    @staticmethod
    def receipt(record, pdf_bytes):
        assert record.get("engine") == "native-php", "Native PHP generation receipt required"
        assert record.get("sourceSha256") == SOURCE_HASH, "Exact source identity required"
        assert record.get("pdfSha256") == digest(pdf_bytes), "Receipt must bind these PDF bytes"
        assert record.get("pages") == 1, "This fixed-box source fits on one A4 page"
        assert record.get("diagnostics") == [], "No unsupported or fallback diagnostics allowed"
        fonts = record.get("fonts")
        assert isinstance(fonts, list) and fonts, "Explicitly supplied font provenance required"
        for font in fonts:
            assert isinstance(font.get("family"), str) and font["family"]
            assert re.fullmatch(r"[0-9a-f]{64}", font.get("sha256", "")), "Each supplied font needs a SHA-256"
        return record

    @staticmethod
    def text_boxes(tree, width, height):
        words = list(tree.iter(NAMESPACE + "word"))
        assert words, "Poppler must expose actual selectable text boxes"
        footer = []
        labels = {"toggle": (40, 35, 50, 280), "pluot?": (145, 185, 100, 100)}
        found = set()
        for word in words:
            x0, x1, y0, y1 = (float(word.attrib[key]) for key in ("xMin", "xMax", "yMin", "yMax"))
            assert -.5 <= x0 < x1 <= width + .5 and -.5 <= y0 < y1 <= height + .5, "Clipped or off-page text"
            value = " ".join((word.text or "").split())
            if value.startswith("This"):
                footer.append(y0)
            for label, (x, y, w, h) in labels.items():
                if value == label:
                    assert x * .75 - 1.5 <= x0 < x1 <= (x + w) * .75 + 1.5, "Label leaves its content box"
                    assert y * .75 - 1.5 <= y0 < y1 <= (y + h) * .75 + 1.5, "Label leaves its content box"
                    found.add(label)
        assert found == set(labels), "Known labels need independent position witnesses"
        assert len(footer) == 1 and 333 * .75 <= footer[0] <= 350 * .75, "Cleared paragraph must begin below outer float bottoms at y=335px"
        return {"words": len(words), "footerFirstGlyphTopPt": footer[0], "labelPositions": sorted(found)}

    def inspect(self):
        self.output.mkdir(parents=True, exist_ok=True)
        report = {"status": "fail", "scope": "Selected historical CSS1 display/box/float/clear source; not complete CSS1 or PDF profile conformance",
                  "sourceSha256": SOURCE_HASH, "sourceUnmodified": True, "cssPxToPdfPt": .75,
                  "tools": {"pypdf": pypdf.__version__, "pillow": PIL.__version__}}
        try:
            source = (FIXTURE / "test5526c.htm").read_bytes()
            assert digest(source) == SOURCE_HASH
            assert digest((FIXTURE / "sec5526c.gif").read_bytes()) == REFERENCE_HASH
            assert self.pdf.stat().st_size <= 32_000_000, "Bounded witness PDF required"
            raw = self.pdf.read_bytes()
            assert self.manifest.stat().st_size <= 1_048_576
            receipt = self.receipt(json.loads(self.manifest.read_text()), raw)
            report["receipt"] = receipt
            report["qpdf"] = run(self.qpdf, "--check", str(self.pdf))
            reader = PdfReader(self.pdf, strict=True)
            assert len(reader.pages) == 1
            page = reader.pages[0]
            width, height = float(page.mediabox.width), float(page.mediabox.height)
            assert abs(width - 210 * 72 / 25.4) < .01 and abs(height - 297 * 72 / 25.4) < .01, "Exact A4 dimensions required"
            assert int(page.get("/Rotate", 0)) == 0
            assert float(page.get("/UserUnit", 1)) == 1
            assert tuple(page.cropbox) == tuple(page.mediabox), "No crop-box concealment of overflow"
            report["page"] = {"widthPt": width, "heightPt": height, "pages": 1}
            catalog = reader.trailer["/Root"]
            assert catalog.get("/MarkInfo", {}).get("/Marked"), "Preserve the module's tagged output"
            assert "/StructTreeRoot" in catalog
            report["textOracle"] = PdfTextOracle().inspect(reader)
            text = report["textOracle"]["text"]
            parser = SourceText()
            parser.feed(source.decode("utf-8"))
            assert compact(text) == compact(parser.text()), "Every original body character must remain selectable and in source order"
            poppler = run(self.pdftotext, "-raw", "-enc", "UTF-8", str(self.pdf), "-")
            assert compact(poppler) == compact(text), "Poppler and explicit CMap decoding must agree"
            (self.output / "verified-text.txt").write_text(text)
            (self.output / "poppler-text.txt").write_text(poppler)
            bbox = self.output / "text-bounds.xhtml"
            run(self.pdftotext, "-bbox-layout", str(self.pdf), str(bbox))
            report["textBounds"] = self.text_boxes(ET.parse(bbox).getroot(), width, height)
            prefix = self.output / "native-96dpi"
            run(self.pdftoppm, "-f", "1", "-singlefile", "-r", "96", "-png", str(self.pdf), str(prefix))
            png = prefix.with_suffix(".png")
            with Image.open(png) as raster:
                report["raster"] = HistoricalRasterOracle().inspect(raster, self.output)
            report["render"] = {"file": png.name, "sha256": digest(png.read_bytes()), "dpi": 96}
            assert report["raster"]["passed"], "Independent colored-box mask differs from the native PDF"
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
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--pdftoppm", default="pdftoppm")
    args = parser.parse_args()
    report = HistoricalPdfOracle(args.pdf, args.manifest, args.output, args.qpdf, args.pdftotext, args.pdftoppm).inspect()
    print(json.dumps({"status": report["status"], "report": str(args.output / "pdf-report.json"), "error": report.get("error")}))
    return 0 if report["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
