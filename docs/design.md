# Matsvep – Design Document

Nov 15, 2026 · @Stefan de Vries

## Summary

Matsvep ("food swipe") helps a family choose weekly dinners when nobody has ideas. A rule-based engine ranks recipes for each day, and the family picks from fast swipe decks. The engine learns from one shared 1–5 rating per recipe and gets better with use. No AI or machine learning runs in the app: every suggestion can be traced back to numbers the family can see.

Design principles:

- **Explainable:** each score is a sum of named parts, and the largest parts become the short reason shown on each card.
- **Deterministic and tunable:** the same data and config give the same result; a seed per planning adds controlled variation. Every weight and threshold lives in a server-side config table.
- **Learns fast, stays stable:** ratings update the recipe and the family's taste profile, with confidence weighting so no single rating dominates.
- **The family is the unit:** the family cooks one meal, decides together and rates together. There are no individual profiles.
- **Built for one family, ready for many:** every table is keyed by `household_id`.
- **Simple stack:** PHP 8.5, MySQL 8.4 and Apache on one Linux VPS with scheduled CLI jobs, plus native Windows and Android apps built with Flutter.

## Requirements

| Topic | Requirement |
| --- | --- |
| Household | 2 adults and 2 kids today; any family size supported |
| Rating | One shared 1–5 rating per recipe, given the first time it is cooked and editable at any time; any family member can answer for the family |
| Dietary rules | No allergy logic; the family blocks recipes or adapts them. A family setting can block all vegetarian dishes |
| Onboarding | One joint family quiz sets starting ratings and taste |
| Favourite / new mix | 70 / 30 by default, adjustable by the family |
| Plan week | Sunday to Saturday; plan all 7 days or Sun–Thu |
| Planning mode | Whole week chosen on Sunday, or split: Sun–Tue chosen on Sunday and Wed–Sat on Wednesday |
| Choosing | Swipe deck of ranked suggestions per day; the same deck replaces a chosen dish |
| Day profiles | `weekday_leftovers` on Sun–Thu feeds the next day's work lunch (batch-friendly dishes, ≤ 60 min Mon–Thu, ≤ 90 min Sun); `friday_treat` suits Friday; `saturday_dinner` favours a nicer dish with few leftovers |
| After dinner | The app asks if the dinner was cooked; uncooked dinners move to the next planning by default |
| Explanations | Short reason on each card, full breakdown on tap |
| Recipe intake | Recipes come from configured Swedish sites; the family can search them and add to a want-to-cook list; no free-form URL import |
| Recipe storage | Full parsed recipes stored locally, with source links |
| Tuning | All weights, limits and rules are server-side settings |
| Platforms | Linux server (PHP, MySQL, Apache, no containers); native Windows and Android apps |
| Use | Private family use; may become a public service later |
| Out of scope | Shopping lists, prices, seasons, nutrition, portion scaling, meals cooked outside the plan, AI in the app |

## Recipe sources

None of the big Swedish recipe sites offers a public API, so recipes are collected from the sites' own pages through one adapter per site. Each recipe is parsed and **stored in full** in MySQL, with its source link kept for attribution.

