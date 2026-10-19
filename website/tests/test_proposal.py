import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"

class MkDocsProposalTests(unittest.TestCase):
    def test_config_and_runtime(self):
        config = (ROOT / "mkdocs.yml").read_text(encoding="utf-8")
        self.assertIn("site_name: EvolvePHP", config)
        self.assertIn("site_url: https://josiahking.github.io/evolvephp/", config)
        self.assertIn("name: mkdocs", config)
        self.assertIn("- search", config)
        self.assertIn("next/index.md", config)
        self.assertNotIn("docusaurus", config.lower())

    def test_content_integrity(self):
        pages = list((DOCS / "next").rglob("*.md"))
        self.assertEqual(19, len(pages))
        self.assertTrue((DOCS / "next/index.md").exists())
        self.assertTrue((DOCS / "index.md").exists())
        self.assertTrue((DOCS / "versions/index.md").exists())
        self.assertTrue((DOCS / ".nojekyll").exists())
        for page in pages:
            text = page.read_text(encoding="utf-8")
            self.assertNotIn("npm run docusaurus", text)
            self.assertNotIn("docs:version", text)
            self.assertNotIn("docusaurus", text.lower())
        self.assertIn("McpComponentContribution", (DOCS / "next/packages/mcp.md").read_text(encoding="utf-8"))
        self.assertFalse((DOCS / "versions/2.0.0-alpha.1").exists())

    def test_relative_links(self):
        for page in DOCS.rglob("*.md"):
            text = page.read_text(encoding="utf-8")
            for target in re.findall(r"!?(?:\[[^\]]*\])\(([^)]+)\)", text):
                target = target.split("#", 1)[0]
                if not target or target.startswith(("https://", "http://", "mailto:")):
                    continue
                self.assertTrue((page.parent / target).exists(), f"{page}: {target}")

    def test_no_generated_source(self):
        for path in ("build", ".venv", ".docusaurus", "node_modules"):
            self.assertIn(path + "/", (ROOT / ".gitignore").read_text(encoding="utf-8"))
        self.assertFalse((ROOT / "package.json").exists())

if __name__ == "__main__":
    unittest.main()
