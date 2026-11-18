# Ingredient pilot refinement

The 500-URL pilot exposed weak nearest-name suggestions and gaps in descriptor and protein coverage. Migration 008 adds configurable minimum similarity and affix length for suggestions. Unicode edit similarity is used only when there is no known compound head or prefix; a weak result leaves the review suggestion empty. Existing pending suggestions are recalculated when normalisation runs again.

Descriptor words remain in `ingredient_descriptors.csv`. Exact aliases win before stripping, so a meaningful qualified ingredient stays distinct. A descriptor is removed only if the remaining text matches a known ingredient, and descriptors cannot become unparsed units. For alternatives, the first known ingredient is recorded; an unresolved line enters the unsafe review count. The expanded ingredient seed covers common fish, seafood, meat cuts and vegetarian proteins while keeping Swedish vocabulary out of PHP code.

The report defines a protein candidate using aliases in the protein or legume categories plus curated protein terms. It reports both the candidate count and the fully matched share, including unmatched compounds containing those terms. The CLI writes UTF-8 JSON bytes; Windows PowerShell should redirect the native process output directly to a file to preserve Swedish characters.
