# Dinner classification audit after the full crawl

The production samples exposed baking and dessert recipes in dinner pools and meat or fish dishes labelled vegetarian. Source recipe categories now take priority over ingredient and title signals for dinner eligibility. Unambiguous dessert titles always exclude. Baking ingredients and broad title terms exclude only when no mapped main meat or fish protein, substantial vegetarian main protein, or seeded savory title term provides clear dinner evidence. Egg by itself does not qualify a recipe as dinner. The sugar-and-flour share and audit sample size are configured in migration 021.

Vegetarian classification checks mapped animal evidence in every ingredient line and the title. An unmatched line containing a seeded animal stem keeps vegetarian unknown. The new ingredient, alias, descriptor and title vocabularies remain in seed CSV files so a production rerun can improve classifications without editing existing migrations.

Processing reports the candidate changes, sampled removed and remaining titles, and all removed titles with a seeded savory audit term. The comparison is relative to stored pre-run flags and preserves manual admin overrides. The operator will review production results after merging and deploying; development uses the local pilot database only.
