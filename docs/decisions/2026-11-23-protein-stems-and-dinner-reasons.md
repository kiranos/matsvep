# Protein stems and dinner exclusion reasons

Production sampling after the first dessert and baking pass showed that broad source categories excluded meals with clear proteins, while unmatched compounds left many dinner candidates without a main protein.

We store a `dinner_exclusion_reason` on excluded recipes. Unambiguous dessert source categories and title words take precedence. A main animal or substantial vegetarian protein, an animal protein in the title, or a seeded savory dish word overrides broad category, ingredient, title, breakfast and side exclusions. An explicit admin dinner override remains final and records `manual_override` when it excludes a recipe. Re-derivation refreshes automatic reasons while preserving manual corrections.

Protein stems are Swedish seed data. They match word edges in titles and every ingredient line, including unmatched lines. The longest matching stem wins within a kind, a compound ending takes precedence over its prefix, and vegetarian compounds take precedence over generic meat stems. The ambiguous `rom` requires fish context and is disabled for dessert and drink pages; explicit roe compounds are unambiguous. Reindeer uses explicit compounds only. Stocks and sauces establish animal evidence without choosing a main protein. Protein choice otherwise retains the title, gram amount and main-line tie-break order.

`processing-report.json` records stem match counts and before and after protein and exclusion-reason distributions. A read-only tag report lists source tag counts overall and among active dinner candidates. Its results will guide a later category seed update against production data.
