"""Strict independent text oracle for this generator's embedded Type0 font profile.

pypdf 6.20.1's extract_text path falls back to one-byte StandardEncoding for an
Encoding *stream*. This oracle uses pypdf only for PDF objects/content operands,
then validates the explicit two-byte CMaps and decodes original Tj bytes. It is
not a general PDF text extractor and rejects profiles it does not implement.
"""
from __future__ import annotations

import hashlib
import re
import struct

from pypdf.generic import ByteStringObject, ContentStream, DictionaryObject, TextStringObject


class ExplicitCMap:
    def __init__(self, data: bytes, unicode: bool):
        assert len(data) <= 8_388_608, "Bounded CMap stream required"
        source = data.decode("ascii", errors="strict")
        ordering = "UCS" if unicode else "Identity"
        kind = "bfchar" if unicode else "cidchar"
        prefix = (
            r"\s*/CIDInit\s+/ProcSet\s+findresource\s+begin\s+12\s+dict\s+begin\s+begincmap\s+"
            r"/CIDSystemInfo\s*<<\s*/Registry\s*\(Adobe\)\s*/Ordering\s*\(" + ordering + r"\)\s*/Supplement\s+0\s*>>\s+def\s+"
            r"/CMapName\s+/(?P<name>[A-Za-z0-9_.-]+)\s+def\s+/CMapType\s+" + ("2" if unicode else "1") + r"\s+def\s+"
            r"/WMode\s+0\s+def\s+1\s+begincodespacerange\s+<0000>\s+<FFFF>\s+endcodespacerange\s+"
        )
        suffix = r"\s*endcmap\s+CMapName\s+currentdict\s+/CMap\s+defineresource\s+pop\s+end\s+end\s*"
        wrapper = re.fullmatch(prefix + r"(?P<body>.*?)" + suffix, source, re.DOTALL)
        assert wrapper is not None, "Unexpected CMap profile: require explicit horizontal two-byte codes"
        self.name = "/" + wrapper["name"]
        body = wrapper["body"]
        self.mapping = {}
        while body.strip():
            block = re.match(r"\s*(\d+)\s+begin" + kind + r"\s+(.*?)\s+end" + kind + r"(?:\s+|$)", body, re.DOTALL)
            assert block is not None, "Unsupported CMap operator or malformed mapping block"
            count = int(block[1])
            assert 1 <= count <= 100, "CMap mapping blocks contain 1 to 100 entries"
            entries = block[2].splitlines()
            assert len(entries) == count, "CMap declared count must equal actual entries"
            for entry in entries:
                pair = re.fullmatch(r"\s*<([0-9A-Fa-f]{4})>\s+" + (r"<([0-9A-Fa-f]*)>" if unicode else r"(\d+)") + r"\s*", entry)
                assert pair is not None, "Malformed or unsupported CMap mapping entry"
                code = bytes.fromhex(pair[1])
                assert code not in self.mapping, "A character code cannot have duplicate mappings"
                if unicode:
                    assert len(pair[2]) % 4 == 0, "ToUnicode destinations contain complete UTF-16 code units"
                    value = bytes.fromhex(pair[2]).decode("utf-16-be", errors="strict")
                else:
                    value = int(pair[2])
                    assert 0 <= value <= 65535, "CID outside the supported TrueType range"
                self.mapping[code] = value
            assert len(self.mapping) <= 65535, "Bounded character repertoire required"
            body = body[block.end():]
        assert self.mapping, "The emitted font must declare character mappings"
        self.sha256 = hashlib.sha256(data).hexdigest()


