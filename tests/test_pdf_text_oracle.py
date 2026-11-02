"""Regression/mutation witnesses for the strict generated-font text oracle."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys
import unittest

import pypdf
from pypdf import PdfReader
from pypdf.generic import ByteStringObject, ContentStream, NameObject

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from pdf_text_oracle import ExplicitCMap, PdfTextOracle  # noqa: E402


class TextOracleRegressionTest(unittest.TestCase):
    pdf_path = Path(__file__).resolve().parents[1] / "_build/examples/invoice.pdf"

    def setUp(self):
        self.reader = PdfReader(self.pdf_path, strict=True)
        self.font = self.reader.pages[0]["/Resources"]["/Font"]["/F1"].get_object()

    def test_valid_maps_and_original_bytes_preserve_unicode_and_ligatures(self):
        report = PdfTextOracle().inspect(self.reader)
        self.assertTrue(report["text"].startswith("ATELIER DES OBJETS"))
        self.assertIn("céramique", report["text"])
        self.assertIn("€", report["text"])
        self.assertNotIn("\x00", report["text"])
        self.assertGreater(report["pages"][0]["glyphs"], 100)
        self.assertGreater(report["pages"][0]["runs"], 10)
        cmap = ExplicitCMap(self.font["/ToUnicode"].get_data(), True)
        self.assertIn("fi", cmap.mapping.values())

    def test_changed_unicode_mapping_is_rejected_by_actualtext_crosscheck(self):
        stream = self.font["/ToUnicode"]
        raw = stream.get_data()
        self.assertIn(b"<0001> <0041>", raw)
        stream.set_data(raw.replace(b"<0001> <0041>", b"<0001> <005A>", 1))
        with self.assertRaisesRegex(AssertionError, "ActualText"):
            PdfTextOracle().inspect(self.reader)

    def test_identity_cid_must_reference_an_existing_embedded_glyph(self):
        stream = self.font["/Encoding"]
        stream.set_data(stream.get_data().replace(b"<0001> 3", b"<0001> 65535", 1))
        with self.assertRaisesRegex(AssertionError, "maxp.numGlyphs"):
            PdfTextOracle().inspect(self.reader)

    def test_stream_metadata_must_agree_with_cmap_program(self):
        self.font["/Encoding"][NameObject("/CMapName")] = NameObject("/DifferentName")
        with self.assertRaisesRegex(AssertionError, "names must agree"):
            PdfTextOracle().inspect(self.reader)

    def test_odd_length_text_operand_is_rejected(self):
        self.replace_first_operand(b"\x00")
        with self.assertRaisesRegex(AssertionError, "two-byte character code"):
            PdfTextOracle().inspect(self.reader)

    def test_unmapped_text_operand_is_rejected(self):
        self.replace_first_operand(b"\xff\xff")
        with self.assertRaisesRegex(AssertionError, "unmapped character code"):
            PdfTextOracle().inspect(self.reader)

    def test_declared_mapping_counts_are_checked(self):
        raw = self.font["/ToUnicode"].get_data()
        import re
        bad = re.sub(rb"\d+ beginbfchar", b"1 beginbfchar", raw, count=1)
        with self.assertRaisesRegex(AssertionError, "count"):
            ExplicitCMap(bad, True)

    def test_duplicate_character_codes_are_rejected(self):
        raw = self.font["/ToUnicode"].get_data().replace(b"<0002> <0054>", b"<0001> <0054>", 1)
        with self.assertRaisesRegex(AssertionError, "duplicate mappings"):
            ExplicitCMap(raw, True)

    def test_utf16_ligatures_and_surrogate_pairs_are_decoded_strictly(self):
        raw = self.font["/ToUnicode"].get_data()
        valid = raw.replace(b"<0001> <0041>", b"<0001> <D83DDE00>", 1)
        self.assertEqual(ExplicitCMap(valid, True).mapping[b"\x00\x01"], "😀")
        invalid = raw.replace(b"<0001> <0041>", b"<0001> <D83D>", 1)
        with self.assertRaises(UnicodeDecodeError):
            ExplicitCMap(invalid, True)

    def test_unknown_cmap_operator_is_not_silently_ignored(self):
        raw = self.font["/ToUnicode"].get_data().replace(b"endcmap", b"/Unknown usecmap\nendcmap", 1)
        with self.assertRaisesRegex(AssertionError, "operator"):
            ExplicitCMap(raw, True)

    def replace_first_operand(self, raw):
        page = self.reader.pages[0]
        stream = ContentStream(page.get_contents(), self.reader)
        operations = stream.operations.copy()
        for index, (_, operator) in enumerate(operations):
            if operator == b"Tj":
                operations[index] = ([ByteStringObject(raw)], operator)
                break
        else:
            self.fail("The generated fixture must contain Tj")
        stream.operations = operations
        page[NameObject("/Contents")] = stream


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--pdf", type=Path, default=TextOracleRegressionTest.pdf_path)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    TextOracleRegressionTest.pdf_path = args.pdf
    result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(TextOracleRegressionTest))
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps({
            "status": "pass" if result.wasSuccessful() else "fail", "tests": result.testsRun,
            "pypdf": pypdf.__version__, "pdfSha256": hashlib.sha256(args.pdf.read_bytes()).hexdigest(),
            "errors": len(result.errors), "failures": len(result.failures),
        }, indent=2) + "\n")
    sys.exit(0 if result.wasSuccessful() else 1)
