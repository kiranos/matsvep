# Synthetic source fixtures in the public repository

The repository is public, while collected recipe pages and database content are for private family use. Committed parser and discovery fixtures therefore contain only invented text and invented page paths. They retain the HTML, JSON-LD, canonical-link and sitemap shapes needed by tests.

Saved real pages may be kept under the gitignored `server/tests/fixtures/koket/local/` directory for optional local parser checks. Tests skip those checks when the files are absent; CI uses only synthetic fixtures. The adapter name, source base URL, robots rules, non-recipe path patterns and source references in the design remain because they describe the source rather than reproduce its pages. No Git history rewrite is part of this change.
