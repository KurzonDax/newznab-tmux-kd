> Historical source snapshot. Read ../README.md and the consolidated design contract first. Earlier proposals and superseded #627 dependencies are not implementation requirements.

# Accepted browse design compared with issue 627

Review date: 2026-09-15. Read the complete issue body and its sole continuation comment (Part 2 of 2), covering common requirements and W01–W14. Issue is OPEN. This is a specification comparison, not verification that 627 is implemented or that the prototype satisfies its performance tests.

Sources:
- https://github.com/KurzonDax/newznab-tmux-kd/issues/627
- https://github.com/KurzonDax/newznab-tmux-kd/issues/627#issuecomment-5680097884
- Local toolbar-design-decisions.md, interpreted with later decisions superseding rejected versions.
- Local toolbar-prototype.html through revision 17. Demonstration arrays, static options, counts and view handlers are not production algorithms.

## Result

Do not call the current design/spec conflict-free. The compact visual layout is compatible with 627's memory remediation, but metadata-control behavior directly overlaps W14. New filtering/search semantics and audiobook identity deliberately change behavior that 627 is instructed to preserve. They require explicit integration contracts. No issue or production source was changed for this review.

## 1. Metadata selectors — direct conflict with W14

W14 explicitly requires compact searchable option popups for database-derived genre/network/label/platform/publisher/author labels, 25 options per page, Next/Previous, complete totals, full label wrapping, keyboard navigation, Escape/focus return, selected value preservation and stale-request rejection. It forbids loading or embedding the complete option catalogue. The authenticated /web/browse-options endpoint uses whitelisted fields and reconstructs user/category/group/poster/watch/basket eligibility. Unrelated selected metadata filters do not narrow its option scope.

Our prototype uses a plain pre-filled Platform select and static Genre options; Network, Label and Publisher appear as free text. The local Platform decision said distinct stored values without specifying a bound. Copying those controls literally would contradict W14. Changing an existing exact label filter to substring free text would also change its prescribed predicate, even if it looked similar.

Required reconciliation:
- Reuse W14's shared searchable paginated selector for existing metadata-label filters, including Console Platform. Keep its closed control compact and aligned in the accepted layout.
- Keep 25 results/page, current selection display/Clear, full-label wrapping, authorization, exact filter values, endpoint and query bounds. No full DISTINCT/pluck array in Blade/Redis/browser.
- Moving a filter into Advanced does not change its key, exact-match predicate or option scope.
- The user's rejection of a custom YEAR popover does not establish a rejection of all dropdown option menus. Year bounds remain inline; these are label selectors.
- If the user intends free-text partial author/publisher/network matching rather than selecting labels, explicitly scope that new behavior instead of silently replacing W14's existing exact filters.
- Music/Audiobooks and the six Sort choices are fixed finite options; ordinary selects are appropriate. Full supported Year choices remain finite; do not apply the 25-label mechanism to years.

## 2. New filters versus 627's preservation clauses — intentional changes to identify

627 section 1.1/W02/W04/W14 preserve existing eligible sets and filters. The accepted design deliberately removes Adult Year, Books Author dropdown and the constant PC Platform control; introduces Music versus Audiobooks; adds provider IDs, ratings and TV episode filters; and changes basic Search to include release names plus appropriate metadata in every view.

These are product changes, not memory fixes. State them explicitly in the final design scope and regression expectations, implemented on top of 627 rather than asking its agent to preserve and remove the same UI at once. Preserve old URLs/parameter parsing where possible; removing a visible control does not itself authorize rejecting existing links. The exact legacy-parameter policy needs to be settled in the final contract.

Genre/network/etc. moving into Advanced retain existing parameter names. Prototype IDs/names such as advanced-Platform and audio_category are illustrative, not a parameter migration plan. Map Music to Audio root minus category 3030 and Audiobooks to Audio category 3030, server-side. Never use a site-wide categories_id != 3030 predicate without the Audio root restriction. Do not mix 627 work packages into new tickets.

## 3. Uniform text search — W03 integration, not an alternative unbounded search path

W03 owns bounded SQL/index candidate storage, complete SQL OR index matches, scope-sensitive cache identity, original exact/negative/fielded/fuzzy behavior, failure handling, and unchanged external API drivers. At most 500 candidate IDs may be retained per batch; never assemble a complete ID array. It does not automatically make the current browse SQL-LIKE path a Manticore query.

The user approved which content Search should cover, not an engine switch or new token/fuzzy semantics. Those details remain a final-spec seam. If browse uses index candidates, integrate W03's relation/reference interface and W01 lifecycle; do not reintroduce WebSearchIndex list<int> or a new all-ID Redis cache. Scope any new cache identity by effective search fields, category mode, criteria, user eligibility and algorithm version. Ensure count/page and expansions use the same normalized search scope. Provider IDs remain exact identifier predicates, preserve leading-zero IMDb strings and do not pass IDs through fuzzy text matching.

Acceptance must include SQL-only, index-only and overlapping matches if both sources are used, all views, later pages, excluded rows, expired/partial cache failures and no arbitrary cap. W03's existing global search behavior remains protected; new browse behavior needs separate explicit expectations.

## 4. TV Season/Episode/air-date filters — W02 membership must govern

