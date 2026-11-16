# Use the full Köket sitemap for primary discovery

Date: 2026-11-16

The saved Köket sitemap has 41,747 URL entries, each with `lastmod`. The current non-recipe path exclusions remove 241 entries, leaving 41,506 possible recipe URLs. This is a candidate count rather than a confirmed recipe count because chef and other non-recipe pages can use root-level slugs. The fetch stage classifies those pages and records `not_recipe` when appropriate.

The sitemap covers a large part of the catalogue, but neither of the two separately saved recipe pages appears in it. Use the sitemap as the primary discovery route and category crawling as a backup for omissions. Tests use a trimmed sitemap fixture with 38 original entries and their original `lastmod` values, plus the two saved recipe URLs without invented `lastmod` values. The full saved sitemap stays only in gitignored local fixture storage.
