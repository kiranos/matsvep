# Persona simulation on a throwaway catalogue copy

The phase 3 simulator reads the configured source catalogue in a read-only transaction while holding the crawler lock. It uses a separate database-creation account to make and later drop a throwaway database. It never writes to the source catalogue and refuses the application database account.

Hidden persona tastes use dish and ingredient terms, saturating and pair effects, rating noise, and slow drift. They do not reuse the engine's affinity features or scoring formula. Each persona drives the real API and nightly maintenance with an injected clock. Random dinner-candidate and favourites-only policies run alongside the engine for comparison. Reports contain aggregate metrics and timings, never recipe content.

Simulator parameters live in `server/seeds/simulator_config.json` and are loaded into the throwaway database after the catalogue copy. They are never added to production `engine_config`, where they would change the aggregate config version and invalidate planning shortlists. The copy uses batched inserts in a target transaction and reports elapsed copy time. All policies also report mean noise-free hidden utility and the share at 4 or above for every explicitly cooked dinner, which remains comparable when a recipe was rated earlier.

The full-catalogue baselines can consider recipes outside a plan's saved shortlist. Their choices still pass through the API and its hard filters, but they do not post `next` or pick swipe events; the swipe endpoint accepts only shortlist candidates. The baseline swipe-to-pick metric counts inspected candidates in memory. Simulator failures identify method, route and policy, and the command drops its throwaway database in `finally`.