class FontTextMap:
    def __init__(self, font):
        assert font["/Subtype"] == "/Type0"
        assert len(font["/DescendantFonts"]) == 1
        descendant = font["/DescendantFonts"][0].get_object()
        assert descendant["/Subtype"] == "/CIDFontType2"
        assert descendant["/CIDToGIDMap"] == "/Identity", "Only explicit identity CID-to-glyph maps are accepted"
        system = descendant["/CIDSystemInfo"]
        assert str(system["/Registry"]) == "Adobe" and str(system["/Ordering"]) == "Identity" and int(system["/Supplement"]) == 0
        encoding = font["/Encoding"].get_object()
        unicode = font["/ToUnicode"].get_object()
        assert hasattr(encoding, "get_data") and hasattr(unicode, "get_data")
        assert "/UseCMap" not in encoding and "/UseCMap" not in unicode, "Inherited CMaps require another oracle profile"
        self.encoding = ExplicitCMap(encoding.get_data(), False)
        self.unicode = ExplicitCMap(unicode.get_data(), True)
        for stream, parsed, ordering in [(encoding, self.encoding, "Identity"), (unicode, self.unicode, "UCS")]:
            assert stream["/Type"] == "/CMap" and stream["/CMapName"] == parsed.name, "CMap dictionary and program names must agree"
            info = stream["/CIDSystemInfo"]
            assert str(info["/Registry"]) == "Adobe" and str(info["/Ordering"]) == ordering and int(info["/Supplement"]) == 0, "CMap dictionary and program character collections must agree"
            assert int(stream.get("/WMode", 0)) == 0, "CMap dictionary and program writing modes must agree"
        assert self.encoding.mapping.keys() == self.unicode.mapping.keys(), "Encoding and ToUnicode must cover the same character codes"
        program = descendant["/FontDescriptor"]["/FontFile2"].get_data()
        glyphs = self.glyph_count(program)
        assert all(cid < glyphs for cid in self.encoding.mapping.values()), "CIDToGIDMap Identity points outside embedded maxp.numGlyphs"
        self.report = {
            "baseFont": str(font["/BaseFont"]), "codes": len(self.encoding.mapping), "glyphs": glyphs,
            "encodingSha256": self.encoding.sha256, "toUnicodeSha256": self.unicode.sha256,
            "fontProgramSha256": hashlib.sha256(program).hexdigest(), "characterCodeBytes": 2,
        }

    @staticmethod
    def glyph_count(program):
        assert len(program) <= 16_777_216 and len(program) >= 12
        assert program[:4] in (b"\x00\x01\x00\x00", b"true"), "Expected a static TrueType sfnt"
        count = struct.unpack_from(">H", program, 4)[0]
        assert 1 <= count <= 256 and 12 + count * 16 <= len(program)
        tables = {}
        for offset in range(12, 12 + count * 16, 16):
            tag, _, start, size = struct.unpack_from(">4sIII", program, offset)
            assert tag not in tables and start + size <= len(program)
            tables[tag] = (start, size)
        start, size = tables[b"maxp"]
        assert size >= 6
        glyphs = struct.unpack_from(">H", program, start + 4)[0]
        assert glyphs > 0
        return glyphs

    def decode(self, raw):
        assert len(raw) % 2 == 0, "Text operand splits a two-byte character code"
        text = []
        for index in range(0, len(raw), 2):
            code = raw[index:index + 2]
            assert code in self.encoding.mapping and code in self.unicode.mapping, "Text uses an unmapped character code"
            text.append(self.unicode.mapping[code])
        return "".join(text)


class PdfTextOracle:
    @staticmethod
    def original_bytes(value):
        assert isinstance(value, (ByteStringObject, TextStringObject)), "Expected an actual PDF string operand"
        return value.original_bytes if isinstance(value, TextStringObject) else bytes(value)

    def inspect(self, reader):
        pages = []
        for page in reader.pages:
            maps = {str(name): FontTextMap(font.get_object()) for name, font in page["/Resources"]["/Font"].get_object().items()}
            stack = []
            active_font = None
            graphics_fonts = []
            in_text = False
            runs = []
            glyphs = 0
            operators = 0
            for operands, operator in ContentStream(page.get_contents(), reader).operations:
                operators += 1
                assert operators <= 5_000_000, "Bounded content operation count required"
                if operator in (b"BDC", b"BMC"):
                    actual = None
                    if operator == b"BDC":
                        assert isinstance(operands[1], DictionaryObject), "Resolve named property lists with a different oracle profile"
                        if "/ActualText" in operands[1]:
                            raw = self.original_bytes(operands[1]["/ActualText"])
                            assert raw.startswith(b"\xfe\xff"), "Generated ActualText must explicitly declare UTF-16BE"
                            actual = raw[2:].decode("utf-16-be", errors="strict")
                            assert not any(item["actual"] is not None for item in stack), "No nested ActualText scopes in this profile"
                    stack.append({"actual": actual, "decoded": []})
                elif operator == b"EMC":
                    assert stack, "Unbalanced marked content"
                    scope = stack.pop()
                    if scope["actual"] is not None:
                        decoded = "".join(scope["decoded"])
                        assert decoded == scope["actual"], "Decoded glyph sequence differs from its independently stored ActualText"
                        runs.append(decoded)
                elif operator == b"q":
                    graphics_fonts.append(active_font)
                elif operator == b"Q":
                    assert graphics_fonts
                    active_font = graphics_fonts.pop()
                elif operator == b"BT":
                    assert not in_text
                    in_text = True
                elif operator == b"ET":
                    assert in_text
                    in_text = False
                elif operator == b"Tf":
                    assert in_text and str(operands[0]) in maps
                    active_font = maps[str(operands[0])]
                elif operator in (b"Tj", b"TJ", b"'", b'"'):
                    assert operator == b"Tj", "This generator profile emits positioned Tj operands only"
                    assert in_text and active_font is not None
                    scopes = [item for item in stack if item["actual"] is not None]
                    assert len(scopes) == 1, "Every generated text run must have a verified ActualText scope"
                    raw = self.original_bytes(operands[0])
                    scopes[0]["decoded"].append(active_font.decode(raw))
                    glyphs += len(raw) // 2
                elif operator == b"gs":
                    raise AssertionError("External graphics-state font changes require another oracle profile")
                elif operator == b"Do":
                    asset = page["/Resources"]["/XObject"][operands[0]].get_object()
                    assert asset["/Subtype"] == "/Image", "Form text needs a recursive oracle and cannot be silently omitted"
            assert not stack and not graphics_fonts and not in_text, "Unbalanced graphics, text or marked-content scopes"
            assert runs and glyphs > 0, "The example page must contain verified text"
            pages.append({"text": "\n".join(runs), "runs": len(runs), "glyphs": glyphs,
                          "fonts": {name: font.report for name, font in maps.items()}})
        return {"method": "original Tj bytes → explicit two-byte Encoding/ToUnicode CMaps; per-run ActualText equality",
                "pages": pages, "text": "\n".join(page["text"] for page in pages)}
