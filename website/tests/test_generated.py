from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
BUILD = ROOT / "build"

class GeneratedSiteTests(unittest.TestCase):
    def test_gh_pages_marker_and_routes(self):
        self.assertTrue((BUILD / ".nojekyll").is_file())
        for path in ("index.html", "next/index.html", "next/packages/mcp/index.html", "versions/index.html", "search/search_index.json"):
            self.assertTrue((BUILD / path).is_file(), path)

    def test_search_canonical_and_edit_links(self):
        index = json.loads((BUILD / "search/search_index.json").read_text(encoding="utf-8"))
        self.assertTrue(any("McpComponentContribution" in item.get("text", "") for item in index["docs"]))
        html = (BUILD / "next/packages/mcp/index.html").read_text(encoding="utf-8")
        self.assertIn('href="https://josiahking.github.io/evolvephp/next/packages/mcp/"', html)
        self.assertIn("edit/2.x/website/docs/next/packages/mcp.md", html)

if __name__ == "__main__":
    unittest.main()