A predicate against only r.tv_episodes_id before membership expansion can discard full-season packs or explicit multi-episode releases that contain the requested episode but have no direct link. Filtering by an unrelated joined episode can also admit the wrong membership.

Resolve episode-specific criteria against W02's canonical same-show episode membership. Keep qualifying pack/range releases for matching episode groups. Define and test how episode filters apply to Table/Cards as well as Covers; do not duplicate one release per matching episode in ordinary release lists. Episode air dates use tv_episodes.firstaired, while basic TV year remains series started. Summary currently denotes series summary. No whole catalogue hydration to implement these filters.

Preserve canonical duplicate resolution, positive-episode rules, specials, explicit parser precedence, no guessed season, posted DESC/id DESC inside episode cards, six outer sorts/ties and complete totals. Tests should include a pack without an episode FK, a range crossing the requested subset, duplicate metadata and foreign-show links. W04 title-page fallback/year rules are a different existing contract and must not be overwritten incidentally. The TV Shows directory remains all-stored-show browsing rather than release eligibility.

## 5. Audiobook Covers — intentional extension of W05 identity mapping

W05 preserves the existing root/foreign-key mapping while bounding non-TV expansions. Audiobooks are Audio category 3030 but use bookinfo_id/book metadata, unlike musicinfo_id for music. Therefore the accepted audiobook proposal cannot simply inherit the old Audio identity mapping.

Extend identity explicitly by Audio mode. The same discriminator must govern outer groups, metadata search, count, selected cover identity, expansion authorization, option scope and URL/page state. A bookinfo ID and musicinfo ID with the same number must never collide. Both reuse W05 COUNT + bounded release_page/release_per pagination, 24/48/100, adddate DESC/id DESC expansion order and current-page selection only. No new unbounded audiobook expansion or permanent DOM cache.

Tests need colliding numeric metadata IDs, audiobook releases with book metadata but no music metadata, Music excluding 3030, Audiobooks excluding other Audio categories, no cross-root leakage, later expansion pages and filter persistence. Book publication year must be described as the metadata year, not asserted to be the audiobook recording/publication edition date without evidence. The decision that every other Audio subcategory is Music is explicit; do not require an album link for ordinary release search merely because the mode is Music.

## 6. Metadata matching versus narrow hydration — W11

Adding Actor/Plot/Summary/ISBN/ratings filters does not require loading these fields into every release row. Apply predicates/joins/EXISTS in SQL and hydrate only the bounded display projection. Large plot/summary/review fields stay out of shared row-label DTOs. Extend presentation projections only where the actual view consumes a value. Preserve null-vs-missing semantics, shared object identity, media summaries and availability checks.

Rotten Tomatoes source attribution/normalization is a separate discovered correctness concern, outside 627's memory-only ingestion boundary. Record it explicitly in the eventual search scope; do not silently add an ingest change to 627. No live data repair/backfill is authorized by prototype approval.

## 7. Shared compact styling — compatible with bounded control behavior

The final design should change shared search/filter component sizing and design standards, not accumulate the prototype's successive CSS overrides. Update documented compact-control height/padding/label gaps/layout and checks. Preserve W14's keyboard/accessibility behavior, selected text/full option labels, themes and narrow-screen reflow. Do not globally shrink unrelated admin/forum/dialog controls as an accidental side effect.

627 section 1.7 preserves styling during its remediation; the subsequent accepted design intentionally changes search/filter density. State that sequencing explicitly: preserve 627 functionality and replace only the authorized visual rules. Layout acceptance is not permission to drop bounded pages or revert controllers.

## 8. No direct design changes to remaining packages

- W01 workspace: retain connection pinning, InnoDB temporary relations, cleanup and bounded batches wherever reused.
- W04 bulk snapshot/archive and W06 show-dialog scalar state: retain existing behavior; new search controls do not authorize an ID array or retained-DOM cache.
- W07 files summary, W08 regex limits, W09 registration logs, W10 histories/reports, W12 forum and W13 settings: no intended functional changes. Shared-component updates must not regress their pagination, accessibility or data contracts.
- Frozen v1/v2/RSS shapes remain unchanged. No production edits, memory-limit increases, new dependencies or unbounded allocations are permitted as shortcuts.

## Final-spec integration gates

1. Resolve the W14 control contract and explicitly identify intended product-behavior changes/legacy URL policy.
2. Specify search engine/token behavior separately from visible fields; reuse W03 if using index candidates.
3. Specify TV membership-aware filters and mode-aware audiobook identity throughout main results/options/expansions.
4. Build on 627's delivered interfaces (or reconcile the dependency explicitly if it is still in flight), rather than replacing its changed modules from an older checkout. OPEN status alone does not prove which packages are implemented.
5. Run 627-relevant cold/warm N/2N fixtures for the changed paths: below 128 MiB PHP peak, <=8 MiB dataset-induced growth, complete counts/later pages, <=500 candidate batch, <=25 label options in the client, bounded displayed releases and no stale-response/DOM retention regression. Include real DB integration and browser tests, elapsed/query counts and RSS; synthetic fixture generation stays outside the measured process.
6. None of these gates has been executed against a production implementation by this design review. The throwaway prototype proves only visual/interactivity proposals, not query correctness or memory safety.
