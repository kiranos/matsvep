# Split shortlist invalidation

The single household state version caused a rating or wishlist change to rebuild a plan's full candidate shortlist on the next deck request. A second database-maintained `filter_version` now identifies changes to hard filters or planning settings. `state_version` still records every household recipe, settings, day-profile and carry-over change.

A score-only change reuses the shortlist and refreshes candidate ratings, affinities, skips and scores. Blocks, removals, hidden ratings, cooked recency, settings, day profiles, carry-overs and engine config changes rebuild it. SQL and the engine recheck hard filters even when reusing a shortlist. Choices and swipes re-rank without invalidating the shortlist.

The generated database benchmark now defaults to a catalogue with production-scale total and dinner-candidate counts. CI reports full plan, score-change deck, post-pick deck, stable-deck p95, choose and replace times. The production `--readonly` run remains operator-controlled and cannot write data.
