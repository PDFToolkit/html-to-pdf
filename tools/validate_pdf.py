"""Independent checks of generated native PDFs, with evidence for every page."""
from __future__ import annotations

import argparse
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

from PIL import Image, ImageChops
import PIL
import pypdf
from pypdf import PdfReader
from pypdf.generic import ContentStream, DictionaryObject, IndirectObject
from pdf_text_oracle import PdfTextOracle

ROOT = Path(__file__).resolve().parents[1]


class BodyText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.body = False
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag == "body":
            self.body = True

    def handle_endtag(self, tag):
        if tag == "body":
            self.body = False

    def handle_data(self, data):
        if self.body:
            self.parts.append(data)


def run(*args):
    result = subprocess.run(args, check=True, capture_output=True, text=True, timeout=120)
    return (result.stdout + result.stderr).strip()


def tokens(text):
    return re.findall(r"\w+|[^\w\s]", text, re.UNICODE)


def require_source_text(source, extracted):
    parser = BodyText()
    parser.feed(source)
    expected = tokens(" ".join(parser.parts))
    actual = iter(tokens(extracted))
    for word in expected:
        for candidate in actual:
            if candidate == word:
                break
        else:
            raise AssertionError("Source content missing or out of order at token: " + word)
    return len(expected)


def children(value):
    return value if isinstance(value, list) else [value]


def tree_nodes(root):
    result = []
    seen = set()

    def visit(reference):
        obj = reference.get_object() if hasattr(reference, "get_object") else reference
        if not isinstance(obj, DictionaryObject) or obj.get("/Type") != "/StructElem":
            return
        key = (reference.idnum, reference.generation) if isinstance(reference, IndirectObject) else id(obj)
        assert key not in seen, "Cycle or duplicate semantic child"
        seen.add(key)
        result.append(obj)
        for child in children(obj.get("/K", [])):
            visit(child)

    for reference in children(root["/K"]):
        visit(reference)
    return result


def number_tree(node):
    result = {}
    pairs = node.get("/Nums", [])
    for index in range(0, len(pairs), 2):
        assert int(pairs[index]) not in result
        result[int(pairs[index])] = pairs[index + 1].get_object()
    for child in node.get("/Kids", []):
        values = number_tree(child.get_object())
        assert not set(values).intersection(result)
        result.update(values)
    return result


def check_structure(reader):
    catalog = reader.trailer["/Root"]
    assert catalog["/MarkInfo"]["/Marked"]
    assert catalog["/Lang"] in ("en", "fr")
    assert "/JavaScript" not in catalog.get("/Names", {})
    root = catalog["/StructTreeRoot"]
    nodes = tree_nodes(root)
    assert nodes[0]["/S"] == "/Document"
    parents = number_tree(root["/ParentTree"])
    marked_count = 0
    links = 0
    font_names = set()
    image_count = 0
    for page in reader.pages:
        mcids = set()
        for operands, op in ContentStream(page.get_contents(), reader).operations:
            if op == b"BDC" and isinstance(operands[1], DictionaryObject) and "/MCID" in operands[1]:
                mcid = int(operands[1]["/MCID"])
                assert mcid not in mcids, "Duplicate MCID on page"
                mcids.add(mcid)
        page_parents = parents[int(page["/StructParents"])]
        for mcid in mcids:
            assert page_parents[mcid].get_object()["/Type"] == "/StructElem"
        marked_count += len(mcids)
        fonts = page["/Resources"].get("/Font", {})
        fonts = fonts.get_object() if hasattr(fonts, "get_object") else fonts
        for font in fonts.values():
            font = font.get_object()
            assert font["/Subtype"] == "/Type0"
            assert len(font["/ToUnicode"].get_data()) > 0
            descendant = font["/DescendantFonts"][0].get_object()
            assert len(descendant["/FontDescriptor"]["/FontFile2"].get_data()) > 0
            font_names.add(str(font["/BaseFont"]))
        assets = page["/Resources"].get("/XObject", {})
        assets = assets.get_object() if hasattr(assets, "get_object") else assets
        for asset in assets.values():
            if asset.get_object().get("/Subtype") == "/Image":
                image_count += 1
        for reference in page.get("/Annots", []):
            annotation = reference.get_object()
            assert annotation["/Subtype"] == "/Link"
            assert annotation["/A"]["/S"] == "/URI"
            assert str(annotation["/A"]["/URI"]).startswith("https://")
            owner = parents[int(annotation["/StructParent"])]
            assert owner["/S"] == "/Link"
            assert any(isinstance(k.get_object(), DictionaryObject)
                       and k.get_object().get("/Type") == "/OBJR"
                       and k.get_object()["/Obj"] == annotation
                       for k in children(owner["/K"]) if hasattr(k, "get_object"))
            links += 1
    roles = {}
    for node in nodes:
        role = str(node["/S"])
        roles[role] = roles.get(role, 0) + 1
        if role == "/Figure":
            assert node.get("/Alt"), "Meaningful example image needs alternative text"
        if role == "/TH":
            attrs = node["/A"]
            assert attrs["/O"] == "/Table" and attrs["/Scope"] == "/Column"
    return dict(roles=roles, markedContent=marked_count, fonts=sorted(font_names), links=links, imageResources=image_count)


