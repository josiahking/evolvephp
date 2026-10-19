"""Build the EvolvePHP MkDocs site and add the GitHub Pages marker."""
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parent
subprocess.run([sys.executable, "-m", "mkdocs", "build", "--strict"], cwd=root, check=True)
shutil.copyfile(root / "docs" / ".nojekyll", root / "build" / ".nojekyll")
print("Verified GitHub Pages .nojekyll marker")
