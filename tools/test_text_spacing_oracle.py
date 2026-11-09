"""Deliberately wrong spacing and indentation must fail independent geometry checks."""
from __future__ import annotations

import copy
import tempfile
from pathlib import Path
import unittest

from validate_text_spacing import DELTAS, TextSpacingPdfOracle, geometry_checks


def positive_cases():
    cases = {}
    for name in ("word-normal", "nbsp-normal", "letter-normal"):
        cases[name] = {"letters": [{"x": 40 + index * 10} for index in range(4)]}
    for name, control, addition in DELTAS:
        cases[name] = {"letters": [{"x": 40 + index * (10 + .75 * addition)} for index in range(4)]}
    for name, first in [("indent-positive", 18), ("indent-negative", -9), ("indent-percent", 12)]:
        cases[name] = {"lines": [{"x": 54 + first}, {"x": 54}, {"x": 54}]}
    cases["page-continuation"] = {"lines": [{"x": 54, "page": 2}, {"x": 36, "page": 2}, {"x": 36, "page": 3}]}
    for name, gap in [("combining-normal", 0), ("combining-positive", 1.5)]:
        cases[name] = {"glyphs": [{"text": "M"}, {"text": "0"}, {"text": "a\u0301", "x": 40}, {"text": "b", "x": 48 + gap}]}
    cases["ligature-positive"] = {"glyphs": [{"text": "F"}, {"text": "1"}] + [{"text": letter} for letter in "office"]}
    return cases


class GeometryOracleTest(unittest.TestCase):
    def setUp(self):
        self.cases = positive_cases()

    def passes(self):
        return all(row["passed"] for row in geometry_checks(self.cases))

    def test_positive_control(self):
        self.assertTrue(self.passes())

    def test_missing_word_letter_or_nbsp_spacing_fails(self):
        for name, control, _ in DELTAS:
            with self.subTest(name=name):
                self.cases = positive_cases()
                self.cases[name] = copy.deepcopy(self.cases[control])
                self.assertFalse(self.passes())

    def test_wrong_units_fails(self):
        self.cases["word-positive"]["letters"][3]["x"] += 4.5
        self.assertFalse(self.passes())

    def test_absent_indent_and_indent_repeated_on_continuation_fail(self):
        for name, line in [("indent-positive", 0), ("indent-negative", 0), ("indent-percent", 0), ("indent-positive", 1), ("page-continuation", 2)]:
            with self.subTest(name=name, line=line):
                self.cases = positive_cases()
                self.cases[name]["lines"][line]["x"] += 12
                self.assertFalse(self.passes())

    def test_fixture_that_does_not_paginate_is_rejected(self):
        for line in self.cases["page-continuation"]["lines"]:
            line["page"] = 2
        with self.assertRaisesRegex(AssertionError, "actually continue"):
            geometry_checks(self.cases)

    def test_combining_mark_does_not_receive_a_second_gap(self):
        self.cases["combining-positive"]["glyphs"][-1]["x"] += 1.5
        self.assertFalse(self.passes())

    def test_optional_ligature_in_letter_spaced_text_is_rejected(self):
        self.cases["ligature-positive"]["glyphs"] = [{"text": "F"}, {"text": "1"}, {"text": "o"}, {"text": "ffi"}, {"text": "c"}, {"text": "e"}]
        self.assertFalse(self.passes())

    def test_failed_validation_preserves_report(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            report = TextSpacingPdfOracle(target / "missing.pdf", target / "missing.json", target, target / "browser.json").inspect()
            self.assertEqual("fail", report["status"])
            self.assertTrue((target / "pdf-report.json").is_file())


if __name__ == "__main__":
    unittest.main()
