"""Negative controls for the independent historical CSS1 PDF oracle."""
from __future__ import annotations

import copy
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET

from PIL import Image, ImageDraw

from validate_w3c_css1 import HistoricalPdfOracle, HistoricalRasterOracle, NAMESPACE, SOURCE_HASH, digest


class RasterOracleTest(unittest.TestCase):
    def setUp(self):
        self.oracle = HistoricalRasterOracle()
        self.image, _ = self.oracle.model()

    def test_positive_control_and_explicit_mask_coverage(self):
        result = self.oracle.inspect(self.image)
        self.assertTrue(result["passed"])
        self.assertEqual({"black", "white", "blue", "red", "yellow"}, set(result["colors"]))
        self.assertTrue(all(row["pixels"] >= 8_000 for row in result["colors"].values()))

    def test_missing_root_canvas_background_fails(self):
        ImageDraw.Draw(self.image).rectangle((510, 0, 790, 1120), fill="white")
        self.assertFalse(self.oracle.inspect(self.image)["passed"])

    def test_wrong_red_or_yellow_background_fails(self):
        for bounds in [(33, 80, 97, 320), (143, 90, 207, 157)]:
            with self.subTest(bounds=bounds):
                image = self.image.copy()
                ImageDraw.Draw(image).rectangle(bounds, fill="white")
                self.assertFalse(self.oracle.inspect(image)["passed"])

    def test_nested_float_on_next_row_fails(self):
        ImageDraw.Draw(self.image).rectangle((228, 143, 382, 152), fill="white")
        self.assertFalse(self.oracle.inspect(self.image)["passed"])

    def test_asymmetric_border_bottom_missing_fails(self):
        ImageDraw.Draw(self.image).rectangle((278, 298, 342, 312), fill="white")
        self.assertFalse(self.oracle.inspect(self.image)["passed"])

    def test_five_pixel_horizontal_shift_fails(self):
        moved = Image.new("RGB", self.image.size, "blue")
        moved.paste(self.image, (5, 0))
        self.assertFalse(self.oracle.inspect(moved)["passed"])

    def test_millimetres_confused_with_points_fails_size(self):
        with self.assertRaisesRegex(AssertionError, "96 dpi"):
            self.oracle.inspect(self.image.resize((1058, 1497)))

    def test_font_and_widget_rasterization_is_excluded(self):
        for x, y, width, height in self.oracle.EXCLUSIONS[:-1]:
            ImageDraw.Draw(self.image).rectangle(self.oracle.box(x, y, width, height), fill=(127, 127, 127))
        self.assertTrue(self.oracle.inspect(self.image)["passed"])


class ReceiptOracleTest(unittest.TestCase):
    def setUp(self):
        self.pdf = b"bounded test PDF bytes"
        self.record = {"engine": "native-php", "sourceSha256": SOURCE_HASH, "pdfSha256": digest(self.pdf),
                       "pages": 1, "diagnostics": [], "fonts": [{"family": "Explicit fallback", "sha256": "1" * 64}]}

    def test_correct_identity_control(self):
        self.assertEqual(self.record, HistoricalPdfOracle.receipt(self.record, self.pdf))

    def test_changed_pdf_or_source_is_rejected(self):
        for key in ("sourceSha256", "pdfSha256"):
            with self.subTest(key=key):
                record = copy.deepcopy(self.record)
                record[key] = "0" * 64
                with self.assertRaises(AssertionError):
                    HistoricalPdfOracle.receipt(record, self.pdf)

    def test_browser_output_diagnostics_extra_pages_missing_font_provenance_rejected(self):
        for key, value in [("engine", "chromium"), ("diagnostics", [{"code": "css.unsupported"}]), ("pages", 2), ("fonts", [])]:
            with self.subTest(key=key):
                record = copy.deepcopy(self.record)
                record[key] = value
                with self.assertRaises(AssertionError):
                    HistoricalPdfOracle.receipt(record, self.pdf)

    def test_failed_cli_validation_preserves_failure_report(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            result = HistoricalPdfOracle(target / "missing.pdf", target / "missing.json", target).inspect()
            self.assertEqual("fail", result["status"])
            self.assertTrue((target / "pdf-report.json").exists())


class TextBoundsOracleTest(unittest.TestCase):
    def tree(self, footer_top=252, toggle_x=30):
        root = ET.Element("root")
        for text, x, y, width, height in [("toggle", toggle_x, 28, 20, 8), ("pluot?", 109, 140, 24, 8), ("This", 15, footer_top, 15, 8)]:
            node = ET.SubElement(root, NAMESPACE + "word", xMin=str(x), xMax=str(x + width), yMin=str(y), yMax=str(y + height))
            node.text = text
        return root

    def test_positive_position_control(self):
        self.assertEqual(3, HistoricalPdfOracle.text_boxes(self.tree(), 595.27559, 841.88976)["words"])

    def test_clearance_omission_and_unnecessary_page_downshift_fail(self):
        for y in (30, 300):
            with self.subTest(y=y), self.assertRaisesRegex(AssertionError, "Cleared paragraph"):
                HistoricalPdfOracle.text_boxes(self.tree(footer_top=y), 595.27559, 841.88976)

    def test_text_outside_own_float_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, "Label leaves"):
            HistoricalPdfOracle.text_boxes(self.tree(toggle_x=100), 595.27559, 841.88976)

    def test_clipped_text_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, "Clipped"):
            HistoricalPdfOracle.text_boxes(self.tree(toggle_x=-100), 595.27559, 841.88976)


if __name__ == "__main__":
    unittest.main()
