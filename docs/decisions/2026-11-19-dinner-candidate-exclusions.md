# Dinner candidate exclusions

Source meal-type tags sometimes classify breakfast and side dishes as main courses. The derivation now applies seeded title exclusions after initial meal-type classification. Unclassified recipes retain the existing seeded exclusions for baking, dessert, drinks, sauces, spice mixes and preserves.

Breakfast and bruschetta terms always exclude. Salsa and dip terms exclude when the dish name before a seeded accompaniment separator contains them, so a dinner served with salsa or dip stays eligible. Salad and side terms exclude only without evidence of a substantial main ingredient: animal protein, carb base or a curated vegetarian main ingredient. Cheese used as garnish and egg yolk in a sauce do not rescue a side dish. All Swedish terms and the vegetarian main ingredient allowlist remain in seed CSV files. This is a derived eligibility flag and can be recalculated; it does not change source tags or meal-type codes.

The pilot baseline and after report are kept locally under `server/var/`. A full-crawl review should check false positives and further seed coverage before broadening the exclusions.
