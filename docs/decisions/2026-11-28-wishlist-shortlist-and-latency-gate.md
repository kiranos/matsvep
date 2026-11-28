# Wishlist shortlist and latency gate

Wishlisting an eligible recipe adds it to the saved shortlist of each ready plan with an open day, in the same transaction as the household wishlist change. The existing hard filters decide eligibility, and normal deck reads still recheck them. Score-only changes continue to reuse the shortlist. This makes a newly wishlisted recipe available to the current week's decks without rebuilding the full candidate pool.

The generated database benchmark keeps its single-operation timings for diagnosis. CI judges the one-second full-plan target and 100 ms deck, choose and replace targets using p95 from 20 repeated API scenarios. This limits failures caused by one noisy timing while retaining a production-sized test catalogue.
