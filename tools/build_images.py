"""Reproducible original illustration fixtures; no third-party artwork."""
from pathlib import Path
from io import BytesIO
import argparse
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]


def fixtures():
    for name, background, accent in [
        ("notebook", "#e7eff4", "#176a80"),
        ("lamp", "#fff0db", "#d47b30"),
        ("mug", "#e6eee8", "#42725c"),
    ]:
        image = Image.new("RGB", (600, 360), background)
        draw = ImageDraw.Draw(image)
        draw.ellipse((160, 285, 440, 315), fill="#c9c9c9")
        if name == "notebook":
            draw.rounded_rectangle((190, 55, 410, 290), 12, fill=accent)
            draw.rectangle((215, 55, 225, 290), fill="#f5dc9b")
            draw.rectangle((250, 100, 365, 105), fill="white")
            draw.rectangle((250, 120, 340, 125), fill="white")
        elif name == "lamp":
            draw.rectangle((292, 150, 308, 285), fill=accent)
            draw.ellipse((230, 275, 370, 300), fill=accent)
            draw.polygon([(240, 60), (360, 60), (410, 170), (190, 170)], fill=accent)
        else:
            draw.ellipse((330, 90, 440, 245), fill=accent)
            draw.ellipse((360, 115, 415, 220), fill=background)
            draw.rounded_rectangle((180, 80, 365, 290), 35, fill=accent)
            draw.ellipse((185, 65, 360, 105), fill="#fef9ed")
        out = BytesIO()
        image.save(out, format="PNG", compress_level=9)
        yield name + ".png", out.getvalue()


def main():
    args = argparse.ArgumentParser()
    args.add_argument("--check", action="store_true")
    options = args.parse_args()
    directory = ROOT / "examples/assets/images"
    directory.mkdir(parents=True, exist_ok=True)
    for name, contents in fixtures():
        path = directory / name
        if options.check:
            if path.read_bytes() != contents:
                raise SystemExit("Fixture differs: " + name)
        else:
            path.write_bytes(contents)


if __name__ == "__main__":
    main()
