# Ingredient normalisation and review

The first ingredient pass uses a rerunnable CLI command and a curated CSV seed. The source recipe lines remain intact. Migration 007 adds parsed fields and a child table for lines with multiple ingredients, because one parent ingredient ID cannot represent both parts of a line.

The parser handles quantities, fractions, ranges and known Swedish units. Unit names, preparation words, conjunctions, ingredient names, plurals and spelling variants live in seed data. Exact alias lookup uses the Swedish case-insensitive table collation. A partial or compound match is only a suggestion; known suffixes receive priority because the head of a Swedish compound commonly appears at the end. A line with an ambiguous conjunction or one quantity shared by multiple ingredients is flagged instead of silently mapped.

The unknown review queue counts current ingredient parts, rather than counting how often the command ran. A zero count means a previously unknown token is no longer present after a rerun. The report exposes match and parsing coverage plus the review backlog. The initial seed targets about 300 common ingredients and can be expanded from pilot results without changing PHP grammar rules.