def validate(name, directory, manifest, args):
    pdf = directory / (name + ".pdf")
    raw = pdf.read_bytes()
    assert hashlib.sha256(raw).hexdigest() == manifest["pdfSha256"]
    assert manifest["diagnostics"] == [], manifest["diagnostics"]
    source = (ROOT / "examples/sources" / (name + ".html")).read_text()
    assert hashlib.sha256(source.encode()).hexdigest() == manifest["sourceSha256"]
    syntax = run(args.qpdf, "--check", str(pdf))
    reader = PdfReader(pdf, strict=True)
    assert len(reader.pages) == manifest["pages"]
    assert len(reader.pages) >= (3 if name == "multipage" else 1)
    text_proof = PdfTextOracle().inspect(reader)
    text = text_proof["text"]
    raw_pypdf_text = "\n".join(page.extract_text() for page in reader.pages)
    (directory / (name + "-pypdf-plain.txt")).write_text(raw_pypdf_text)
    poppler_text = run(args.pdftotext, "-raw", "-enc", "UTF-8", str(pdf), "-")
    (directory / (name + "-poppler.txt")).write_text(poppler_text)
    assert re.sub(r"\s+", "", text) == re.sub(r"\s+", "", poppler_text), "CMap decoding and Poppler disagree"
    (directory / (name + ".txt")).write_text(text)
    source_tokens = require_source_text(source, text)
    assert reader.metadata.title
    for page in reader.pages:
        assert abs(float(page.mediabox.width) - 595.27559) < .01
        assert abs(float(page.mediabox.height) - 841.88976) < .01
    structure = check_structure(reader)
    if name == "invoice":
        assert "480,00" in text and "€" in text and "céramique" in text
        assert structure["links"] >= 1
        assert structure["roles"]["/TH"] == 3
    if name == "catalogue":
        assert structure["roles"]["/Figure"] == 3 and structure["imageResources"] >= 3
    if name == "multipage":
        assert structure["roles"]["/TR"] == 31
        assert structure["roles"]["/TH"] == 3
        for row in range(1, 31):
            code = f"R{row:02d}"
            assert len(re.findall(r"\b" + code + r"\b", text)) == len(re.findall(r"\b" + code + r"\b", source))
        assert "Closing note" in text_proof["pages"][-1]["text"]
        assert "Closing note" not in "\n".join(page["text"] for page in text_proof["pages"][:-1])
    bbox = directory / (name + "-bbox.xhtml")
    run(args.pdftotext, "-bbox-layout", str(pdf), str(bbox))
    words = 0
    for page in ET.parse(bbox).getroot().iter("{http://www.w3.org/1999/xhtml}page"):
        width, height = float(page.attrib["width"]), float(page.attrib["height"])
        for word in page.iter("{http://www.w3.org/1999/xhtml}word"):
            x0, x1, y0, y1 = (float(word.attrib[k]) for k in ("xMin", "xMax", "yMin", "yMax"))
            assert -0.5 <= x0 < x1 <= width + .5, (name, word.text, x0, x1)
            assert -0.5 <= y0 < y1 <= height + .5, (name, word.text, y0, y1)
            words += 1
    # ActualText may cause Poppler to expose a whole run as one text box.
    assert words > 0
    render_dir = directory / "renders"
    render_dir.mkdir(exist_ok=True)
    prefix = render_dir / name
    run(args.pdftoppm, "-png", "-r", "96", str(pdf), str(prefix))
    images = sorted(render_dir.glob(name + "-*.png"))
    assert len(images) == len(reader.pages)
    raster = []
    for path in images:
        image = Image.open(path).convert("RGB")
        diff = ImageChops.difference(image, Image.new("RGB", image.size, "white"))
        bounds = diff.getbbox()
        assert bounds is not None, "Unexpected blank page"
        assert bounds[0] >= 20 and bounds[1] >= 20
        assert bounds[2] <= image.width - 20 and bounds[3] <= image.height - 20
        raster.append(dict(file=str(path.relative_to(directory)), size=image.size, inkBounds=bounds,
                           sha256=hashlib.sha256(path.read_bytes()).hexdigest()))
    return dict(pages=len(reader.pages), bytes=len(raw), sha256=manifest["pdfSha256"], textOracle=text_proof,
                pypdfPlainExtraction={"matchesVerifiedText": raw_pypdf_text == text, "nulCount": raw_pypdf_text.count("\0"),
                    "note": "pypdf 6.20.1 plain extraction uses a one-byte fallback for the embedded Encoding stream; raw output is retained, never repaired. Verified text uses explicit CMap decoding and Poppler."},
                qpdf=syntax, sourceTokens=source_tokens, popplerTextBoxes=words, **structure, renders=raster)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--directory", type=Path, required=True)
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--pdftotext", default="pdftotext")
    parser.add_argument("--pdftoppm", default="pdftoppm")
    args = parser.parse_args()
    manifest = json.loads((args.directory / "manifest.json").read_text())
    report = dict(status="passed", tools={"pypdf": pypdf.__version__, "pillow": PIL.__version__,
                  "qpdf": run(args.qpdf, "--version"), "poppler": run(args.pdftoppm, "-v")}, documents={})
    for name, record in manifest["documents"].items():
        report["documents"][name] = validate(name, args.directory, record, args)
    (args.directory / "validation.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({"status": "passed", "documents": len(report["documents"]),
                      "pages": sum(d["pages"] for d in report["documents"].values())}))


if __name__ == "__main__":
    main()
