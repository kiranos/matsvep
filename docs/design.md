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
- **Simple stack:** PHP 8.3, MySQL 8 and Apache on one Linux VPS with cron, plus native Windows and Android apps built with Flutter.

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
| Day profiles | Sun–Thu dinners feed the next day's work lunch (batch-friendly dishes, ≤ 60 min Mon–Thu, ≤ 90 min Sun); Fri is fredagsmys; Sat is a nicer dish with few leftovers |
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
| [Köket.se](https://www.koket.se/) (TV4) | `sitemap.xml` (about 500 recently changed pages) plus recipe category pages under `/recept` | `Allow: /` for all agents | [Private use only](https://www.koket.se/kokets-anvandarvillkor); no public redistribution; copying for private use allowed; page footer says "Do not mine us" | First source |
| [ICA.se](https://www.ica.se/robots.txt) | `/recept/sitemaps/` | Open for normal agents; deep recipe paths blocked for AI crawlers | [ICA Gruppen terms](https://icagruppen.se/installningssida/anvandarvillkor): private, non-commercial use; no republishing without written consent | Later source |
| [Arla.se](https://www.arla.se/robots.txt) | `sitemap.index.xml` | Recipe pages allowed; search, `/recept/?` queries and collection sub-pages blocked | No published site terms; the footer links only to privacy and cookie policies (checked 2026-11-15). Swedish copyright law applies | Later source; OK for private use |
| [Coop.se](https://www.coop.se/robots.txt) | `sitemap.xml` | Recipe pages allowed; filtered and sorted lists blocked | Only customer-account terms, which reserve Coop's rights in its digital channels; no recipe-specific terms (checked 2026-11-15) | Later source; OK for private use |
| Livsmedelsverket food database | Open API, CC BY 4.0 | – | Attribution required | Optional, for a Swedish ingredient vocabulary |

Under Swedish law, ingredient lists and plain method steps usually lack *verkshöjd* (originality), so they aren't covered by copyright; photos and descriptive text are ([Lawline](https://lawline.se/answers/skyddas-recept-av-upphovsratten)). Copying for private use is also allowed under the Swedish copyright act (2 kap. 12 § URL). Storing full recipes for private family use is therefore fine for all four sites, as long as photos are only linked. This is guidance, not legal advice.

### Ingestion pipeline

Ingestion runs from cron, never on a user request.

1. **Discover:** read each enabled source's sitemap and category pages and queue new or changed recipe URLs.
2. **Fetch politely:** obey robots.txt, use an identifying User-Agent, send at most 1 request every 5 s per site, cache ETag and Last-Modified, and back off on 429 or 5xx responses.
3. **Parse:** read the schema.org `Recipe` in `application/ld+json` first, and fall back to the adapter's CSS selectors for name, ingredients, steps, time, yield and category. Keep a compressed raw snapshot so recipes can be re-parsed when a parser improves.
4. **Normalise:** map ingredient lines to canonical ingredients through a Swedish synonym table ("kycklingfilé", "kycklinglårfilé" → *kyckling*). Unknown words go to an admin review queue.
5. **Derive features:** main protein, carb base, cuisine, meal type, vegetarian flag, effort, festive score and leftover score (see Data model).
6. **Deduplicate and group:** merge copies and link variants of the same dish (below).
7. **Check sources:** a nightly job re-fetches about 1% of recipes and marks any that return 404 as `source_gone`. Those recipes stay fully usable.

### Duplicates and dish groups

| Case | Example | Handling |
| --- | --- | --- |
| **Copy:** the same recipe in two places | An Arla recipe republished on Köket, or a recipe moved to a new URL | Merged into one recipe; every source URL is kept as a link |
| **Variant:** different recipes for the same dish | 12 köttbullar recipes across four sites | Kept as separate recipes, linked in one *dish group* |

Detection:

1. **Same site:** canonical URL, JSON-LD `@id` and redirects catch moved pages.
2. **Copies across sites:** normalised titles and main-ingredient sets are compared (Jaccard similarity). At ≥ 0.95 with similar time and servings, recipes merge automatically; between 0.80 and 0.95 they go to an admin review queue. The most complete parse is kept, with ties broken by a source priority setting.
3. **Variants:** the title is reduced to its core dish by removing words like *klassiska*, *mormors*, *enkla*, *snabba* and side dishes after "med". The same core dish, the same main protein and ≥ 0.5 ingredient overlap put recipes in one dish group. Admin can split or merge groups.

In the engine, a dish group means:

- **Recency per dish:** cooking any köttbullar recipe puts every köttbullar recipe on cooldown.
- **One per week:** two recipes from the same group are never chosen in the same week.
- **One card per dish:** a deck shows only the best recipe of each group, with an *Andra varianter* link.
- **The family's version wins:** once the family rates a variant 4★ or higher, that version represents the group.
- **Siblings inform new variants:** an unrated variant uses its rated siblings as a strong prior (see Pref); ratings themselves are never copied.

### Storage rules, search and add

- Images are linked by URL and never downloaded. Every recipe view shows the source name and a link.
- A per-source `display_mode` (*full* / *index\_only*) can hide steps for a source, with no schema change.
- The family searches the local database with MySQL `FULLTEXT` on title and ingredients, filtered by source. *Lägg till* puts a recipe on the family's *Vill laga* (want to cook) list.

## Data model

The model has three layers: the **recipe database**, shared by all households; **household state**, which holds each family's choices, ratings and learned taste; and **engine config**, which holds every tunable number. Learned taste is stored as plain affinity rows, so the engine's memory can be read and debugged in SQL.

**Recipe database**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `source` | id, name, base\_url, adapter, enabled, crawl\_delay\_s, last\_crawl\_at, display\_mode | One row per recipe site |
| `recipe` | id, source\_id, url, title, image\_url, total\_time\_min, servings, cuisine, meal\_type, main\_protein, carb\_base, is\_vegetarian, leftover\_score (0–1), festive\_score (0–1), effort (1–3), dish\_group\_id, source\_status (ok / source\_gone), parsed\_at, parser\_version, active | Parsed recipe and derived features |
| `recipe_ingredient` | recipe\_id, position, group\_name, raw\_text, quantity, unit, ingredient\_id, is\_main | Ingredient lines with amounts; also the feature vector |
| `recipe_step` | recipe\_id, position, group\_name, text | Cooking steps |
| `recipe_tag` | recipe\_id, tag | Tags such as *taco*, *gryta*, *ugn*, *fredagsmys* |
| `recipe_source_link` | recipe\_id, source\_id, url, is\_primary | All source URLs of a merged recipe |
| `recipe_raw` | recipe\_id, fetched\_at, etag, format (jsonld / html), payload\_gz | Compressed raw snapshot for re-parsing |
| `ingest_url` | id, source\_id, url, url\_hash (unique per source), discovered\_via (sitemap / category), status (queued / fetched / failed / skipped\_robots / gone), etag, last\_modified, http\_status, attempts, next\_attempt\_at, last\_fetched\_at, recipe\_id (nullable) | Resumable discovery and fetch queue, conditional requests and retry state |
| `ingredient` | id, canonical\_name, category, is\_staple | Canonical vocabulary; staples (salt, oil, onion) are ignored in similarity |
| `ingredient_alias` | alias, ingredient\_id | Swedish synonym map |
| `ingredient_unknown` | id, raw\_text, normalised\_token (unique), occurrences, first\_seen\_at, status (pending / mapped / staple / ignored), suggested\_ingredient\_id (nullable), mapped\_ingredient\_id (nullable) | Review queue for ingredient tokens the normaliser cannot map |
| `leftover_keyword` | keyword, signal\_type (dish\_type / method / protein\_form), value (−1 to 1) | Keyword lists behind leftover\_score |
| `dish_group` | id, core\_name, main\_protein, manual\_override | Variants of the same dish |
| `duplicate_candidate` | recipe\_a, recipe\_b, similarity, status (pending / merged / not\_duplicate) | Admin review queue for possible copies |

**Household state**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `household` | id, name, size, created\_at | The family; size is informational |
| `user` | id, household\_id, email, password\_hash, role | Logins; all members share one household state |
| `device_token` | user\_id, platform (android / windows), refresh\_token\_hash, push\_token, last\_seen\_at | App logins and push notifications |
| `household_setting` | household\_id, key, value | Favourite share, plan days, planning mode, block vegetarian |
| `day_profile` | household\_id, weekday, profile | Weekday to profile, e.g. Fri → *fredagsmys* |
| `household_recipe` | household\_id, recipe\_id, status (library / removed / blocked), wishlist, rating (1–5, nullable), rating\_source (onboarding / user), rated\_at, last\_cooked\_at, times\_cooked | The family's relation to each recipe |
| `rating_history` | household\_id, recipe\_id, old\_rating, new\_rating, source, changed\_at | Audit trail of rating changes |
| `affinity` | household\_id, feature\_type (ingredient / cuisine / protein / carb / meal\_type / tag), feature\_value, score, weight\_sum, updated\_at | Learned taste per feature |
| `onboarding_answer` | household\_id, question\_id, answer | Raw quiz answers, so starting ratings and seeds can be recomputed |
| `plan` | id, household\_id, week\_start (Sunday), part (1 / 2), days, seed, status, config\_version | One planned week, or one half in split mode |
| `plan_slot` | id, plan\_id, date, day\_profile, recipe\_id, chosen\_by (engine / family), status (proposed / chosen / cooked / not\_cooked / unconfirmed), kind (favourite / discovery), score, predicted\_pref, explanation\_json, replaced\_from\_recipe\_id | One dinner and why it was chosen |
| `carryover` | household\_id, recipe\_id, from\_slot\_id, target\_week, target\_part, status (pending / placed / expired) | Uncooked dinners pinned into the next planning |
| `slot_skip` | household\_id, recipe\_id, reason (replaced / swipe\_rejects), skipped\_at, expires\_at | Source of SkipPen |
| `swipe_event` | household\_id, plan\_id, date, recipe\_id, position, action (pick / next / undo), session\_id, created\_at | Every swipe; feeds swipe metrics and repeated-reject logic |
| `meal_log` | id, household\_id, recipe\_id, cooked\_on, plan\_slot\_id | Dinners counted as eaten; drives recency and variety |
| `engine_config` | key, value, type, description, version | Server-side settings |

**Operational metadata**

| Table | Key columns | Purpose |
| --- | --- | --- |
| `schema_migrations` | migration, checksum, status (started / applied), started\_at, applied\_at | Records numbered SQL files and detects changes or interrupted application |

**Derived recipe features**, set by rules during ingestion and correctable per recipe in admin:

- **leftover\_score (batch-friendliness):** how naturally the dish makes many portions, judged by the type of dish, not by servings. Stews, bönchili, bolognese, köttfärssås, boeuf bourguignon, soups, curries, gratins and lasagne score high. Dishes built from individual pieces, such as kycklingbröst, fiskfilé, biff or fläskkotlett, score low. Three keyword signals combine:
  - **Dish type (weight 0.5):** title and tags, e.g. *gryta*, *ragu*, *soppa*, *lasagne*, *gratin*, *curry*, *chili*.
  - **Cooking method (0.3):** steps mentioning slow or one-pot cooking, e.g. *puttra*, *långkok*, *sjud*.
  - **Protein form (0.2):** köttfärs, grytbitar, högrev or baljväxter score high. Whole pieces cooked one by one (bröstfilé, filé, kotlett, biff) score low. The same protein cut up and cooked in a sauce ("i bitar", "strimlad" or "tärnad" in a gryta or one-pan dish, e.g. kycklinggryta) scores high, because the test is one big pan versus frying many pieces.
- **festive\_score:** high for longer cook times, premium proteins (oxfilé, lamm, skaldjur) and the tags *helg* or *fest*.
- **effort:** a 1–3 bucket from total time and ingredient count.

## Scoring

Every candidate recipe *r* gets a score for a specific day *d*, given the dinners already chosen this week *P*. Hard filters run first; the score then adds four positive components and subtracts two penalties, each scaled to 0–1.

```latex
S(r,d,P) = w_p\,\mathrm{Pref}(r) + w_d\,\mathrm{DayFit}(r,d) + w_r\,\mathrm{Due}(r) + w_e\,\mathrm{Explore}(r) - w_v\,\mathrm{VarietyPen}(r,P) - w_s\,\mathrm{SkipPen}(r) + B_{\mathrm{wish}}(r)
```

*B\_wish* is the *Vill laga* bonus (0.10) for recipes on the want-to-cook list. Carried-over dinners are not scored: they are pinned into the plan, and DayFit only chooses their day.

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
| *vardag\_matlada* (feeds tomorrow's lunch) | Sun–Thu | 0.7 · leftover\_score + 0.3 · (1 − effort/3) | 60 min Mon–Thu, 90 min Sun |
| *fredagsmys* | Fri | 1.0 if tagged *fredagsmys*, *taco*, *pizza* or *burgare*; otherwise 0.4 · festive\_score | – |
| *helgmiddag* (nicer, few leftovers) | Sat | 0.6 · festive\_score + 0.4 · (1 − leftover\_score) | – |

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

The penalty adds up rule breaks against this week's chosen dinners and the last 7 days of `meal_log`, clamped to 0–1:

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

A recipe replaced with *Byt* gets SkipPen = 1, fading linearly to 0 over 14 days. A recipe swiped past in 5 separate sessions without being picked gets the same penalty over 30 days. SkipPen never touches ratings or affinities.

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
| First time cooked, unrated | "Lagade ni X?" + stars | `household_recipe.rating`, `rating_source = user` |
| First time cooked, rated in onboarding | "Lagade ni X?" + stars pre-filled from onboarding | Confirmed or changed rating, `rating_source = user` |
| Cooked again | "Lagade ni X?" with an *Ändra betyg* link showing the current stars | Recency only |
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

Seeds are written with weight *W₀* = 2, so 2–3 real ratings on a feature outweigh the quiz.

### Affinity update

With *x* = (rating − 3) / 2 in \[−1, 1\], every feature *f* of a rated recipe is updated as a weighted running mean, after decaying its weight by age:

```latex
W_f \leftarrow W_f\cdot 2^{-\Delta t/H},\qquad a_f \leftarrow \frac{W_f\, a_f + \eta_t\, x}{W_f + \eta_t},\qquad W_f \leftarrow W_f + \eta_t,\qquad \mathrm{conf}_f = \frac{W_f}{W_f + k_0}
```

- η = 1.0 for protein, cuisine, meal type and carb base. For main ingredients, η = 1/√k, where *k* is the number of main ingredients.
- *H* = 365 days, measured from the date each rating was set. *k₀* = 3, so a feature reaches 50% confidence after about 3 ratings.

### Consistency

- **Immediate:** setting or changing a rating recomputes that family's affinities at once (milliseconds for a few hundred rated recipes).
- **Nightly rebuild:** a cron job rebuilds all affinities from `household_recipe` ratings and `onboarding_answer`. It is idempotent and is the source of truth, so config changes and corrected recipe features apply retroactively.
- **Damping:** confidence weighting means a single rating can't swing a whole cuisine or protein.

## Weekly plan builder

The builder ranks recipes for every open day of a planning: the whole week, or one half in split mode. Its top choice per day becomes the first card of that day's swipe deck. It is deterministic for a given seed; *Blanda* changes the seed.

1. **Set up:** read the days to plan, each day's profile, the favourite share, the active config version and any pending carry-overs. The seed is a hash of household, week, part and reshuffle count.
2. **Place carry-overs:** pinned dinners go first, each on the open day where its DayFit is highest, and count toward their kind's quota.
3. **Build pools:**
   - *Favourites:* rated recipes with Pref ≥ 0.6 (about 3.4★) that pass the hard filters.
   - *Discovery:* unrated recipes that pass the hard filters: every *Vill laga* recipe, plus the top 300 others by Content.
4. **Set quotas:** favourites = round(N × share) for the week, discoveries for the rest. With 7 days at 70% that is 5 + 2. In split mode, the second half's quota is the week's target minus the favourites already chosen. If the favourites pool runs short, discoveries fill the gap.
5. **Order the days:** most constrained first, so Fri and Sat go before the lunch-box days.
6. **Score each day:** score both pools against the dinners chosen so far. If one kind's remaining quota equals the remaining days, that kind is forced; otherwise the higher top score wins.
7. **Pick with controlled variation:** sample from the top 5 with a seeded softmax (temperature 0.05), so the best recipe usually wins but weeks still differ.
8. **Swap pass:** try exchanging every pair of open days and keep any swap that raises the total score, for at most 2 passes.
9. **Build decks:** for each day, rank the remaining candidates behind the top choice, one recipe per dish group, interleaving favourites and discoveries by the favourite share.

**Timing:** cron prepares the decks every Sunday at 07:00, and also every Wednesday at 07:00 in split mode, then sends a notification. Reshuffling or re-preparing keeps every day the family has already chosen and every carry-over, and redraws only open days.

**Performance:** 7 days × about 400 candidates × 6 components is roughly 17,000 simple calculations, well under 100 ms in PHP.

## Choosing meals

The family doesn't approve a finished plan. It swipes through a ranked deck per day, and the engine re-ranks the other days after every pick.

### Planning modes

| Mode | Sunday 07:00 | Wednesday 07:00 |
| --- | --- | --- |
| *Hela veckan* (whole week) | Choose Sun–Sat, or Sun–Thu | – |
| *Dela veckan* (split) | Choose Sun, Mon, Tue | Choose Wed–Sat, or Wed–Thu |

- Each planning starts with a notification, e.g. *"Dags att välja söndag–tisdag"*.
- The second half is scored against what was chosen and cooked in the first half, so variety and the favourite share hold across the week.
- An uncooked dinner from the first half moves to the second half's planning by default.

### The swipe deck

| Gesture | Meaning | Effect |
| --- | --- | --- |
| Swipe right | *Välj* | Recipe is chosen for the day; the other open days are re-ranked |
| Swipe left | *Nästa* | Card is hidden for this session only |
| Tap | Details | Full recipe, short reason and score breakdown |
| *Ångra* | Undo | Brings back the last card |
| *Fyll i resten* | Autofill | The engine's top card is chosen for every remaining open day |
| *Blanda* | Reshuffle | New seed for the open days |

- Each card shows title, image (linked from the source), cook time, a *Nytt* badge for discoveries and the short reason, e.g. *"Ni gav den 5★ · bra matlåda"*. A card for a dish group has an *Andra varianter* link.
- The server returns the top 20 cards per day for the whole planning in one call, and the app pre-loads images. After each pick, one call (under 100 ms) re-ranks the other days. The next 20 cards load if a deck runs out. All scoring stays on the server.
- A recipe swiped past in 5 separate sessions without being picked gets a 30-day SkipPen. A single left swipe has no lasting effect.
- A search box lets the family choose any recipe in the database for a day. Hard filters still apply, and a warning shows if it breaks a variety rule.

### Replacing a chosen dinner

*Byt* on a chosen day opens that day's deck, re-scored against the rest of the week; the other days stay as they are. The replaced recipe gets a 14-day SkipPen. Ratings and affinities are untouched.

## After dinner

The day after each chosen dinner, the app asks *"Lagade ni {recept}?"*. A dinner that wasn't cooked is never counted as eaten or as disliked.

| Answer | Status | Recency and variety | Learning | Accuracy metrics |
| --- | --- | --- | --- | --- |
| Yes, first time (stars asked) | cooked | Cooldown starts; counts in the 7-day variety history | Rating updates Pref and affinities | Included |
| Yes, already rated | cooked | Same as above | None | Excluded |
| No → *Flytta till nästa planering* (default) | not\_cooked | No cooldown; pinned into the next planning | None | Excluded |
| No → *Inte nu, spara till senare* | not\_cooked | No cooldown; added to *Vill laga* | None | Excluded |
| No → *Inte nu, ta bort* | not\_cooked | Status *removed*: out of all pools, still searchable | None | Excluded |
| No answer within 2 days | unconfirmed | Counted as eaten: cooldown starts, counts for variety | None | Excluded |

- **Carry-over:** the next planning is Wednesday's half in split mode, otherwise next week. If its days are already chosen, the app offers to swap the dinner in on the best-fitting day. A carry-over lasts one week; if it still isn't cooked, it moves to *Vill laga*.
- **Vill laga:** wishlisted recipes get the 0.10 bonus until cooked, so they come back within a few weeks without being forced.
- **Removed vs blocked:** *removed* means "not for us right now" and can be undone from search; *blocked* means "never show again".
- **Flags:** a recipe uncooked or replaced 3 or more times in 60 days is flagged in admin. It never gets a lower score automatically.

## Explainability

Each card shows one short reason built from its two largest positive components. Tapping it shows the full breakdown. Both come from `explanation_json`, saved with the slot when the dish is chosen, so the explanation always matches the decision.

| Top component | Reason (Swedish UI) |
| --- | --- |
| Pref, rated | "Ni gav den {stars}★" |
| Pref, unrated | "Liknar {recept} som ni gav {stars}★" |
| Pref, dish group | "Ni gillar {rätt} – ny variant" |
| Due | "Inte lagad på {veckor} veckor" |
| DayFit, lunch-box day | "Bra matlåda till imorgon" |
| DayFit, Friday | "Fredagsmys" |
| DayFit, Saturday | "Lite finare, få rester" |
| Explore | "Nytt för er: {kök}" |
| Carry-over | "Flyttad från {dag}" |
| Vill laga bonus | "Från er Vill laga-lista" |

For "Liknar", the engine names the rated recipe that shares the most weighted features.

Example breakdown:

```
Kycklinggryta med curry           Totalt 0.68
  Smak (Pref)       0.90 × 0.45 = +0.41   betyg 5★, kyckling +0.6, indiskt +0.4
  Dagen (DayFit)    0.90 × 0.25 = +0.23   matlåda: rester 0.9, 40 min
  Dags igen (Due)   0.50 × 0.15 = +0.08   lagad för 21 dagar sedan (spärr 14)
  Ny (Explore)      0.00 × 0.10 = +0.00
  Variation        −0.10 × 0.35 = −0.04   2:a kycklingrätten denna vecka
  Bytt nyligen      0.00 × 0.30 = −0.00
```

The same data powers an admin view, "Why not recipe X on Tuesday?", showing which hard filter removed a recipe or how far it scored below the winner.

## Measuring accuracy

Each `plan_slot` stores the Pref predicted when the dish was chosen. When a recipe chosen while unrated gets its first rating, the engine logs the error between the predicted rating (1 + 4 × Pref) and the actual rating. An admin page tracks per month:

| Metric | Meaning | Should |
| --- | --- | --- |
| MAE | Mean absolute error between predicted and actual first ratings | Fall |
| Hit rate | Share of cooked dinners rated 4★ or higher | Rise |
| Discovery success | Share of discovery dinners rated 4★ or higher | Rise |
| Replace rate | Share of chosen dinners later replaced with *Byt* | Fall |
| Swipes to pick | Average cards swiped before a pick | Fall |
| Top-3 picks | Share of picks from the first 3 cards | Rise |

These metrics guide tuning of `engine_config`.

## Server-side configuration

Every engine number is a row in `engine_config`, so tuning is an admin-page change, never a code deploy. Values are cached per request and versioned; each plan records the `config_version` that built it.

| Area | Key | Default | Meaning |
| --- | --- | --- | --- |
| Weights | `weight.pref` / `.dayfit` / `.due` / `.explore` | 0.45 / 0.25 / 0.15 / 0.10 | Positive component weights |
| Weights | `weight.variety` / `.skip` | 0.35 / 0.30 | Penalty weights |
| Pref | `pref.content_weight` / `pref.rating_weight` | 1 / 3 | Content vs rating in Pref |
| Pref | `pref.sibling_weight` | 2 | Weight of rated dish-group siblings for an unrated variant |
| Content | `content.beta.ingredients` / `.protein` / `.cuisine` / `.meal_type` / `.carb` | 0.30 / 0.25 / 0.20 / 0.15 / 0.10 | Feature-type weights |
| Learning | `affinity.seed_weight` | 2 | Weight of onboarding seeds |
| Learning | `affinity.conf_k0` | 3 | Ratings for 50% confidence |
| Learning | `affinity.half_life_days` | 365 | Age decay of rating evidence |
| Filters | `filter.hide_at_or_below` | 2 | Ratings at or below this are hidden |
| Recency | `cooldown.days.5` / `.4` / `.3` | 14 / 21 / 42 | Cooldown by rating; unrated cooked recipes use the 4★ value |
| Day fit | `dayfit.weekday_max_minutes` / `dayfit.sunday_max_minutes` | 60 / 90 | Soft time caps on lunch-box days |
| Day fit | `dayfit.softcap_factor` | 0.3 | DayFit multiplier when a cap is exceeded |
| Variety | `variety.max_protein_week` / `.max_cuisine_week` / `.max_carb_week` | 2 / 2 / 3 | Weekly repeat limits |
| Variety | `variety.pen.adjacent_protein` / `.protein` / `.cuisine` / `.carb` / `.overlap` | 0.5 / 0.4 / 0.3 / 0.2 / 0.3 | Penalty sizes |
| Variety | `variety.overlap_jaccard` | 0.5 | Ingredient-overlap threshold |
| Variety | `variety.target_fish` / `.target_veg` | 1 / 1 | Weekly targets, with bonuses 0.3 / 0.2 |
| Skips | `skip.replace_days` | 14 | SkipPen after *Byt* |
| Skips | `swipe.reject_sessions` / `swipe.reject_days` | 5 / 30 | Repeated left swipes before a SkipPen, and its length |
| After dinner | `confirm.auto_days` | 2 | Days without an answer before a dinner counts as eaten |
| After dinner | `carryover.expire_weeks` | 1 | How long a carry-over stays pinned |
| After dinner | `wishlist.bonus` | 0.10 | Score bonus for *Vill laga* recipes |
| After dinner | `flag.count` / `flag.days` | 3 / 60 | Uncooked or replaced this often → admin flag |
| Builder | `builder.fav_threshold` | 0.6 | Pref needed to count as a favourite |
| Builder | `builder.top_k` / `builder.temperature` | 5 / 0.05 | Controlled variation |
| Builder | `builder.discovery_prefilter` | 300 | Discovery candidates scored per planning |
| Builder | `builder.swap_passes` | 2 | Local improvement passes |
| Builder | `builder.generate_time` / `builder.split_day` | 07:00 / wednesday | Planning time; first day of the second half in split mode |
| Deck | `deck.size` | 20 | Cards loaded per day |
| Dedupe | `dedupe.auto_merge` / `.review` / `.group_overlap` | 0.95 / 0.80 / 0.5 | Thresholds for merging copies and grouping variants |
| Dedupe | `dedupe.source_priority` | koket, ica, arla, coop | Tie-breaker for the kept copy |
| Crawl | `crawl.delay_s` / `crawl.recheck_share` | 5 / 0.01 | Seconds between requests per site; nightly re-check share |

**Family settings** (in the app): favourite share (default 70%), plan days (all 7 or Sun–Thu), planning mode (whole week or split) and block vegetarian (off). The weekday-to-profile map lives in `day_profile`; a new profile, such as *gäster*, needs one new DayFit rule plus config rows.

## System architecture

The server is a plain Linux setup on one VPS (e.g. Ubuntu 24.04 LTS or Debian 12): Apache 2.4, PHP 8.3 with PHP-FPM, and MySQL 8, with no containers. The engine is plain PHP classes with no web dependencies, so the same code runs from API requests and from cron. Only cron talks to the recipe sites, so a slow or blocked site never affects the app.

Numbered SQL files in `server/migrations` are applied by the PHP CLI runner. Its `000_schema_migrations.sql` bootstrap creates `schema_migrations`; each later file is recorded with a checksum and applied timestamp. The runner skips matching files and stops if a recorded file changed or a previous run stopped partway through a migration. MySQL DDL can commit before a migration finishes, so an interrupted migration requires manual repair before retrying.

```
Windows + Android apps (Flutter)
        |  HTTPS /api/v1
        v
+----------------------- Linux VPS: Apache + PHP 8.3 ------------------------+
|  JSON API + admin  -->  Recommendation engine        Cron jobs (PHP CLI)    |
|  decks, picks,          hard filters + scoring        crawl sources nightly  |
|  ratings, onboarding,   plan builder + swap pass      rebuild affinities 03:00|
|  search, admin pages    swipe decks + reasons         decks Sun + Wed 07:00  |
+---------------------------------------------------------------------------+
        |                        |                          |          |
        v                        v                          v          v (polite crawl)
                     MySQL 8 (recipes, history, affinities, config)   Recipe sites
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
| `POST /plans/{week}/{part}/autofill` | *Fyll i resten* |
| `POST /plans/{week}/{part}/shuffle` | *Blanda* |
| `POST /slots/{id}/cooked` | Day-after answer: yes, or no with a choice |
| `PUT /recipes/{id}/rating` | Set or change a rating |
| `GET /recipes/search?q=` | Search the recipe database |
| `POST /wishlist`, `DELETE /wishlist/{id}` | *Vill laga* list |
| `GET /settings`, `PUT /settings` | Family settings |

Admin pages (review queues, feature corrections, config, metrics, debug views) are server-rendered PHP behind admin login.

**Clients:** one Flutter codebase builds native apps for Windows (desktop) and Android. Flutter is the chosen framework; the alternatives below apply only if a blocker appears.

- **Auth:** a 15-minute access token plus a refresh token per device, hashed in `device_token`.
- **Offline:** each app caches the current week's chosen dinners with full recipes. Day-after answers and ratings are queued and sent when online.
- **Reminders:** planning and day-after notifications use Firebase Cloud Messaging on Android and local scheduled notifications on Windows.
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

- **Full first crawl:** Köket's sitemap lists only about 500 recently changed pages, so the first crawl also walks the recipe category pages under `/recept`. At 1 request every 5 s, 10,000 recipes take about 14 hours, so the crawl runs over a night or two and resumes where it stopped. After that, the nightly job only fetches new or changed recipes.
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
/server/               ← PHP 8.3: src/Engine, src/Ingest, src/Api, migrations/, tests/
/server/simulator/     ← personas and the headless simulator
/app/                  ← Flutter app for Android and Windows
/api/openapi.yaml      ← API contract shared by server and app
CLAUDE.md / AGENTS.md  ← instructions the agent reads on every task
```

**Instruction file:** Claude Code reads `CLAUDE.md` and Codex reads `AGENTS.md`; keep one file under both names. It states:

- What the app is, in 5 lines, with a pointer to `/docs/design.md`.
- Versions: PHP 8.3, MySQL 8, Apache, Flutter stable, Linux server.
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

1. **Foundation and seed data (2–3 weeks):** VPS setup, recipe and ingestion schema (including `ingest_url`, `ingredient_unknown`, dish groups, duplicate review and `engine_config`), the Köket adapter, the full first crawl (which also establishes the size of Köket's catalogue), normaliser, deduplication and dish groups, the clean-up pass and the database snapshot. Household, planning, swipe and auth tables are created in phase 2. The phase 1 API contract covers shared recipe/source/ingredient/dish-group schemas and `GET /recipes/{id}`, `GET /recipes/search` and `GET /health`; the remaining `/api/v1` contract starts phase 2.
2. **Engine, headless (2–3 weeks):** filters, scoring, plan builder, swipe decks, planning modes, replace, after-dinner answers, carry-overs, reasons and onboarding, exposed through `/api/v1` with token auth.
3. **Simulation and tuning (2 weeks):** simulator, six personas, choosing the 30–40 onboarding dishes from the seeded database (an admin list of the most common dish groups across sites), automatic checks, nightly rebuild, metrics page, config admin and the "why not X" view.
4. **Client apps (3–4 weeks):** the Flutter app for Android and Windows: onboarding, swipe decks, week view, offline recipe view, after-dinner prompt, ratings, search, *Vill laga* and settings.
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
