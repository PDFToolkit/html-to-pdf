"""Negative tests for coverage claims and ordered qualification gates."""
from __future__ import annotations

import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from check_css_coverage import CoverageChecker, ROOT, unique_object


class CoverageCheckerTest(unittest.TestCase):
    def setUp(self):
        self.registry = json.loads((ROOT / "resources/css-coverage.json").read_text())

    def check(self, registry=None, stage=None):
        return CoverageChecker(ROOT).check(self.registry if registry is None else registry, stage)

    def test_honest_incomplete_inventory_passes_consistency_without_claiming_conformance(self):
        report = self.check()
        self.assertEqual("pass", report["status"])
        self.assertTrue(report["incomplete"])
        self.assertFalse(report["complete"])
        self.assertFalse(report["witnessesAreFullQualification"])
        self.assertEqual(53, report["css1PropertyCount"])
        self.assertEqual(62, report["requirementCount"])

    def test_required_css1_fails_while_the_inventory_is_partial(self):
        report = self.check(stage="css1")
        self.assertEqual("incomplete", report["status"])
        self.assertFalse(report["stageGatePassed"])
        self.assertEqual([], report["errors"])

    def test_duplicate_requirement_is_rejected(self):
        self.registry["requirements"].append(copy.deepcopy(self.registry["requirements"][0]))
        self.assertEqual("fail", self.check()["status"])

    def test_wrong_property_with_same_total_is_rejected(self):
        self.registry["requirements"][0].update(id="css1.property.fake", name="fake")
        self.assertEqual("fail", self.check()["status"])

    def test_unknown_status_is_not_accepted_as_completion(self):
        self.registry["requirements"][0]["status"] = "probably-supported"
        self.assertEqual("fail", self.check()["status"])

    def test_malformed_status_and_context_report_errors(self):
        self.registry["requirements"][0].update(status=[], context={})
        self.assertEqual("fail", self.check()["status"])

    def test_missing_test_file_is_rejected(self):
        self.registry["requirements"][0]["tests"] = ["tests/DefinitelyMissing.php"]
        self.assertEqual("fail", self.check()["status"])

    def test_test_paths_cannot_escape_repository(self):
        self.registry["requirements"][0]["tests"] = ["../html-to-pdf/AGENTS.md"]
        # Canonical repository-contained paths remain valid; traversal outside does not.
        self.registry["requirements"][1]["tests"] = ["../../../../etc/passwd"]
        self.assertEqual("fail", self.check()["status"])

    def test_completion_claim_cannot_override_partial_requirements(self):
        self.registry["stages"][0]["claimedComplete"] = True
        self.assertEqual("fail", self.check()["status"])

    def test_validated_labels_alone_are_not_qualification(self):
        for requirement in self.registry["requirements"]:
            requirement["status"] = "validated"
        self.registry["stages"][0]["claimedComplete"] = True
        report = self.check()
        self.assertEqual("fail", report["status"])
        self.assertFalse(report["stages"][0]["complete"])

    def test_parser_receipt_cannot_qualify_a_full_stage(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for requirement in self.registry["requirements"]:
                requirement["status"] = "validated"
                for relative in requirement["tests"]:
                    path = root / relative
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_text("fixture test path\n")
            receipt = root / "witness.json"
            receipt.write_text(json.dumps({"status": "pass", "complete": True, "stage": "css1", "scope": "parser-witness", "witnessOnly": True}))
            self.registry["stages"][0].update(claimedComplete=True, qualification={"report": "witness.json", "sha256": hashlib.sha256(receipt.read_bytes()).hexdigest()})
            report = CoverageChecker(root).check(self.registry)
            self.assertEqual("fail", report["status"])
            self.assertFalse(report["stages"][0]["complete"])

    def test_inventory_flag_cannot_be_skipped(self):
        self.registry["stages"][0].update(claimedComplete=True, inventoryComplete=False)
        self.assertEqual("fail", self.check()["status"])

    def test_later_stage_cannot_be_activated_before_css1_is_complete(self):
        self.registry["activeStage"] = "css2"
        self.assertEqual("fail", self.check()["status"])

    def test_missing_behavior_group_is_rejected(self):
        self.registry["requirements"] = [entry for entry in self.registry["requirements"] if entry["id"] != "css1.behavior.canvas"]
        self.assertEqual("fail", self.check()["status"])

    def test_duplicate_json_object_keys_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "Duplicate JSON"):
            json.loads('{"claimedComplete":false,"claimedComplete":true}', object_pairs_hook=unique_object)

    def test_command_line_gate_returns_one_and_keeps_an_explicit_report(self):
        with tempfile.TemporaryDirectory() as directory:
            report_path = Path(directory) / "gate.json"
            result = subprocess.run([sys.executable, str(ROOT / "tools/check_css_coverage.py"), "--require-stage", "css1", "--report", str(report_path)], capture_output=True, text=True, timeout=20)
            self.assertEqual(1, result.returncode, result.stderr)
            self.assertEqual("incomplete", json.loads(report_path.read_text())["status"])


if __name__ == "__main__":
    unittest.main()
