"""Validate honest CSS coverage metadata; never equate parser witnesses with conformance."""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[1]
STAGES = ("css1", "css2", "css21", "modules")
STATUSES = {"unsupported", "partial", "implemented", "validated"}
CSS1_PROPERTIES = frozenset("""
font-family font-style font-variant font-weight font-size font color
background-color background-image background-repeat background-attachment
background-position background word-spacing letter-spacing text-decoration
vertical-align text-transform text-align text-indent line-height margin-top
margin-right margin-bottom margin-left margin padding-top padding-right
padding-bottom padding-left padding border-top-width border-right-width
border-bottom-width border-left-width border-width border-color border-style
border-top border-right border-bottom border-left border width height float
clear display white-space list-style-type list-style-image list-style-position
list-style
""".split())
CSS1_BEHAVIORS = {
    "selectors", "cascade", "inheritance", "formatting", "font-matching",
    "units", "syntax-recovery", "imports", "canvas",
}


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate JSON object key: " + key)
        result[key] = value
    return result


class CoverageChecker:
    """One registry, local test paths and qualification receipts per invocation."""

    def __init__(self, root: Path):
        self.root = root.resolve()
        self.errors = []
        self.files = {}

    def require(self, condition, message):
        if not condition:
            self.errors.append(message)
        return bool(condition)

    def local_file(self, value, label):
        if not self.require(isinstance(value, str) and bool(value), label + " must be a relative file path"):
            return None
        path = (self.root / value).resolve()
        if not self.require(not Path(value).is_absolute() and path.is_relative_to(self.root), label + " escapes the repository"):
            return None
        if not self.require(path.is_file(), label + " does not identify an existing file: " + value):
            return None
        self.files[value] = hashlib.sha256(path.read_bytes()).hexdigest()
        return path

    def qualification(self, stage):
        proof = stage.get("qualification")
        if not self.require(isinstance(proof, dict), stage["id"] + " completion requires a full-stage qualification receipt"):
            return False
        path = self.local_file(proof.get("report"), stage["id"] + " qualification report")
        if path is None:
            return False
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        valid_hash = self.require(proof.get("sha256") == digest, stage["id"] + " qualification hash mismatch")
        try:
            receipt = json.loads(path.read_text(), object_pairs_hook=unique_object)
        except (ValueError, OSError) as error:
            self.errors.append(stage["id"] + " qualification cannot be read: " + str(error))
            return False
        valid = isinstance(receipt, dict) and receipt.get("status") == "pass" and receipt.get("complete") is True
        valid = valid and receipt.get("stage") == stage["id"] and receipt.get("scope") == "full-css-stage"
        valid = valid and receipt.get("valueContextInventoryComplete") is True and receipt.get("witnessOnly") is False
        self.require(valid, stage["id"] + " needs complete value/context qualification, not a parser witness")
        return valid_hash and valid

    def check(self, registry, required_stage=None):
        if not isinstance(registry, dict):
            self.errors.append("The registry must be an object")
            registry = {}
        self.require(registry.get("schemaVersion") == 1, "Unsupported registry schema version")
        self.require(registry.get("stageOrder") == list(STAGES), "The stage order must be css1, css2, css21, modules")
        self.require(registry.get("activeStage") in STAGES, "Unknown active stage")
        stages = registry.get("stages", [])
        requirements = registry.get("requirements", [])
        if not isinstance(stages, list):
            self.errors.append("stages must be an array")
            stages = []
        if not isinstance(requirements, list):
            self.errors.append("requirements must be an array")
            requirements = []
        stage_map = {}
        for stage in stages:
            if not isinstance(stage, dict):
                self.errors.append("Each stage must be an object")
                continue
            identity = stage.get("id")
            if not self.require(isinstance(identity, str) and identity in STAGES, "Unknown stage identity"):
                continue
            self.require(identity not in stage_map, "Duplicate stage: " + identity)
            self.require(type(stage.get("claimedComplete")) is bool, identity + " claimedComplete must be boolean")
            self.require(type(stage.get("inventoryComplete")) is bool, identity + " inventoryComplete must be boolean")
            stage_map[identity] = stage
        self.require(set(stage_map) == set(STAGES), "Every stage must be declared exactly once")
        seen = set()
        grouped = {identity: [] for identity in STAGES}
        property_names = []
        behavior_names = []
        for requirement in requirements:
            if not isinstance(requirement, dict):
                self.errors.append("Each requirement must be an object")
                continue
            identity = requirement.get("id")
            if not self.require(isinstance(identity, str) and re.fullmatch(r"(?:css1|css2|css21|modules)\.(?:property|behavior)\.[a-z0-9-]+", identity), "Invalid requirement identity"):
                continue
            self.require(identity not in seen, "Duplicate requirement: " + identity)
            seen.add(identity)
            stage, kind, name = identity.split(".", 2)
            self.require(requirement.get("kind") == kind and requirement.get("name") == name, identity + " identity/kind/name mismatch")
            self.require(isinstance(requirement.get("status"), str) and requirement["status"] in STATUSES, identity + " has an unknown status")
            self.require(isinstance(requirement.get("note"), str) and bool(requirement.get("note")), identity + " needs an honest scope note")
            self.require(isinstance(requirement.get("reference"), str) and requirement["reference"].startswith("https://www.w3.org/"), identity + " needs an official W3C reference")
            paths = requirement.get("tests")
            if self.require(isinstance(paths, list) and bool(paths), identity + " needs existing test paths"):
                for path in paths:
                    self.local_file(path, identity + " test")
            if kind == "property":
                self.require(isinstance(requirement.get("sample"), str) and bool(requirement.get("sample")), identity + " needs a witness value")
                self.require(isinstance(requirement.get("context"), str) and requirement["context"] in {"div", "td"}, identity + " needs a supported witness context")
            grouped[stage].append(requirement)
            if stage == "css1":
                (property_names if kind == "property" else behavior_names).append(name)
        self.require(len(property_names) == 53 and set(property_names) == CSS1_PROPERTIES, "CSS1 must inventory exactly its 53 distinct properties")
        self.require(CSS1_BEHAVIORS.issubset(behavior_names), "CSS1 behavior inventory must retain its nine initial behavior groups")
        stage_reports = []
        preceding_complete = True
        for identity in STAGES:
            stage = stage_map.get(identity, {"id": identity})
            entries = grouped[identity]
            counts = dict(sorted(Counter(entry.get("status") if isinstance(entry.get("status"), str) else "invalid" for entry in entries).items()))
            incomplete = [entry["id"] for entry in entries if entry.get("status") != "validated"]
            claimed = stage.get("claimedComplete") is True
            ready = stage.get("inventoryComplete") is True and bool(entries) and not incomplete and preceding_complete
            qualified = False
            if claimed:
                self.require(ready, identity + " completion claim has unqualified requirements, an incomplete inventory or incomplete preceding stages")
                qualified = self.qualification(stage)
            complete = claimed and ready and qualified
            if registry.get("activeStage") == identity:
                self.require(preceding_complete, "An active stage cannot skip incomplete preceding stages")
            stage_reports.append({
                "id": identity, "inventoryComplete": stage.get("inventoryComplete") is True,
                "claimedComplete": claimed, "complete": complete, "requirements": len(entries),
                "statuses": counts, "unqualified": incomplete,
            })
            preceding_complete = preceding_complete and complete
        requested = next((stage for stage in stage_reports if stage["id"] == required_stage), None)
        gate_passed = required_stage is None or (requested is not None and requested["complete"])
        return {
            "schemaVersion": 1, "status": "fail" if self.errors else ("pass" if gate_passed else "incomplete"),
            "scope": "Registry consistency and referenced evidence; this command does not run parser, layout or rendering tests.",
            "witnessesAreFullQualification": False,
            "complete": all(stage["complete"] for stage in stage_reports),
            "incomplete": any(not stage["complete"] for stage in stage_reports),
            "activeStage": registry.get("activeStage"), "requiredStage": required_stage,
            "stageGatePassed": gate_passed and not self.errors,
            "css1PropertyCount": len(property_names), "requirementCount": len(seen),
            "stages": stage_reports, "referencedFiles": self.files, "errors": self.errors,
        }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--registry", type=Path, default=ROOT / "resources/css-coverage.json")
    parser.add_argument("--report", type=Path)
    parser.add_argument("--require-stage", choices=STAGES)
    args = parser.parse_args()
    checker = CoverageChecker(ROOT)
    try:
        raw = args.registry.read_bytes()
        if len(raw) > 1_048_576:
            raise ValueError("CSS registry exceeds one MiB")
        registry = json.loads(raw, object_pairs_hook=unique_object)
    except (OSError, ValueError) as error:
        raw = b""
        registry = {}
        checker.errors.append("Cannot read registry: " + str(error))
    report = checker.check(registry, args.require_stage)
    report["registry"] = str(args.registry.resolve())
    report["registrySha256"] = hashlib.sha256(raw).hexdigest()
    rendered = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(rendered)
    print(rendered, end="")
    return 0 if report["status"] == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
