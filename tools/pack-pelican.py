"""Zips the Pelican plugin into dist/dylaris-gateway-<version>.zip, folder dylaris-gateway/ at the root of the archive.

Run tools/build-plugins.sh first (core-src/ must exist). tests/ is not shipped. The PteroExt is made by tools/pack-ptero.sh.
"""
import json
import pathlib
import sys
import zipfile

root = pathlib.Path(__file__).resolve().parent.parent
src = root / "pelican" / "dylaris-gateway"
if not (src / "core-src").is_dir():
    sys.exit("core-src/ is missing: run tools/build-plugins.sh first.")
version = json.loads((src / "plugin.json").read_text(encoding="utf-8"))["version"]
out = root / "dist" / f"dylaris-gateway-{version}.zip"
out.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(src.rglob("*")):
        rel = f.relative_to(src)
        if f.is_dir() or rel.parts[0] == "tests":
            continue
        z.write(f, "dylaris-gateway/" + rel.as_posix())
print(out)