| Site | Discovery | robots.txt (checked 2026-11-15) | Terms | Status |
| --- | --- | --- | --- | --- |
| [Köket.se](https://www.koket.se/) (TV4) | Broad `sitemap.xml` as the primary route; category pages as backup for omissions | `Allow: /` for all agents | [Private use only](https://www.koket.se/kokets-anvandarvillkor); no public redistribution; copying for private use allowed; page footer says "Do not mine us" | First source |
| [ICA.se](https://www.ica.se/robots.txt) | `/recept/sitemaps/` | Open for normal agents; deep recipe paths blocked for AI crawlers | [ICA Gruppen terms](https://icagruppen.se/installningssida/anvandarvillkor): private, non-commercial use; no republishing without written consent | Later source |
| [Arla.se](https://www.arla.se/robots.txt) | `sitemap.index.xml` | Recipe pages allowed; search, `/recept/?` queries and collection sub-pages blocked | No published site terms; the footer links only to privacy and cookie policies (checked 2026-11-15). Swedish copyright law applies | Later source; OK for private use |
| [Coop.se](https://www.coop.se/robots.txt) | `sitemap.xml` | Recipe pages allowed; filtered and sorted lists blocked | Only customer-account terms, which reserve Coop's rights in its digital channels; no recipe-specific terms (checked 2026-11-15) | Later source; OK for private use |
| Livsmedelsverket food database | Open API, CC BY 4.0 | – | Attribution required | Optional, for a Swedish ingredient vocabulary |

Under Swedish law, ingredient lists and plain method steps usually lack *verkshöjd* (originality), so they aren't covered by copyright; photos and descriptive text are ([Lawline](https://lawline.se/answers/skyddas-recept-av-upphovsratten)). Copying for private use is also allowed under the Swedish copyright act (2 kap. 12 § URL). Storing full recipes for private family use is therefore fine for all four sites, as long as photos are only linked. This is guidance, not legal advice.

The repository is public. Parser and discovery tests use small synthetic pages and invented recipe URLs. Saved real source pages, database dumps and processing reports stay outside Git under ignored local paths. Source names, base URLs, robots rules and non-recipe path patterns remain documented as adapter facts.

### Ingestion pipeline

Ingestion runs from a systemd timer, never on a user request.

1. **Discover:** read each enabled source's sitemap and queue same-host URLs, excluding known non-recipe sections. For Köket, the sitemap is the broad primary route and category pages can supplement its omissions. Köket recipe URLs can be root-level slugs; URL shape alone does not establish that a page is a recipe. Queue new URLs and requeue fetched URLs when a sitemap change date is newer than the last fetch.
2. **Fetch politely:** obey robots.txt, use an identifying User-Agent, wait at least `crawl.delay_s` between requests per site, and use a `crawl.timeout_s` request timeout. Cache ETag and Last-Modified. Retry failures with capped exponential backoff, respecting a longer `Retry-After` value, and stop after `crawl.max_attempts`. A same-host redirect queues its target and marks the old URL gone; an off-host redirect is a failed fetch.
3. **Classify and parse:** a fetched page is a recipe if it has schema.org `Recipe` JSON-LD or Köket's recipe `page-type` meta tag. Mark other pages `not_recipe` without retrying them. Read JSON-LD first, then fall back to the adapter's HTML selectors for name, ingredients, steps, time, yield and tags. Preserve available ingredient and step section names. Store source cuisine and category as `recipe_tag` rows; the derived `cuisine` and `meal_type` fields are filled by later normalisation. Keep a compressed raw snapshot so recipes can be re-parsed when a parser improves.
4. **Normalise:** parse quantity, range, unit, ingredient text and preparation notes from each original ingredient line. Exact case-insensitive Swedish alias matches map to canonical ingredients. The seed has common ingredients, plurals, spelling variants, units, note words, descriptors and conjunctions in CSV data. An exact ingredient match takes precedence over descriptor removal, which is accepted only if the remaining text matches an ingredient. A line with several ingredients creates one `recipe_ingredient_part` per safely split ingredient; an `A eller B` line records the first known alternative and is flagged only when neither is known. A shared quantity on a combined line is flagged for review. Unknown tokens enter the review queue. Suggestions favor a known compound head or prefix and otherwise require the configured Unicode edit-similarity threshold; weak suggestions remain empty. Suggestions never map automatically. The command is safe to rerun after seed or parser improvements.
5. **Derive features:** a rerunnable CLI pass sets main protein, carb base, cuisine, meal type, vegetarian flag, effort, festive score and leftover score from normalised ingredients, title, tags and steps (see Data model). Seeded non-food lines are ignored. Derivation also flags main ingredient lines; unmatched substantial lines and protein candidates remain visible in main-line coverage. Vegetarian is true only when at least one main line exists, every main line is fully matched, and no known meat or fish ingredient or title protein is present. Known meat or fish in any ingredient line or the title makes it false; an unmatched ingredient line with a known meat or fish stem makes it unknown. Ingredient descriptors are removed only when the remainder matches a known ingredient. A shared-ending compound ending in a hyphen stays intact across a conjunction.

   Source meal-type tags are authoritative: `Huvudrätt` wins over any simultaneous meal-type tag; without it, `Förrätt`, `Efterrätt`, `Buffé`, `Brunch`, `Tillbehör`, `Snittar/tilltugg`, `Fika`, `Frukost` and `Mellanmål` exclude regardless of protein. `Fest` and `Picknick` are neutral occasion tags. A seeded preparation, condiment or drink term in the leading dish name (before an accompaniment word or comma) excludes even with `Huvudrätt`, unless that leading part also names a protein or seeded dinner dish. Seeded suffix terms must end a word, so a condiment term cannot match the beginning of an unrelated meat dish. With both `Huvudrätt` and `Efterrätt`, the dessert tag alone cannot exclude; an unambiguous dessert title or baking ingredient signal can. With no meal-type tag, a main meat, fish or substantial vegetarian protein, an animal protein named in the title, or a seeded dinner dish word is required; a carb base alone is insufficient. A pie is savory only without seeded fruit, berry, chocolate, caramel or other sweet evidence in its title or ingredients, or sugar in at least `feature.sweet_pie_sugar_share` of food lines. Citrus is title-only evidence because it commonly seasons savory pies. Foreign title accents are folded for term matching, while Swedish `å`, `ä` and `ö` remain distinct. Baking ingredients mean a seeded direct signal, or sugar and flour covering at least `feature.baking_sugar_flour_share` of non-ignored lines. Egg alone and explicit breakfast title exclusions remain safeguards for `Huvudrätt` recipes without a simultaneous `Efterrätt` tag. Every excluded recipe stores `dinner_exclusion_reason`: `source_category`, `baking_ingredients`, `dessert_title`, `breakfast_or_side`, `non_dinner_title` or `insufficient_evidence`. A manual exclusion stores `manual_override`; manual corrections are applied after derivation.
6. **Deduplicate and group:** merge copies and link variants of the same dish (below).
7. **Check sources:** a nightly job re-fetches about 1% of recipes and marks any that return 404 as `source_gone`. Those recipes stay fully usable.

For the phase 1 ingredient pass, `php bin/seed-ingredients.php` loads the Swedish CSV vocabulary idempotently. `php bin/normalise.php --recipe=ID` processes one recipe, `--all` processes the catalogue, and `--report` reads the current coverage without changing rows. The UTF-8 JSON report includes matched-line share, quantity and unit coverage, the top 50 unknown tokens, the top 20 unparsed unit words and examples of lines whose ingredients could not be split safely. Protein-candidate coverage counts lines containing an alias in the protein or legume categories or a curated protein term, including unmatched compound forms; the report gives the share of those lines fully matched. Unknown occurrences are recomputed from stored ingredient parts on each run, so reruns do not inflate counts. Pending suggestions are refreshed on rerun. The original `raw_text` remains intact.

`php bin/seed-features.php` loads the Swedish `leftover_keyword` data. After normalisation, `php bin/derive-features.php --all` sets features for the catalogue; `--recipe=ID` reruns one recipe and `--report` only refreshes the report. It writes `server/var/feature-report.json` with protein, cuisine, meal-type and leftover-score distributions, main-line match share, and 20 recipes sampled by a fixed hash seed for review. The original recipe content and manually curated seed files remain separate from PHP rules. A subsequent `php bin/dedupe.php --all` pass uses canonical URLs and title-plus-main-ingredient Jaccard overlap to merge copies or queue review, then groups active variants by reduced title and protein. Its pilot report under `server/var/` contains merge, review and dish-group counts and the largest groups. A separate local protein-gap report lists 30 baseline gaps with main ingredient lines and current mappings.

Read-only `--protein-gap-sample=N`, `--vegetarian-sample=N` and `--dinner-sample=N` modes print up to 100 active dinner candidates and their main ingredient lines. The dinner sample covers all dinner candidates and also prints each recipe's title, URL, source meal-type tags, main protein, cuisine and vegetarian flag. An optional `--seed=N` reproduces a sample; without it the command generates and prints a seed. Protein-gap reasons are diagnostic hints: an unmatched main line takes precedence, then a safely split multi-ingredient line is marked mixed, and remaining cases suggest a missing protein category. Sampling does not mutate or refresh stored features. `processing-report.json` records vegetarian true, false and unknown counts and the dinner-candidate protein gap before and after feature derivation. `ingredient-report.json` is saved after main-line flags are refreshed.

The processing report also records dinner candidates before and after derivation, removed and newly added counts, 30 reproducibly sampled removed titles and 30 remaining titles. It lists every removed title containing the seeded savory audit terms so a category or dessert exclusion cannot quietly remove a likely dinner. It includes before and after counts of recipes without a source meal-type tag, dinner cuisine and main-protein distributions, the dinner-candidate protein gap, exclusion reasons, and each protein stem matched in a title or ingredient line. This comparison reflects the previous stored candidate flags, including manual overrides; a second run with unchanged data reports zero changes. `php bin/derive-features.php --tag-report` reads source `recipe_tag` values with overall, active and active dinner-candidate counts without changing stored recipes.

### Duplicates and dish groups

| Case | Example | Handling |
| --- | --- | --- |
| **Copy:** the same recipe in two places | An Arla recipe republished on Köket, or a recipe moved to a new URL | Merged into one recipe; every source URL is kept as a link |
| **Variant:** different recipes for the same dish | 12 köttbullar recipes across four sites | Kept as separate recipes, linked in one *dish group* |

Detection:

1. **Same site:** canonical URL, JSON-LD `@id` and redirects catch moved pages.
2. **Copies across sites:** normalised titles and main-ingredient sets are compared (Jaccard similarity). At ≥ 0.95 with similar time and servings, recipes merge automatically; scores from 0.80 to below 0.95, or high scores with differing time or servings, go to an admin review queue. The time and servings tolerances come from `engine_config`. Merged copies are marked inactive with `merged_into_id`; source links and ingest references move to the kept recipe while raw records remain available on the inactive copy. Every candidate query and dish-group best-recipe selection filters to active recipes. The most complete parse is kept, with ties broken by a source priority setting.
3. **Variants:** the title is reduced to its core dish by removing words like *klassiska*, *mormors*, *enkla*, *snabba* and side dishes after "med". The same core dish, the same main protein and ≥ 0.5 ingredient overlap put recipes in one dish group. Admin can split or merge groups. Title reductions live in seed CSV data, and automatic reruns leave manually overridden groups alone.

In the engine, a dish group means:

- **Recency per dish:** cooking any köttbullar recipe puts every köttbullar recipe on cooldown.
- **One per week:** two recipes from the same group are never chosen in the same week.
- **One card per dish:** a deck shows only the best recipe of each group, with a localised link to other variants.
- **The family's version wins:** once the family rates a variant 4★ or higher, that version represents the group.
- **Siblings inform new variants:** an unrated variant uses its rated siblings as a strong prior (see Pref); ratings themselves are never copied.

### Storage rules, search and add

- Images are linked by URL and never downloaded. Every recipe view shows the source name and a link.
- A per-source `display_mode` (*full* / *index\_only*) can hide steps for a source, with no schema change.
- The family searches the local database with MySQL `FULLTEXT` on title and ingredients, filtered by source. The add action puts a recipe on the family's wishlist.

## Data model

The model has three layers: the **recipe database**, shared by all households; **household state**, which holds each family's choices, ratings and learned taste; and **engine config**, which holds every tunable number. Learned taste is stored as plain affinity rows, so the engine's memory can be read and debugged in SQL.

**Recipe database**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `source` | id, name, base\_url, adapter, enabled, crawl\_delay\_s, last\_crawl\_at, display\_mode | One row per recipe site |
| `recipe` | id, source\_id, url, title, image\_url, total\_time\_min, total\_time\_source (given / estimated / unknown), servings, cuisine, meal\_type, main\_protein, carb\_base, is\_dinner\_candidate, dinner\_exclusion\_reason, is\_vegetarian, leftover\_score (0–1), festive\_score (0–1), effort (1–3), dish\_group\_id, dish\_group\_manual, merged\_into\_id, source\_status (ok / source\_gone), parsed\_at, parser\_version, active | Parsed recipe and derived features |
| `recipe_ingredient` | recipe\_id, position, group\_name, raw\_text, ingredient\_text, note, quantity, quantity\_max, unit, unparsed\_unit, ingredient\_id, normalise\_status (matched / partial / unknown / unsafe\_split / ignored), is\_main | Original line and parsed amount, unit, ingredient and review state; `ingredient_id` is set only for a single matched ingredient |
| `recipe_ingredient_part` | recipe\_id, position, part\_position, ingredient\_text, ingredient\_id, ingredient\_unknown\_id | One mapped or unknown ingredient per part of a line; cascades with its parent line |
| `recipe_step` | recipe\_id, position, group\_name, text | Cooking steps |
| `recipe_tag` | recipe\_id, tag | Tags such as *taco*, *gryta*, *ugn*, *fredagsmys* |
| `recipe_source_link` | recipe\_id, source\_id, url, is\_primary | All source URLs of a merged recipe |
| `crawl_discovery` | source\_id, sitemap\_hash, completed\_at | Marks a completed full sitemap discovery pass |
| `recipe_raw` | recipe\_id, fetched\_at, etag, format (jsonld / html), payload\_gz | Compressed raw snapshot for re-parsing |
| `ingest_url` | id, source\_id, url, url\_hash (unique per source), discovered\_via (sitemap / category), status (queued / fetched / failed / skipped\_robots / gone / not\_recipe), etag, last\_modified, http\_status, attempts, next\_attempt\_at, last\_fetched\_at, recipe\_id (nullable) | Resumable discovery and fetch queue, conditional requests and retry state; `not_recipe` is terminal |
| `ingredient` | id, canonical\_name, category, is\_staple | Canonical vocabulary; staples (salt, oil, onion) are ignored in similarity |
| `ingredient_alias` | alias, ingredient\_id | Swedish synonym map |
| `ingredient_unknown` | id, raw\_text, normalised\_token (unique), occurrences, first\_seen\_at, status (pending / mapped / staple / ignored), suggested\_ingredient\_id (nullable), mapped\_ingredient\_id (nullable) | Review queue for ingredient tokens the normaliser cannot map |
| `leftover_keyword` | keyword, signal\_type (dish\_type / method / protein\_form), value (−1 to 1) | Keyword lists behind leftover\_score |
| `dish_group` | id, core\_name, main\_protein, manual\_override | Variants of the same dish |
| `duplicate_candidate` | recipe\_a, recipe\_b, similarity, status (pending / merged / not\_duplicate) | Admin review queue for possible copies |
| `user` | id, email, password\_hash, role (admin / member), household\_id (nullable for admin), failed\_attempts, locked\_until, created\_at | Admin cookie sessions and household member identities remain separate |
| `admin_audit` | id, user\_id, action, target\_type, target\_id, before\_json, after\_json, created\_at | Records admin mutations and account activity |
| `recipe_feature_override` | recipe\_id, main\_protein, carb\_base, cuisine, meal\_type, is\_dinner\_candidate, leftover\_score, updated\_by, updated\_at | Manual corrections reapplied after derivation |

**Household state**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `household` | id, name, size, timezone, state\_version, created\_at | The family; default time zone is `Europe/Stockholm`. State version advances on planning-relevant household changes |
| `device_token` | id, user\_id, platform (android / windows), refresh\_token\_hash, push\_token, expires\_at, revoked\_at, last\_seen\_at | Member app logins and push notifications; refresh tokens are rotated and stored only as hashes |
| `household_invite` | id, household\_id, created\_by, code\_hash, expires\_at, used\_by, used\_at | One-time member invitation; plaintext code is returned only when created |
| `api_idempotency` | user\_id, idempotency\_key, request\_hash, response\_status, response\_json, expires\_at | Stores the original result of a retryable member write per user |
| `household_setting` | household\_id, key, value | Favourite share, plan days, planning mode, block vegetarian |
| `day_profile_default` | weekday (0=Sunday through 6=Saturday), profile\_code | Seven seeded global profiles |
| `day_profile` | household\_id, weekday, profile\_code | Optional household override of a global weekday profile |
| `household_recipe` | household\_id, recipe\_id, status (library / removed / blocked), wishlist, rating (1–5, nullable), rating\_source (onboarding / user), rated\_at, last\_cooked\_at, times\_cooked | The family's relation to each recipe |
| `rating_history` | household\_id, recipe\_id, old\_rating, new\_rating, source, changed\_at | Audit trail of rating changes |
| `affinity` | household\_id, feature\_type (ingredient / cuisine / protein / carb / meal\_type / tag), feature\_value, score, weight\_sum, updated\_at | Learned taste per feature |
| `onboarding_answer` | household\_id, question\_id, answer | Raw quiz answers, so starting ratings and seeds can be recomputed |
| `plan` | id, household\_id, week\_start (Sunday), part (1 / 2), days, seed, status, version, config\_version, shortlist\_state\_version, shortlist\_config\_version | One planned week or split half; version is incremented on each plan mutation |
| `plan_candidate` | plan\_id, recipe\_id | Filtered planning shortlist kept for fast re-ranking after a pick; hard filters are checked again when it is read. `plan` stores the household and config versions used to build it |
| `plan_slot` | id, plan\_id, household\_id, date, day\_profile, recipe\_id, chosen\_by (engine / family), status (proposed / chosen / cooked / not\_cooked / unconfirmed), kind (favourite / discovery), score, predicted\_pref, explanation\_json, replaced\_from\_recipe\_id | One household-scoped dinner and why it was chosen |
| `carryover` | household\_id, recipe\_id, from\_slot\_id, target\_week, target\_part, status (pending / placed / expired) | Uncooked dinners pinned into the next planning |
| `slot_skip` | household\_id, recipe\_id, reason (replaced / swipe\_rejects), skipped\_at, expires\_at | Source of SkipPen |
| `swipe_event` | household\_id, plan\_id, date, recipe\_id, position, action (pick / next / undo), session\_id, created\_at | Every swipe; feeds swipe metrics and repeated-reject logic |
| `meal_log` | id, household\_id, recipe\_id, cooked\_on, plan\_slot\_id | Dinners counted as eaten; drives recency and variety |
| `engine_config` | key, value, type, description, version | Server-side settings |

**Operational metadata**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `schema_migrations` | migration, checksum, status (started / applied), started\_at, applied\_at | Records numbered SQL files and detects changes or interrupted application |

**Phase 2 storage rules.** Every user insert names its role explicitly; a member must have a `household_id`, while existing admins may have none. App bearer tokens are issued only for members and never authorize `/admin/`; admin cookie sessions never authorize `/api/v1`. Invite codes are single-use, expire after `household.invite_ttl_hours`, and are stored as hashes. The first registered member creates a household; another registers only with a valid invite. `day_profile_default` holds one seeded row for each Sunday–Saturday weekday, while `day_profile` stores household overrides. Weekdays use PHP `date('w')`: 0 is Sunday and 6 is Saturday. Composite foreign keys keep invite creators and consumers, plan slots, carryovers, swipes and meal logs within their household.

Household `timezone` is an IANA time-zone name, defaulting to `Europe/Stockholm`. `week_start`, plan-slot dates, target weeks and cooked dates are household-local calendar dates. Database timestamps stay UTC. Planning at 07:00 and day-after prompts are scheduled using the household's time zone, including daylight-saving changes.

**Phase 2 API contract.** `/api/v1` uses member bearer tokens with rotating, hashed per-device refresh tokens. Errors have an English `code` and structured `params`, with no display message. Reasons and events likewise contain codes and parameters; the Flutter localisation files render their text. Recipe titles and ingredient names are data, so they retain their source language. Search, wishlist, plans and decks use opaque cursors and return `next_cursor`.

Queued app writes accept a client UUID in `Idempotency-Key`. The server stores the request hash, HTTP status and JSON response per user for `api.idempotency_ttl_hours`; an exact retry returns the original result, while reuse with another request returns `idempotency_key_reused`. The idempotency lookup precedes the version check so a replay of a successful choice remains successful. Choose, replace, autofill and shuffle require `expected_version`; a stale value returns HTTP 409 `plan_version_conflict` with `current_version` in `params`, and a successful result includes the incremented `version`. A right swipe is logged atomically by choose; the separate swipes endpoint records next and undo. Day-after answers, ratings and other retryable member writes also accept idempotency keys.

**Derived recipe features**, set by rules during ingestion and correctable per recipe in admin:

- **leftover\_score (batch-friendliness):** how naturally the dish makes many portions, judged by the type of dish, not by servings. Stews, bönchili, bolognese, köttfärssås, boeuf bourguignon, soups, curries, gratins and lasagne score high. Dishes built from individual pieces, such as kycklingbröst, fiskfilé, biff or fläskkotlett, score low. Three keyword signals combine:
  - **Dish type (weight 0.6):** title and tags, e.g. *gryta*, *ragu*, *soppa*, *lasagne*, *gratin*, *curry*, *chili*.
  - **Cooking method (0.3):** steps mentioning slow or one-pot cooking, e.g. *puttra*, *långkok*, *sjud*.
  - **Protein form (0.1):** köttfärs, grytbitar, högrev or baljväxter score high. Whole pieces cooked one by one (bröstfilé, filé, kotlett, biff) score low. The same protein cut up and cooked in a sauce ("i bitar", "strimlad" or "tärnad" in a gryta or one-pan dish, e.g. kycklinggryta) scores high, because the test is one big pan versus frying many pieces.
- **festive\_score:** high for longer cook times and premium proteins (oxfilé, lamm, skaldjur). A seeded `Fest` occasion tag adds `feature.fest_tag_bonus` once; the score remains capped at 1.
- **effort:** a 1–3 bucket from total time and ingredient count.

The first phase 1 rules use these explicit definitions, with every adjustable number stored in `engine_config`:

- `is_main` is true for a non-staple mapped ingredient in a configured main category. An unmatched line is also main when it contains a known protein term or at least 100 g of an unmatched ingredient. Ignored non-food lines are never main. The report shows how many main lines are fully matched.
- `main_protein` uses the earliest specific seeded protein named in the title. Otherwise it chooses a mapped or stem-matched animal protein with the largest convertible gram amount; tied amounts use the first main ingredient line. A generic meat title term is a fallback after ingredient amounts. Stocks and sauces can rule out vegetarian classification but cannot select the main protein. A small amount of anchovy therefore follows the ordinary quantity tie-break. Compound matches favour the final word part, while a vegetable protein prefix wins a meat suffix. A shorter egg suffix cannot override a meat prefix. Bare `rom` is roe only with fish context, while explicit roe compounds always count. Reindeer requires an explicit reindeer compound. A substantial vegetarian protein item sets `main_protein = vegetarian` when there is no meat or fish evidence, even if other unmatched ingredients leave `is_vegetarian` unknown. Cheese and eggs yield `vegetarian` only when no meat or fish evidence remains and the dish is confidently vegetarian. A confidently vegetarian dish without a named protein also receives `vegetarian`. `carb_base` is selected from matching ingredient feature rows. Seeded source cuisine tags beat title keywords, and specific country tags beat broader regions such as `Asien`; manual cuisine overrides remain final. Source meal-type tags beat title terms, with `Huvudrätt` taking first priority. Unclassified values remain null.
- `is_dinner_candidate` follows source meal-type tag priority above, then the leading-title and sweet-pie exclusions. With no meal-type tag, it requires a main protein or seeded dinner dish word; a carb base alone does not qualify. Seeded breakfast and bruschetta title terms exclude those candidates; salsa and dip terms apply only to the dish name before a seeded accompaniment separator. Seeded salad and side title terms exclude untagged candidates without a main protein or dinner dish word; garnish cheese and egg yolk do not qualify. Sill titles without a source meal-type tag map to `buffet`.
- `is_vegetarian` is false when a mapped animal ingredient or confident animal title term appears. It is true only when at least one ingredient is mapped and no substantial or protein-like unmatched ingredient remains; otherwise it is null. Dairy and eggs may occur in a vegetarian recipe.
- For each leftover signal, the strongest matching keyword value in the range −1 to 1 is used. Dish type scans title and tags, method scans steps, and protein form scans ingredient text. A step with a seeded slow-cooking method and a duration of at least `feature.leftover_long_cook_min` minutes raises the method signal to `feature.leftover_long_cook_signal`; duration-unit words live in seed data. If a batch dish has cut-up steps, a negative whole-fillet protein signal becomes the configured cut-up signal. `leftover_score = clamp(baseline + scale × (dish_weight × dish + method_weight × method + protein_weight × protein), 0, 1)`. Defaults are baseline 0.5, scale 0.5 and weights 0.6 / 0.3 / 0.1 for dish type, method and protein form.
- The feature report includes all-recipe and dinner-only protein, cuisine, meal-type and leftover-score distributions, plus the highest and lowest dinner candidates for review.
- `festive_score = clamp(0.4 × min(total_time_min / 120, 1) + 0.35 × premium_ingredient + 0.25 × other_festive_tag + 0.2 × fest_tag, 0, 1)` with all coefficients loaded from `engine_config`. `Fest` sets only `fest_tag`, so it adds its bonus once. Missing time contributes zero to the festive score. Active step durations are summed when a source gives no total time; passive waiting durations are excluded using seeded phrases, and reprocessing reports how many stored estimates changed; `total_time_source` records whether the value was given, estimated, or still unknown. Unknown time is neutral in DayFit time caps and does not alone force an effort bucket. `effort` is 3 at 90 minutes or 15 ingredient lines, 1 at no more than 30 minutes and 8 lines, and 2 otherwise; missing time cannot yield effort 1; ingredient count can still make effort 3.

## Scoring

Every candidate recipe *r* gets a score for a specific day *d*, given the dinners already chosen this week *P*. Hard filters run first; the score then adds four positive components and subtracts two penalties, each scaled to 0–1.

```latex
S(r,d,P) = w_p\,\mathrm{Pref}(r) + w_d\,\mathrm{DayFit}(r,d) + w_r\,\mathrm{Due}(r) + w_e\,\mathrm{Explore}(r) - w_v\,\mathrm{VarietyPen}(r,P) - w_s\,\mathrm{SkipPen}(r) + B_{\mathrm{wish}}(r)
```

*B\_wish* is the wishlist bonus (0.10). Carried-over dinners are not scored: they are pinned into the plan, and DayFit only chooses their day.

**Hard filters** (the recipe is removed, not scored):

- Blocked or removed by the family, or vegetarian while *block vegetarian* is on.
- Rated 2★ or lower.
- Inside its recency cooldown, counted per dish group (see Due).
- Already chosen this week, or another recipe from the same dish group is.

### Pref: how much the family is expected to like it

```latex
\mathrm{Pref}(r) = \begin{cases} \dfrac{w_c\,\mathrm{Content}(r) + w_x\,x_r}{w_c + w_x} & \text{if rated} \\[6pt] \dfrac{\mathrm{Content}(r) + g\,\bar x_{\mathrm{sib}}}{1 + g} & \text{if unrated and the dish group has rated recipes} \\[6pt] \mathrm{Content}(r) & \text{otherwise} \end{cases} \qquad x = \frac{\mathrm{rating} - 1}{4}
```

- *w\_x* = 3 and *w\_c* = 1, so a rating counts for 75% and the content prediction for 25%. Two 4★ recipes are still ordered by how well they match the family's taste.
- *x̄\_sib* is the mean rating of the rated recipes in the same dish group, with weight *g* = 2.
- Onboarding answers count as ratings (Favourite = 5★, OK = 4★, Not for us = 2★) until the family confirms or changes them after cooking.

**Content(r)** is a weighted average of the family's learned affinities for the recipe's features, mapped to 0–1:

```latex
\mathrm{Content}(r) = 0.5 + 0.5 \sum_{t} \beta_t\, \bar a_t(r),\qquad \bar a_t(r) = \frac{\sum_{f \in F_t(r)} c_f\, a_f}{\sum_{f \in F_t(r)} c_f + \epsilon}
```

- *t* runs over the feature types, with weights β: main ingredients 0.30, protein 0.25, cuisine 0.20, meal type 0.15, carb base 0.10.
- *a\_f* is the affinity in \[−1, 1\] and *c\_f* its confidence (see Learning).
- Staple ingredients are excluded so they don't make every recipe look alike.

### DayFit: does it suit the day

| Profile | Days | DayFit | Soft time cap |
| --- | --- | --- | --- |
| `weekday_leftovers` (feeds tomorrow's lunch) | Sun–Thu | 0.7 · leftover\_score + 0.3 · (1 − effort/3) | 60 min Mon–Thu, 90 min Sun |
| `friday_treat` | Fri | 1.0 if a recipe has a tag in `dayfit.friday_tags`; otherwise 0.4 · festive\_score | – |
| `saturday_dinner` (nicer, few leftovers) | Sat | 0.6 · festive\_score + 0.4 · (1 − leftover\_score) | – |

Exceeding a soft cap multiplies DayFit by 0.3 rather than blocking the recipe.

### Due: favourites that are due again

With *t* = days since the dish group was last cooked and *T* = the cooldown for the recipe's rating:

```latex
\mathrm{Due}(r) = \min\left(1,\; \frac{t - T}{T}\right) \quad (t \ge T)
```

| Recipe state | Cooldown *T* | Due |
| --- | --- | --- |
| Rated 5★ | 14 days | Formula above |
| Rated 4★, or cooked but unrated | 21 days | Formula above |
| Rated 3★ | 42 days | Formula above |
| Rated in onboarding, never cooked | – | 1, so starting favourites appear early |
| Never cooked, not rated | – | 0 |

### Explore: a reason to try something new

```latex
\mathrm{Explore}(r) = 1 - \overline{\mathrm{conf}}(r)
```

Explore applies only to unrated recipes and rewards those whose features the engine knows little about. Its small weight makes it a tiebreaker toward novelty, not a push toward disliked flavours.

### VarietyPen: no repeats

The penalty adds up rule breaks against this week's chosen dinners and the last 7 days of `meal_log`, clamped to −1…1 after target bonuses. Weekly repeat limits and fish/vegetarian targets use the current Sunday–Saturday plan week; the previous seven days inform adjacency, while ingredient overlap compares dinners chosen for the plan:

| Rule | Limit | Penalty |
| --- | --- | --- |
| Same main protein as the day before or after | – | 0.5 |
| Same protein more than N times per week | N = 2 | 0.4 per extra |
| Same cuisine more than N times per week | N = 2 | 0.3 per extra |
| Same carb base more than N times per week | N = 3 | 0.2 per extra |
| Main-ingredient overlap (Jaccard) with a chosen dinner | > 0.5 | 0.3 |
| No fish yet this week, ≤ 2 days left | target 1 | −0.3 (bonus) |
| No vegetarian dish yet this week, ≤ 2 days left | target 1; off if vegetarian is blocked | −0.2 (bonus) |

### SkipPen: recently rejected

A replaced recipe gets SkipPen = 1, fading linearly to 0 over 14 days. A recipe swiped past in 5 separate sessions without being picked gets the same penalty over 30 days. SkipPen never touches ratings or affinities.

### Weights

| Weight | Component | Default |
| --- | --- | --- |
| *w\_p* | Pref | 0.45 |
| *w\_d* | DayFit | 0.25 |
| *w\_r* | Due | 0.15 |
| *w\_e* | Explore | 0.10 |
| *w\_v* | VarietyPen | 0.35 |
| *w\_s* | SkipPen | 0.30 |

Because every component uses the same 0–1 scale, each weight reads directly as how much that component matters.

## Learning

The engine learns from one signal: the family's star rating of a recipe. A rating sets that recipe's Pref directly and updates the family's affinity for every feature of the recipe, which spreads the lesson to recipes the family has never tried.

### One rating per recipe

The family rates a recipe once, the first time they cook it. Any member can answer or change a rating on the family's behalf, and no per-person attribution is stored.

| Situation | Day-after prompt | Stored |
| --- | --- | --- |
| First time cooked, unrated | Localised cooking confirmation and star picker | `household_recipe.rating`, `rating_source = user` |
| First time cooked, rated in onboarding | Localised cooking confirmation with onboarding stars pre-filled | Confirmed or changed rating, `rating_source = user` |
| Cooked again | Localised cooking confirmation with a change-rating link and current stars | Recency only |
| Rating changed from the recipe page or library | – | New rating replaces the old; old value kept in `rating_history` |

Each recipe contributes to the affinities exactly once, with its current rating, so a dish cooked 20 times doesn't dominate the taste profile.

### Onboarding

The family answers one joint quiz in about 5 minutes:

| Step | Question | Becomes |
| --- | --- | --- |
| 1 | Mark 30–40 common dishes, chosen from the seeded database (köttbullar, tacos, lax i ugn, carbonara, kycklinggryta…) as *Favourite* / *OK* / *Not for us* | That recipe's starting rating: 5 / 4 / 2★ (`rating_source = onboarding`); 2★ hides it |
| 2 | Rate cuisines 1–5 (husman, italienskt, tex-mex, asiatiskt, indiskt, mellanöstern…) | Cuisine affinity seed |
| 3 | Rate proteins 1–5 (kyckling, nöt, fläsk, fisk, skaldjur, vegetariskt, korv) | Protein affinity seed |
| 4 | Pick ingredients nobody likes (koriander, svamp, oliver…) | Ingredient affinity seed of −0.8 |

Seeds start with weight *W₀* = 2 and decay from `onboarding_answer.answered_at` with the same half-life as ratings. Thus recent ratings increasingly outweigh older quiz answers.

The saved answer keys are `dish_rating`, `cuisine_rating`, `protein_rating` and `disliked_ingredients`. Dish answers contain `ratings: [{recipe_id, choice}]`, with `choice` equal to `favourite`, `ok` or `not_for_us`. Cuisine and protein answers contain `ratings: [{code, rating}]`; disliked ingredients contain `ingredient_ids: [id]`. Reapplying onboarding synchronises only ratings whose source is still `onboarding`. It clears an omitted onboarding dish rating, recording the removal in `rating_history`, and never replaces a user rating.

### Affinity update

With *x* = (rating − 3) / 2 in \[−1, 1\], every feature *f* of a rated recipe is updated as a weighted running mean, after decaying its weight by age:

```latex
W_f \leftarrow W_f\cdot 2^{-\Delta t/H},\qquad a_f \leftarrow \frac{W_f\, a_f + \eta_t\, x}{W_f + \eta_t},\qquad W_f \leftarrow W_f + \eta_t,\qquad \mathrm{conf}_f = \frac{W_f}{W_f + k_0}
```

- η = 1.0 for protein, cuisine, meal type and carb base. For main ingredients, η = 1/√k, where *k* is the number of main ingredients.
- *H* = 365 days, measured from the date each rating was set. *k₀* = 3, so a feature reaches 50% confidence after about 3 ratings.
- The calculation reads current ratings in `rated_at`, then `recipe_id` order. Each rating's contribution is weighted by `η · 2^(-age/H)` at a single UTC calculation time. A seed contributes `W₀ · 2^(-age/H)`, with age measured from its answer time. The stored `weight_sum` gives confidence as `W/(W + k₀)`. Main ingredient IDs are distinct and staples are excluded. Tags are not learned until their feature weight is specified.

### Consistency

- **Immediate:** setting or changing a rating recomputes that family's affinities at once (milliseconds for a few hundred rated recipes).
- **Nightly rebuild:** a cron job rebuilds all affinities from `household_recipe` ratings and `onboarding_answer`. It is idempotent and is the source of truth, so config changes and corrected recipe features apply retroactively.
- **Recipe processing:** `process-recipes.php` runs the same all-household rebuild after normalisation, feature derivation and deduplication. A standalone rebuild command is available for operators; no separate affinity timer is installed yet.
- **Damping:** confidence weighting means a single rating can't swing a whole cuisine or protein.

## Weekly plan builder

The builder ranks recipes for every open day of a planning: the whole week, or one half in split mode. Its top choice per day becomes the first card of that day's swipe deck. It is deterministic for a given seed; reshuffling changes the seed.

1. **Set up:** read the days to plan, each day's profile, the favourite share, the active config version and any pending carry-overs. The seed is a hash of household, week, part and reshuffle count.
2. **Place carry-overs:** pinned dinners go first, each on the open day where its DayFit is highest, and count toward their kind's quota.
3. **Build pools:** push active, dinner, household status, rating, vegetarian and group-cooldown hard filters into SQL. On first preparation, retain the filtered shortlist in `plan_candidate` so later swipes do not scan the full catalogue. Database triggers on `household_recipe`, `household_setting`, `day_profile` and `carryover` increment `household.state_version` after inserts, updates and deletes, covering ratings, blocks or removals, wishlists, carry-overs and settings. The plan records the household and engine config versions used for its shortlist. Rebuild the shortlist automatically on either version mismatch, then recheck hard filters on each read. An eligible recipe chosen from search can join the shortlist.
   - *Favourites:* rated recipes with Pref ≥ 0.6 (about 3.4★) that pass the hard filters.
   - *Discovery:* unrated recipes that pass the hard filters: every wishlisted recipe, plus the top 300 others by Content.
4. **Set quotas:** favourites = round(N × share) for the week, discoveries for the rest. With 7 days at 70% that is 5 + 2. In split mode, the second half's quota is the week's target minus the favourites already chosen. If the favourites pool runs short, discoveries fill the gap.
5. **Order the days:** use the configured `builder.profile_order` for the most constrained profiles, so Fri and Sat go before the lunch-box days.
6. **Score each day:** score both pools against the dinners chosen so far. If one kind's remaining quota equals the remaining days, that kind is forced; otherwise the higher top score wins.
7. **Pick with controlled variation:** sample from the top 5 with a seeded softmax (temperature 0.05), so the best recipe usually wins but weeks still differ.
8. **Swap pass:** try exchanging every pair of open days and keep any swap that raises the total score, for at most 2 passes.
9. **Build decks:** for each day, rank the remaining candidates behind the top choice, one recipe per dish group, interleaving favourites and discoveries by the favourite share.

**Timing:** cron prepares the decks every Sunday at 07:00, and also every Wednesday at 07:00 in split mode, then sends a `planning_ready` event with date parameters. The app builds the localised notification text. Reshuffling or re-preparing keeps every day the family has already chosen and every carry-over, and redraws only open days.

**Performance:** first preparation scores a filtered pool from the full catalogue, then saves that pool. A pick reads only its planning's shortlist and re-ranks the open days; the target for this re-rank is under 100 ms. The generated-catalogue database benchmark creates 16,365 dinner candidates in a throwaway MySQL database and times a full plan and one pick plus re-rank, including queries and writes. Its `--readonly` mode measures queries and scoring with the existing recipe catalogue inside a read-only transaction that is rolled back. It uses in-memory default household settings when no household exists, or a specified household's settings and ratings; it keeps the shortlist in memory and does not measure plan writes. Both database modes report query times. The separate CPU benchmark excludes database time; none of the benchmarks includes HTTP network time.

## Choosing meals

The family doesn't approve a finished plan. It swipes through a ranked deck per day, and the engine re-ranks the other days after every pick.

### Planning modes

| Mode | Sunday 07:00 | Wednesday 07:00 |
| --- | --- | --- |
| Whole week | Choose Sun–Sat, or Sun–Thu | – |
| Split week | Choose Sun, Mon, Tue | Choose Wed–Sat, or Wed–Thu |

- Each planning starts with a `planning_ready` event and parameters such as `start_date` and `end_date`; the app renders the notification in the selected locale.
- The second half is scored against what was chosen and cooked in the first half, so variety and the favourite share hold across the week.
- An uncooked dinner from the first half moves to the second half's planning by default.

### The swipe deck

| Gesture | Meaning | Effect |
| --- | --- | --- |
| Swipe right | Pick | Recipe is chosen for the day; the other open days are re-ranked |
| Swipe left | Next | Card is hidden for this session only |
| Tap | Details | Full recipe, short reason and score breakdown |
| Undo | Undo | Brings back the last card |
| Autofill | Autofill | The engine's top card is chosen for every remaining open day |
| Reshuffle | Reshuffle | New seed for the open days |

- Each card shows title, image (linked from the source), cook time, a localised discovery badge and a reason rendered from server reason codes and parameters. A card for a dish group has a localised link to other variants.
- The server returns the top 20 cards per day for the whole planning in one call, and the app pre-loads images. After each pick, one call (under 100 ms) re-ranks the other days. The next 20 cards load if a deck runs out. All scoring stays on the server.
- Choose, replace, autofill and shuffle require the caller's expected plan version. Each successful operation increments `plan.version` in the same transaction as its slot changes. A stale version raises `plan_version_conflict` before any change.
- A recipe swiped past in 5 separate sessions without being picked gets a 30-day SkipPen. A single left swipe has no lasting effect.
- A search box lets the family choose any recipe in the database for a day. Hard filters still apply, and a warning shows if it breaks a variety rule.

### Replacing a chosen dinner

Replacing a chosen dinner opens that day's deck, re-scored against the rest of the week; the other days stay as they are. The replaced recipe gets a 14-day SkipPen. Ratings and affinities are untouched.

## After dinner

The day after each chosen dinner, the app renders a localised cooking confirmation prompt using the recipe title. A dinner that wasn't cooked is never counted as eaten or as disliked.

| Answer | Status | Recency and variety | Learning | Accuracy metrics |
| --- | --- | --- | --- | --- |
| Yes, first time (stars asked) | cooked | Cooldown starts; counts in the 7-day variety history | Rating updates Pref and affinities | Included |
| Yes, already rated | cooked | Same as above | None | Excluded |
| No → carry over (default) | not\_cooked | No cooldown; pinned into the next planning | None | Excluded |
| No → save for later | not\_cooked | No cooldown; added to the wishlist | None | Excluded |
| No → remove | not\_cooked | Status *removed*: out of all pools, still searchable | None | Excluded |
| No answer within 2 days | unconfirmed | Counted as eaten: cooldown starts, counts for variety | None | Excluded |

- **Carry-over:** the next planning is Wednesday's half in split mode, otherwise next week. If its days are already chosen, the app offers to swap the dinner in on the best-fitting day. A carry-over lasts one week; if it still isn't cooked, it moves to the wishlist.
- **Wishlist:** wishlisted recipes get the 0.10 bonus until cooked, so they come back within a few weeks without being forced.
- **Removed vs blocked:** *removed* means "not for us right now" and can be undone from search; *blocked* means "never show again".
- **Flags:** a recipe uncooked or replaced 3 or more times in 60 days is flagged in admin. It never gets a lower score automatically.

## Explainability

Each card shows one short reason built from its two largest positive components. Tapping it shows the full breakdown. The server returns English reason codes and structured parameters, never display text. Both the reasons and score components are saved in `explanation_json` with the slot when the dish is chosen, so the explanation always matches the decision. Proposed slots have no stored explanation. The app renders Swedish text from `app/lib/l10n/app_sv.arb` (or English from `app_en.arb`).

| Top component | Reason code | Example parameters |
| --- | --- | --- |
| Pref, rated | `rated_stars` | `{"stars": 5}` |
| Pref, unrated | `similar_rated_recipe` | `{"recipe_id": 123, "stars": 5}` |
| Pref, dish group | `liked_dish_group_variant` | `{"dish_group_id": 45}` |
| Due | `not_cooked_recently` | `{"weeks": 3}` |
| DayFit, lunch-box day | `good_for_leftovers` | `{}` |
| DayFit, Friday | `friday_treat` | `{}` |
| DayFit, Saturday | `saturday_dinner` | `{}` |
| Explore | `new_cuisine` | `{"cuisine_code": "indian"}` |
| Carry-over | `carried_over` | `{"from_date": "2026-11-11"}` |
| Wishlist bonus | `wishlisted` | `{}` |

For `similar_rated_recipe`, the engine identifies the rated recipe that shares the most weighted features; the app looks up and displays its title.

Example breakdown:

```
Recipe 123                       Total 0.68
  Pref             0.90 × 0.45 = +0.41   stars 5, protein +0.6, cuisine +0.4
  DayFit           0.90 × 0.25 = +0.23   leftover score 0.9, 40 min
  Due              0.50 × 0.15 = +0.08   cooked 21 days ago (cooldown 14)
  Explore          0.00 × 0.10 = +0.00
  VarietyPen      −0.10 × 0.35 = −0.04   second recipe with this protein this week
  SkipPen          0.00 × 0.30 = −0.00
```

The same data powers an admin view, "Why not recipe X on Tuesday?", showing which hard filter removed a recipe or how far it scored below the winner.

## Measuring accuracy

Each `plan_slot` stores the Pref predicted when the dish was chosen. When a recipe chosen while unrated gets its first rating, the engine logs the error between the predicted rating (1 + 4 × Pref) and the actual rating. An admin page tracks per month:

| Metric | Meaning | Should |
| --- | --- | --- |
| MAE | Mean absolute error between predicted and actual first ratings | Fall |
| Hit rate | Share of cooked dinners rated 4★ or higher | Rise |
| Discovery success | Share of discovery dinners rated 4★ or higher | Rise |
| Replace rate | Share of chosen dinners later replaced | Fall |
| Swipes to pick | Average cards swiped before a pick | Fall |
| Top-3 picks | Share of picks from the first 3 cards | Rise |

These metrics guide tuning of `engine_config`.

## Server-side configuration

Every engine number is a row in `engine_config`, so tuning is an admin-page change, never a code deploy. Values are cached per request and versioned; each plan records the aggregate `config_version` that built it. A config edit increments that key's version. The aggregate is the sum of per-key versions, so any increment causes saved planning shortlists to rebuild on the next access.

| Area | Key | Default | Meaning |
| --- | --- | --- | --- |
| App auth | `auth.access_ttl_minutes` / `auth.refresh_ttl_days` | 15 / 30 | Member access and rotating refresh lifetime |
| Household | `household.invite_ttl_hours` | 72 | One-time invite validity |
| API | `api.idempotency_ttl_hours` | 48 | Per-user retry result retention |
| Weights | `weight.pref` / `.dayfit` / `.due` / `.explore` | 0.45 / 0.25 / 0.15 / 0.10 | Positive component weights |
| Weights | `weight.variety` / `.skip` | 0.35 / 0.30 | Penalty weights |
| Pref | `pref.content_weight` / `pref.rating_weight` | 1 / 3 | Content vs rating in Pref |
| Pref | `pref.sibling_weight` | 2 | Weight of rated dish-group siblings for an unrated variant |
| Content | `content.beta.ingredients` / `.protein` / `.cuisine` / `.meal_type` / `.carb` | 0.30 / 0.25 / 0.20 / 0.15 / 0.10 | Feature-type weights |
| Content | `content.confidence_epsilon` | 0.000001 | Positive denominator for empty feature evidence |
| Learning | `affinity.seed_weight` | 2 | Weight of onboarding seeds |
| Learning | `affinity.conf_k0` | 3 | Ratings for 50% confidence |
| Learning | `affinity.half_life_days` | 365 | Age decay of rating evidence |
| Learning | `onboarding.rating.favourite` / `.ok` / `.not_for_us` | 5 / 4 / 2 | Starting dish ratings |
| Learning | `affinity.disliked_ingredient_score` | -0.8 | Disliked ingredient seed score |
| Filters | `filter.hide_at_or_below` | 2 | Ratings at or below this are hidden |
| Recency | `cooldown.days.5` / `.4` / `.3` | 14 / 21 / 42 | Cooldown by rating; unrated cooked recipes use the 4★ value |
| Day fit | `dayfit.weekday_max_minutes` / `dayfit.sunday_max_minutes` | 60 / 90 | Soft time caps on lunch-box days |
| Day fit | `dayfit.friday_tags` | fredagsmys, taco, pizza, burgare | Recipe tags that give `friday_treat` full DayFit |
| Day fit | `dayfit.leftovers.leftover_weight` / `.effort_weight` | 0.7 / 0.3 | Leftover and low-effort weekday DayFit contributions |
| Day fit | `dayfit.friday.festive_weight` | 0.4 | Festive contribution without a Friday tag |
| Day fit | `dayfit.saturday.festive_weight` / `.low_leftover_weight` | 0.6 / 0.4 | Saturday DayFit contributions |
| Day fit | `dayfit.softcap_factor` | 0.3 | DayFit multiplier when a cap is exceeded |
| Variety | `variety.max_protein_week` / `.max_cuisine_week` / `.max_carb_week` | 2 / 2 / 3 | Weekly repeat limits |
| Variety | `variety.pen.adjacent_protein` / `.protein` / `.cuisine` / `.carb` / `.overlap` | 0.5 / 0.4 / 0.3 / 0.2 / 0.3 | Penalty sizes |
| Variety | `variety.overlap_jaccard` | 0.5 | Ingredient-overlap threshold |
| Variety | `variety.target_fish` / `.target_veg` | 1 / 1 | Weekly targets |
| Variety | `variety.bonus.fish` / `.bonus.vegetarian` / `variety.target_days_left` / `variety.history_days` | 0.3 / 0.2 / 2 / 7 | Target bonuses, final days and recent meal window |
| Skips | `skip.replace_days` | 14 | SkipPen after replacement |
| Skips | `swipe.reject_sessions` / `swipe.reject_days` | 5 / 30 | Repeated left swipes before a SkipPen, and its length |
| After dinner | `confirm.auto_days` | 2 | Days without an answer before a dinner counts as eaten |
| After dinner | `carryover.expire_weeks` | 1 | How long a carry-over stays pinned |
| After dinner | `wishlist.bonus` | 0.10 | Score bonus for wishlisted recipes |
| After dinner | `flag.count` / `flag.days` | 3 / 60 | Uncooked or replaced this often → admin flag |
| Builder | `builder.fav_threshold` | 0.6 | Pref needed to count as a favourite |
| Builder | `builder.default_favourite_share` / `builder.default_plan_days` | 0.7 / 7 | Household planning defaults |
| Builder | `builder.plan_days.all_seven` / `builder.plan_days.sunday_thursday` | 7 / 5 | Number of planned days for each setting code |
| Builder | `builder.profile_order` | friday_treat, saturday_dinner, weekday_leftovers | Most constrained day profiles first |
| Explanation | `explain.similar_rating_min` | 4 | Lowest rating used for a similar-rated-recipe reason |
| Builder | `builder.top_k` / `builder.temperature` | 5 / 0.05 | Controlled variation |
| Builder | `builder.discovery_prefilter` | 300 | Discovery candidates scored per planning |
| Builder | `builder.swap_passes` | 2 | Local improvement passes |
| Builder | `builder.generate_time` / `builder.split_day` | 07:00 / wednesday | Planning time; first day of the second half in split mode |
| Deck | `deck.size` | 20 | Cards loaded per day |
| Dedupe | `dedupe.auto_merge` / `.review` / `.group_overlap` | 0.95 / 0.80 / 0.5 | Thresholds for merging copies and grouping variants |
| Dedupe | `dedupe.time_tolerance_min` / `.servings_tolerance` / `.report_top_groups` | 30 / 2 / 10 | Copy metadata tolerance and report size |
| Dedupe | `dedupe.source_priority` | koket, ica, arla, coop | Tie-breaker for the kept copy |
| Crawl | `crawl.delay_s` / `crawl.recheck_share` | 5 / 0.01 | Seconds between requests per site; nightly re-check share |
| Crawl | `crawl.retry_base_s` / `crawl.retry_max_s` / `crawl.max_attempts` | 60 / 3600 / 5 | Exponential retry base, delay cap and maximum attempts; a longer server `Retry-After` takes precedence over the cap |
| Crawl | `crawl.timeout_s` | 30 | HTTP request timeout in seconds |
| Feature | `feature.main_categories` / `.main_min_mass_g` | protein, legume, vegetable, grain, dairy, fruit / 100 | Main-line eligibility and unmatched mass threshold |
| Feature | `feature.leftover_dish_weight` / `.leftover_method_weight` / `.leftover_protein_weight` | 0.6 / 0.3 / 0.1 | Weighted leftover signals |
| Feature | `feature.leftover_baseline` / `.leftover_scale` / `.leftover_cut_up_signal` | 0.5 / 0.5 / 0.5 | Neutral score, signal scale and cut-up protein override |
| Feature | `feature.leftover_long_cook_min` / `.leftover_long_cook_signal` | 45 / 1 | Step duration threshold and long-cook method signal |
| Feature | `feature.festive_time_full_min` / `.festive_time_weight` / `.festive_premium_weight` / `.festive_tag_weight` | 120 / 0.4 / 0.35 / 0.25 | Festive score inputs |
| Feature | `feature.fest_tag_bonus` | 0.2 | One capped bonus for the seeded Fest occasion tag |
| Feature | `feature.effort_easy_time_min` / `.effort_easy_ingredient_count` / `.effort_hard_time_min` / `.effort_hard_ingredient_count` | 30 / 8 / 90 / 15 | Effort buckets |
| Feature report | `feature.report_sample_size` / `.report_sample_seed` / `.report_leftover_buckets` | 20 / matsvep-feature-pilot / 10 | Repeatable spot check and histogram |
| Feature report | `feature.report_ranked_size` | 10 | Dinner candidates at each end of the leftover ranking |
| Feature | `feature.baking_sugar_flour_share` | 0.5 | Minimum food-line share for the combined sugar and flour baking signal |
| Feature | `feature.sweet_pie_sugar_share` | 0.25 | Minimum food-line sugar share that makes a pie sweet |
| Feature report | `feature.report_dinner_audit_size` | 30 | Removed and remaining dinner title sample size |
| Normalise | `normalise.suggestion_min_similarity` / `.suggestion_min_affix_length` | 0.82 / 4 | Minimum Unicode edit similarity for a review suggestion; minimum characters in a compound head or prefix suggestion |

**Family settings** (in the app): favourite share (default 70%), plan days (all 7 or Sun–Thu), planning mode (whole week or split) and block vegetarian (off). The weekday-to-profile map lives in `day_profile`; a new profile, such as `guests`, needs one new DayFit rule plus config rows.

## System architecture

The server is a plain Linux setup on one Ubuntu 26.04 LTS VPS: Apache 2.4, PHP 8.5 with PHP-FPM, and MySQL 8.4, with no containers. The engine is plain PHP classes with no web dependencies, so the same code runs from API requests and scheduled CLI jobs. Only the scheduled crawler talks to recipe sites, so a slow or blocked site never affects the app. Setup installs the crawler but leaves the Köket source and crawl timer disabled until the operator explicitly enables them. The first full crawl starts at 01:00 local time on the next night, runs for up to 20 hours, and has a 21-hour service hard limit; missed runs are skipped. Unattended security updates are enabled, while switching SSH to key-only login remains an operator action after verifying a second session. A separate 22:30 timer makes a consistent gzipped MySQL backup and keeps fourteen days locally. The operator copies backups off the server. See `docs/deployment.md` for setup, updates, monitoring, restore and secret rotation.

Numbered SQL files in `server/migrations` are applied by the PHP CLI runner. Its `000_schema_migrations.sql` bootstrap creates `schema_migrations`; each later file is recorded with a checksum and applied timestamp. The runner skips matching files and stops if a recorded file changed or a previous run stopped partway through a migration. MySQL DDL can commit before a migration finishes, so an interrupted migration requires manual repair before retrying.

```
Windows + Android apps (Flutter)
        |  HTTPS /api/v1
        v
+----------------------- Linux VPS: Apache + PHP 8.5 ------------------------+
|  JSON API + admin  -->  Recommendation engine        Cron jobs (PHP CLI)    |
|  decks, picks,          hard filters + scoring        crawl sources nightly  |
|  ratings, onboarding,   plan builder + swap pass      rebuild affinities 03:00|
|  search, admin pages    swipe decks + reasons         decks Sun + Wed 07:00  |
+---------------------------------------------------------------------------+
        |                        |                          |          |
        v                        v                          v          v (polite crawl)
                     MySQL 8.4 (recipes, history, affinities, config)   Recipe sites
                                                                       (Köket, ICA, Arla, Coop)
```

**Code layout** (PHP, Composer autoload, PSR-4):

| Module | Responsibility |
| --- | --- |
| `Engine\Filters` | Hard filters as SQL `WHERE` clauses plus PHP checks |
| `Engine\Scorer` | The six components and the total for one recipe and day |
| `Engine\PlanBuilder` | Pools, quotas, day order, seeded sampling, swap pass, swipe decks |
| `Engine\Learner` | Immediate affinity update and nightly rebuild |
| `Engine\Explainer` | Short reasons and breakdown JSON |
| `Engine\Config` | Loads `engine_config` once per request, typed and versioned |
| `Ingest\Adapter\*` | One class per site: discover, fetch, parse |
| `Ingest\Normaliser` | Ingredient aliases and feature derivation |
| `Ingest\Dedupe` | Copy merging and dish groups |
| `Api\*` | Thin JSON controllers with no business logic |

**API** (JSON, versioned under `/api/v1`, described in `openapi.yaml`):

| Endpoint | Purpose |
| --- | --- |
| `POST /auth/token`, `POST /auth/refresh` | Device login and token refresh |
| `POST /onboarding` | Submit the family quiz |
| `GET /plans/{week}` | Chosen dinners for a week |
| `GET /plans/{week}/{part}/deck` | Swipe decks for the open days of a planning |
| `POST /swipes` | Log swipes |
| `PUT /slots/{id}` | Choose or replace a dinner; re-ranks the other open days |
| `POST /plans/{week}/{part}/autofill` | Fill the remaining days |
| `POST /plans/{week}/{part}/shuffle` | Reshuffle the open days |
| `POST /slots/{id}/cooked` | Day-after answer: yes, or no with a choice |
| `PUT /recipes/{id}/rating` | Set or change a rating |
| `GET /recipes/search?q=` | Search the recipe database |
| `POST /wishlist`, `DELETE /wishlist/{id}` | Wishlist |
| `GET /settings`, `PUT /settings` | Family settings |

Admin pages (review queues, feature corrections, config, metrics, debug views) are server-rendered PHP behind admin login.

**Clients:** one Flutter codebase builds native apps for Windows (desktop) and Android. Flutter is the chosen framework; the alternatives below apply only if a blocker appears.

- **Auth:** a 15-minute access token plus a refresh token per device, hashed in `device_token`.
- **Offline:** each app caches the current week's chosen dinners with full recipes. Day-after answers and ratings are queued and sent when online.
- **Reminders:** the server sends only event codes and structured parameters (for example, `planning_ready` with `start_date` and `end_date`, or `dinner_confirmation_due` with `recipe_id`). The app builds the notification text from its localisation files. Delivery uses Firebase Cloud Messaging on Android and local scheduled notifications on Windows.
- **Server-side logic:** all scoring runs on the server, so every device sees the same plan.
- **Distribution:** Android as a signed APK or a private Play track; Windows as an MSIX installer.

| Client option | Windows | Android | Assessment |
| --- | --- | --- | --- |
| **Flutter** | Native desktop (stable) | Native | Chosen: one codebase, mature, good offline storage (SQLite via *drift*) |
| .NET MAUI | WinUI 3 | Native | Viable for a C# preference; smaller ecosystem for this kind of app |
| Separate apps (WinUI + Kotlin) | Native | Native | Best platform fit, but twice the work |

## Seed database and persona simulation

The recipe database is filled and the engine is proven on simulated families before any client work starts. At run time the engine needs no network calls and no external services.

### Seeding the database

- **Windows pilot:** Before the full crawl, run a repeatable 500-URL sample from the locally saved sitemap in a separate `*_pilot` database. Sample root-level and nested paths in their sitemap proportions with a fixed seed. The operator runs the script; development and tests use saved fixtures and fake HTTP responses only. The script resumes the existing queue, fetches batches of 50, and writes a content-free report of statuses, recipe shares, non-recipe path prefixes, failures and pending retries. If only retries more than five minutes away remain, it writes the report and stops; the next run resumes them. The observed pilot classified 448 of 499 reachable pages as recipes, 51 as non-recipes, and one as gone; 134 fetched recipe URLs had nested paths. The report can be rerun offline against the pilot database to count nested URLs with root-level canonical URLs and measure total-time, serving and image coverage plus ingredient and step counts.
- **Full first crawl:** The saved Köket sitemap contains 41,747 `<url>` entries, all with `lastmod`. Excluding 241 known non-recipe section URLs leaves about 41,506 possible recipe URLs (30,176 root-level and 11,330 nested). This is a URL-pattern upper bound: root-level chef pages and other non-recipe pages are classified after fetching. Neither of the two saved recipe pages appears in this sitemap, so it is broad but incomplete. Use the live sitemap as the primary discovery route on the production Linux VPS and supplement it with category pages to find omissions. At one request every 5 seconds, fetching all 41,506 sitemap candidates requires at least about 58 hours, plus discovery, backup discovery and processing time. The operator or nightly systemd service runs `php bin/crawl-koket.php crawl --hours 20`. The command discovers when the queue is empty or the last completed sitemap discovery is older than `crawl.discovery_max_age_days` (default seven). The database marker is written only after successful discovery; an interrupted discovery repeats safely. Fetching uses `crawl.batch_size` (default 50) and ends at the time limit or when only retries beyond `crawl.max_retry_wait_s` (default 300) remain. Each run atomically writes a content-free report to `server/var/crawl-report.json`; later runs resume queued URLs and due retries.
- **Canonical identity:** A recipe's primary URL comes from the page's canonical link, falling back to JSON-LD `url` and then the fetched URL. Only same-host canonical URLs are accepted. Both the canonical URL and any fetched alias are stored in `recipe_source_link`. A queued URL already linked to a recipe is marked `duplicate` without another request. A newly encountered alias still needs one fetch to discover its canonical URL. The report counts nested fetched URLs whose canonical recipe URL is root-level; for the original pilot database, it reads saved raw snapshots to compute this without refetching. The `duplicate` status is terminal and excluded from the fetched-page recipe share.
- **Raw snapshots:** `recipe_raw` stores only the JSON-LD `Recipe` object when parsing JSON-LD, compressed as JSON. A page parsed with the HTML fallback keeps its compressed full HTML for later reparsing. Earlier pilot snapshots may still contain full HTML with `format = jsonld`; reports can read those legacy snapshots without refetching.
- **Clean-up pass:** work through the unknown-ingredient queue and the duplicate review queue, and spot-check protein, cuisine and leftover tags on about 200 recipes. Fixes go into synonym and keyword lists, not individual recipes. This is the main manual effort of the phase, and tag quality directly drives suggestion quality.
- **Snapshot:** a compressed dump of the seeded database is the fixed dataset for all simulations and golden tests. It contains collected recipes, so it is kept in private storage only.

### Simulated families

Each persona has a hidden "true taste" the engine never sees: weights for cuisines, proteins and ingredients, plus some randomness. A headless simulator plays it week by week through the real API. It opens decks, swipes and picks according to its taste, sometimes answers "not cooked", and rates first-time dishes.

| Persona | Hidden taste | What it tests |
| --- | --- | --- |
| Husman family | Loves köttbullar, pytt i panna, fläskpannkaka; little interest in Asian food | Favourites dominate without repetition |
| Tex-Mex fans | Tacos and chili top marks; fish mostly disliked | Fish target vs strong dislike; Friday logic |
| Picky kids | Few accepted dishes, many 2★ | Small pool, hard filters, decks never run empty |
| Adventurous foodies | Likes almost everything new | Exploration and discovery success |
| Meat only | Block vegetarian on | Vegetarian target switches off |
| Chaotic week | Often doesn't cook, replaces a lot, split mode | Carry-overs, split halves, cooldowns, unconfirmed dinners |

Each persona runs 52 simulated weeks in seconds, checked automatically:

- **Learning:** MAE and swipes-to-pick fall over the year.
- **Rules:** no variety rule is broken, no dish group appears inside its cooldown, and a 2★ dish never returns unless its rating changes.
- **Day logic:** the average leftover\_score is clearly higher Sun–Thu than on Saturday.
- **Health:** no recipe dominates the year, and no deck runs empty.

An admin page and a CLI command print any persona's weeks with score breakdowns. Default settings are tuned here, on simulated data, before the family starts using the app.

## Building with an AI coding agent

The code is written by an AI coding agent, such as Claude Code or OpenAI Codex. Use one as the main agent so conventions stay consistent. AI is used only to build the app and to help prepare data during development; the app itself contains no AI.

**Repository**

```
/docs/design.md        ← this document in Markdown (source of truth)
/docs/decisions/       ← a short note for every later design change
/server/               ← PHP 8.5: src/Engine, src/Ingest, src/Api, migrations/, tests/
/server/simulator/     ← personas and the headless simulator
/app/                  ← Flutter app for Android and Windows
/api/openapi.yaml      ← API contract shared by server and app
CLAUDE.md / AGENTS.md  ← instructions the agent reads on every task
```

**Instruction file:** Claude Code reads `CLAUDE.md` and Codex reads `AGENTS.md`; keep one file under both names. It states:

- What the app is, in 5 lines, with a pointer to `/docs/design.md`.
- Versions: PHP 8.5, MySQL 8.4, Apache, Flutter stable, Linux server.
- Commands: install, test, lint, run the simulator.
- Hard rules:
  - No AI or ML libraries in the app.
  - Every tunable number comes from `engine_config`.
  - No business logic in API controllers.
  - Prepared statements only.
  - Schema changes only through numbered migrations.
  - Every engine change has tests.
- Definition of done: tests and linter pass, `openapi.yaml` is updated, and `/docs/design.md` is updated if behaviour changed.

**Workflow**

1. **Contract first:** the agent writes the migrations and `openapi.yaml` before feature code, so server and app build against the same contract.
2. **Small tasks:** each task covers a few hours of work, such as "implement DayFit with tests". It names the doc section, the acceptance criteria and the files it may touch. The agent writes a plan, which you approve before coding. One branch and pull request per task.
3. **Tests the agent runs itself:**
   - Engine unit tests (PHPUnit) for every component, with expected numbers from this document.
   - Golden plan tests: a fixed family, the seed snapshot and a fixed seed produce an exact checked-in result.
   - The persona simulator and its automatic checks.
   - Parser tests on 3–5 saved pages per site, so the agent never hits live sites.
   - `flutter test` for app logic, plus one widget test per main screen.
4. **Automatic checks on every pull request** (e.g. GitHub Actions): PHPUnit, PHPStan at a strict level, PHP-CS-Fixer, `flutter analyze` and `flutter test`. Merge only when green and reviewed.

**Guardrails**

- The agent never gets production credentials or SSH keys; secrets live in an uncommitted `.env`.
- Deploys run through a script you trigger yourself: `git pull`, `composer install`, migrations and an Apache reload.
- Review every change to weights, migrations and crawler politeness settings personally.
- Design changes get a note in `/docs/decisions/` and an update to `design.md`.

**First prompt:** *"Read /docs/design.md. Propose the database migrations and openapi.yaml for Roadmap phase 1. Don't write feature code yet; list any questions about the design first."*

## Path to a public service

The data model already supports many households; the blocker for a public launch is recipe rights.

- **Content rights:** Köket and ICA allow private, non-commercial use only. A public version needs written agreements, or sources without an agreement switch to `display_mode = index_only` (title, ingredients, link).
- **Text and data mining:** Köket states "Do not mine us". Under the EU DSM directive, such an opt-out can restrict commercial text and data mining, so it is treated as binding for a public service.
- **GDPR:** household data is personal data. A public version needs a privacy policy, data export, account deletion and EU hosting.
- **Scaling:** one VPS serves thousands of households, since a planning takes under 100 ms. Next steps are a job queue for cron work, a MySQL read replica and nightly pre-computed Content per household.
- **Security:** `password_hash()`, CSRF protection on admin pages, prepared statements everywhere, HTTPS via Let's Encrypt and rate-limited login.

## Roadmap

The engine is proven on real recipes and simulated families before the apps are built.

1. **Foundation and seed data (2–3 weeks):** VPS setup, recipe and ingestion schema (including `ingest_url`, `ingredient_unknown`, dish groups, duplicate review and `engine_config`), the Köket adapter, the full first crawl (which also establishes the size of Köket's catalogue), normaliser, deduplication and dish groups, the lock-protected production processing pass, the clean-up pass and the database snapshot. Household, planning, swipe and app auth tables are created in phase 2; the minimal admin user table is created in phase 1 and extended later. The phase 1 API contract covers shared recipe/source/ingredient/dish-group schemas and `GET /recipes/{id}`, `GET /recipes/search` and `GET /health`; the remaining `/api/v1` contract starts phase 2.
2. **Engine, headless (2–3 weeks):** filters, scoring, plan builder, swipe decks, planning modes, replace, after-dinner answers, carry-overs, reasons and onboarding, exposed through `/api/v1` with token auth.
3. **Simulation and tuning (2 weeks):** simulator, six personas, choosing the 30–40 onboarding dishes from the seeded database (an admin list of the most common dish groups across sites), automatic checks, nightly rebuild, metrics page, config admin and the "why not X" view.
4. **Client apps (3–4 weeks):** the Flutter app for Android and Windows: onboarding, swipe decks, week view, offline recipe view, after-dinner prompt, ratings, search, wishlist and settings.
5. **More sources (ongoing):** ICA, Arla and Coop adapters and the source re-check.
6. **Public readiness (later):** source agreements, GDPR features and hardening.

Durations assume one part-time developer working with an AI coding agent. All weights and thresholds are starting points, tuned in phase 3.

## Sources

- [Köket.se robots.txt](https://www.koket.se/robots.txt) and [sitemap](https://www.koket.se/sitemap.xml)
- [Köket.se user terms](https://www.koket.se/kokets-anvandarvillkor)
- [ICA.se robots.txt](https://www.ica.se/robots.txt)
- [ICA Gruppen user terms](https://icagruppen.se/installningssida/anvandarvillkor)
- [Arla.se robots.txt](https://www.arla.se/robots.txt)
- [Coop.se robots.txt](https://www.coop.se/robots.txt)
- [Lawline: Are recipes protected by copyright?](https://lawline.se/answers/skyddas-recept-av-upphovsratten)
- [Livsmedelsverket food composition data](https://www.livsmedelsverket.se/en/about-us/open-data/food-composition-data/)
- [Arla.se footer and policies](https://www.arla.se/)
- [Coop customer-account terms](https://www.coop.se/Globala-sidor/villkor/anvandarvillkor/)

## Phase 1 admin cleanup

The cleanup interface is a server-rendered PHP site at `/admin/` on the main HTTPS domain. The front controller checks the connecting IP against an app-local allowlist before any session starts; a missing or empty file denies access. Access also uses an admin account, account lockout, a Secure session cookie, session timeout and CSRF token. The `user` table is introduced early for this admin account; household and app authentication remain phase 2 work. Every admin mutation writes an `admin_audit` row with actor, time and before/after values.

Ingredient resolutions persist in `ingredient_unknown` and aliases and trigger normalisation of affected recipes. Duplicate decisions persist in `duplicate_candidate`; merged copies remain inactive and retain their raw snapshots. Feature corrections live in `recipe_feature_override` and are reapplied after each derivation. Manual group memberships use `recipe.dish_group_manual` so automatic regrouping cannot undo a split or merge. The overview shows ingredient match share, the dinner-candidate protein gap, candidate count and queue sizes. Admin login, lock, session, password and page-limit settings are stored in `engine_config` under `admin.*`.
