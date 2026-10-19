from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit, unquote
import posixpath
import os
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BUILD = ROOT / "build"

class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.targets = []
    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        key = "href" if tag in ("a", "link") else "src" if tag in ("img", "script") else None
        if key and key in values:
            self.targets.append(values[key])

class SiteIntegrityTests(unittest.TestCase):
    def test_generated_internal_targets(self):
        for page in BUILD.rglob("*.html"):
            parser = Links()
            parser.feed(page.read_text(encoding="utf-8"))
            page_route = page.relative_to(BUILD).as_posix().removesuffix("index.html")
            for target in parser.targets:
                url = urlsplit(target)
                if url.scheme or url.netloc or target.startswith(("mailto:", "data:")):
                    continue
                route = unquote(url.path)
                if not route:
                    continue
                if route.startswith("/"):
                    self.assertTrue(route.startswith("/evolvephp/"), f"{page}: {target}")
                    route = route.removeprefix("/evolvephp/")
                else:
                    route = posixpath.normpath(posixpath.join(page_route, route))
                dest = BUILD / route
                self.assertTrue(dest.is_file() or (dest / "index.html").is_file(), f"{page}: {target}")

    def test_php_runtime_is_84(self):
        php = os.environ.get("EVOLVEPHP_DOCS_PHP")
        self.assertIsNotNone(php, "Set EVOLVEPHP_DOCS_PHP to an explicit PHP 8.4 executable")
        result = subprocess.run([php, "-r", "echo PHP_VERSION_ID;"], text=True, capture_output=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertGreaterEqual(int(result.stdout), 80400)
        self.assertLess(int(result.stdout), 80500)

    def test_php_fences_parse(self):
        php = os.environ.get("EVOLVEPHP_DOCS_PHP")
        self.assertIsNotNone(php, "Set EVOLVEPHP_DOCS_PHP to an explicit PHP 8.4 executable")
        count = 0
        for page in (ROOT / "docs" / "next").rglob("*.md"):
            text = page.read_text(encoding="utf-8")
            for snippet in re.findall(r"~~~php\s*\n(.*?)\n~~~", text, flags=re.S | re.I):
                count += 1
                with tempfile.NamedTemporaryFile("w", suffix=".php", encoding="utf-8", delete=False) as tmp:
                    tmp.write(snippet)
                    name = tmp.name
                try:
                    result = subprocess.run([php, "-l", name], text=True, capture_output=True)
                    self.assertEqual(0, result.returncode, f"{page}: {result.stdout} {result.stderr}")
                finally:
                    Path(name).unlink()
        self.assertGreaterEqual(count, 5)

if __name__ == "__main__":
    unittest.main()
