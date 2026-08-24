# Any associated group's forced root locks a release

## Context

A group can carry an operator-selected root category, while a release can be cross-posted to several groups. Categorization and audio routing previously consulted only the ingestion-order primary group, so the same cross-post could receive different policy depending on scan order. Separately, ADR 0006 allowed audio-only Mediainfo evidence to cross a routed root, while the later forced-root guard prevented that evidence from crossing an operator-selected root. G15 and G20 in the release lifecycle gap analysis identified the missing precedence rule.

## Decision

A **Forced root** is a lock, not a floor. An operator's explicit group policy takes precedence over all derived evidence, including audio-only Mediainfo proof. ADR 0006's audio-only cross-root exception applies only to the **Routed floor** established by ADR 0005; it does not cross a Forced root.

Group policy is evaluated across the release's complete set of associated groups. If any associated group has a Forced root, that force governs categorization. When associated groups force different roots, the primary group's force wins if the primary group is forced; otherwise, the numerically lowest forced-root category ID wins. This makes the result deterministic while preserving the primary group's explicit policy in a genuine conflict.

Audio routing and categorization must use that same selected Forced root. They may not derive different group-policy answers: the `aud` and `add` paths must retain one deterministic owner for every pending release.

## Deliberate choices a future reader may be tempted to "fix"

- **Force beats proof.** A Forced root records operator judgment about a group's identity, while Mediainfo is derived evidence about an individual file. Erotic audio belongs at least as plausibly under XXX as under Audio, so audio-only proof is not sufficient reason to override the operator.
- **Any forced root wins, rather than the primary group alone.** A cross-post association is meaningful group evidence. Allowing an unforced primary to hide a forced secondary makes explicit policy dependent on scan order.
- **Conflicting forces use a deterministic tie-break, not a new precedence hierarchy.** Prefer the primary group's force when it has one; otherwise choose the lowest forced-root category ID. This handles contradictory operator configuration without inventing semantic priority among roots.
- **Categorization and audio routing share the rule.** Divergent policy would make a release's stored category disagree with its additional-processing owner and could leave small forced-Music cross-posts claimable by neither path.

The cross-post-aware implementation is tracked by issue #227. The existing forced-root guard already implements the lock for the primary group and remains intentional.

Settled in a triage session on 2026-08-23. See `CONTEXT.md` for **Forced root**, **Routed floor**, and **Cross-posted release** vocabulary.
