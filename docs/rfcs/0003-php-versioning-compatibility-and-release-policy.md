# RFC 0003: PHP Versioning, Compatibility and Release Policy

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0
- Decision type: Compatibility, versioning and release governance
- Depends on: RFC 0001, RFC 0002
- Supersedes: None
- Superseded by: None

## 1. Summary

RFC 0003 defines how EvolvePHP 2 communicates and enforces PHP compatibility, framework compatibility, package compatibility, semantic versions, pre-release stability, deprecation, security support and release lifecycle expectations.

This RFC establishes policy, not current implementation. It does not claim that CI, Composer constraints, release automation, package publication or release branches have already been implemented.

The current repository runtime metadata still represents the preserved EvolvePHP 1 line. RFC 0003 defines the future EvolvePHP 2 policy and must not be read as a statement that the current root `composer.json` already enforces that policy.

## 2. Compatibility Terminology

### Supported PHP Version

A supported PHP version is a PHP minor version that:

- Is allowed by the EvolvePHP package constraint.
- Is executed in the official CI matrix.
- Passes the supported test suite.
- Is covered by the documented compatibility policy.

### Tested PHP Version

A tested PHP version is a PHP version executed in official CI. A version is not officially supported merely because a developer reports that it works locally.

### Minimum PHP Version

The minimum PHP version is the oldest PHP minor accepted by EvolvePHP 2 packages.

### Recommended PHP Version

The recommended PHP version is the newest officially supported PHP branch validated by the EvolvePHP CI matrix, unless release notes state otherwise.

### Framework Release

A framework release is a published semantic version of one or more first-party EvolvePHP packages.

### Security-Supported Release

A security-supported release is a framework release line eligible for security fixes under the documented support policy.

### Maintenance-Supported Release

A maintenance-supported release is a release line eligible for security fixes and selected correctness fixes.

### End Of Support

End of support is the point after which no further fixes are promised for a release or PHP combination.

PHP's own support lifecycle and EvolvePHP's framework lifecycle are related but separate. A PHP branch being supported upstream does not automatically mean every EvolvePHP release supports it, and an EvolvePHP release policy cannot extend upstream PHP maintenance.

## 3. EvolvePHP 2.0 PHP Policy

Accepted policy:

```text
Minimum PHP version: 8.4
Initially tested PHP versions: 8.4 and 8.5
```

Rules:

- PHP 8.4 is the minimum for EvolvePHP 2.0.
- PHP 8.4 and PHP 8.5 form the initial official CI matrix.
- EvolvePHP 2 Core must not contain PHP 7 compatibility code.
- EvolvePHP 2 packages may use PHP 8.4 language features.
- The project does not promise compatibility with PHP 8.3 or earlier.
- A local test run on PHP 8.3 does not establish EvolvePHP 2 compatibility.
- Legacy applications on unsupported PHP versions must integrate through Evolve Bridge remote or sidecar modes.
- Same-process embedding requires a PHP version supported by both EvolvePHP and the host application.

The current root `composer.json` is not changed by this RFC and should not be described as already enforcing the EvolvePHP 2 policy.

## 4. Composer PHP Constraint Policy

The intended EvolvePHP 2 package constraint is:

```json
"php": "^8.4"
```

Policy:

- `^8.4` permits supported PHP 8 minor releases beginning with 8.4.
- Official support still depends on the documented CI matrix.
- Composer accepting a future PHP version does not by itself mean that version is officially supported.
- A newly released PHP minor must be added to CI and validated before EvolvePHP documents it as supported.
- Composer constraints must not be broadened merely to suppress installation errors.
- Packages may use a narrower constraint only when a documented technical reason requires it.
- First-party packages should use compatible PHP constraints unless their runtime needs differ legitimately.
- Implementation of the constraint belongs to a later repository-structure task.

Do not modify `composer.json` in this task.

## 5. Composer Platform Configuration

- The repository must not use `config.platform.php` to pretend CI is running a PHP version that it is not actually executing.
- A Composer platform override may be used for dependency-resolution verification only when clearly documented.
- Platform emulation must not replace real test execution.
- Release validation must run on real supported PHP runtimes.
- Contributor environments may differ, but official compatibility claims come from CI.
- Composer lock generation must use a documented and reproducible environment.
- A platform override must never silently hide use of syntax or runtime features unavailable on the declared minimum PHP version.

## 6. Adding Support For A New PHP Minor

A new PHP minor becomes officially supported only after:

1. The version is publicly available in a stable form.
2. CI executes the complete applicable test suite on it.
3. Composer dependency resolution succeeds.
4. Static analysis and coding tools support it where applicable.
5. No unresolved compatibility blocker exists.
6. Documentation is updated.
7. The changelog records support.
8. A maintainer explicitly accepts the support addition.

Rules:

- Preview, alpha, beta and release-candidate PHP builds may be tested experimentally.
- Experimental PHP testing must not be presented as stable support.
- Support for a new PHP minor may be added in an EvolvePHP minor release when backward-compatible.
- Support must not be claimed solely from syntax linting.

## 7. Removing Support For A PHP Minor

- Dropping a supported PHP minor is normally a breaking change.
- A supported PHP minor must not be removed in a patch release.
- Removal should normally occur in the next EvolvePHP major release.
- An EvolvePHP major may remove a PHP version that is no longer appropriate for security, dependencies or maintainability.
- The removal must be announced before the major release.
- Upgrade guidance must identify the required PHP change.
- Composer constraints, CI and documentation must be changed together.
- Emergency removal within a major line is permitted only when continuing support would create a serious security or correctness risk.
- Emergency removal requires explicit documentation and a clearly justified release note.

This RFC does not rely on exact external PHP end-of-life dates.

## 8. PHP Extensions

- Required PHP extensions must be explicitly declared in Composer.
- Optional extensions must not become hidden requirements.
- Packages must fail clearly when a required extension is absent.
- Extension-specific functionality should live in an adapter package where appropriate.
- The Core package should keep required extensions minimal.
- Development-only extensions must not be declared as production requirements.
- Polyfills may be used only when they preserve the supported behaviour without undermining the PHP 8.4 baseline.
- Extension support must be tested where practical.
- Adding a new required extension to a stable package requires compatibility review.

RFC 0003 does not choose the complete extension list.

## 9. Semantic Versioning

Stable first-party packages adopt Semantic Versioning:

```text
MAJOR.MINOR.PATCH
```

### Major Release

A major release may contain:

- Backward-incompatible public API changes.
- Supported-PHP removals.
- Major package restructuring.
- Removal of previously deprecated stable APIs.
- Behaviour changes requiring application migration.

### Minor Release

A minor release may contain:

- Backward-compatible features.
- New stable public APIs.
- New experimental APIs.
- New optional packages or adapters.
- Support for a new PHP minor.
- Deprecations.
- Backward-compatible performance improvements.

### Patch Release

A patch release may contain:

- Backward-compatible bug fixes.
- Security fixes.
- Documentation corrections.
- Internal refactoring.
- Performance fixes preserving documented behaviour.
- Compatibility fixes that do not remove supported environments.

Patch releases must not intentionally introduce new breaking public behaviour.

## 10. Package Version Alignment

RFC 0002 is reaffirmed:

- First-party EvolvePHP 2 packages use major version `2`.
- Official packages within the EvolvePHP 2 family must not require incompatible framework majors.
- Minor versions may be coordinated when a feature spans packages.
- Patch numbers do not need to be identical across every package.
- A package may release independently when its public compatibility constraints remain accurate.
- A convenience metapackage may define a tested compatible set.
- Cross-package constraints must be explicit.
- Packages must not rely on undeclared monorepo proximity.
- A package split or rename requires migration guidance.

## 11. Pre-Release Versions

Use semantic pre-release identifiers:

```text
2.0.0-alpha.1
2.0.0-alpha.2
2.0.0-beta.1
2.0.0-rc.1
2.0.0
```

### Alpha

- Architecture and public APIs remain under active development.
- Breaking changes are expected.
- Alpha releases are not recommended for production use.
- Alpha compatibility is limited to what is explicitly documented.
- Migration notes should still be provided for significant changes when practical.

### Beta

- Major architecture should be established.
- Public APIs may still change.
- Breaking changes require clear changelog entries.
- Beta is intended for integration testing and early adopters.
- Production use remains at the adopter's risk.

### Release Candidate

- Intended to match the final stable release unless a blocking issue is found.
- No planned breaking changes should remain.
- Public APIs and documentation should be substantially complete.
- Only release-blocking fixes should normally enter after RC begins.

### Stable

- Documented stable APIs receive semantic-versioning guarantees.
- Stable does not mean defect-free.
- Internal and experimental APIs remain governed by their own classifications.

## 12. Pre-Release Numbering Rules

- Pre-release counters are numeric and increase monotonically within a stage.
- A stage transition resets the counter:

```text
alpha.4 -> beta.1
beta.3 -> rc.1
```

- Published versions are immutable.
- A broken published version must not be replaced silently.
- Corrections require a new version.
- Git tags and Composer package versions must match.
- Release notes must identify the release stage.
- Pre-release version ordering follows Semantic Versioning.

Do not create tags in this task.

## 13. Backward Compatibility

Backward compatibility applies to documented stable public APIs and behaviour.

It may include:

- Public interfaces.
- Public classes intended for application use.
- Public methods.
- Public value objects.
- Public exceptions callers are expected to catch.
- Configuration keys.
- Event names and documented payloads.
- CLI commands and documented options.
- Package installation behaviour.
- Lifecycle hooks.
- Integration contracts.

Not automatically covered:

- Internal APIs.
- Experimental APIs.
- Undocumented service identifiers.
- Private database schemas.
- Exact exception messages unless documented.
- Package directory layout.
- Test fixtures.
- Internal event payloads.
- Implementation details.
- Performance characteristics not documented as contractual.

Behavioural compatibility matters, not only method signatures.

## 14. Compatibility Breaks

Examples of breaking changes:

- Removing or renaming a stable public method.
- Adding an unresolvable required method to a public interface.
- Narrowing accepted input types.
- Widening thrown exceptions in a way callers must now handle.
- Changing documented lifecycle ordering.
- Removing a supported PHP version.
- Changing a configuration key without migration support.
- Changing a public event payload incompatibly.
- Moving a stable class without an alias or migration path.
- Making an optional dependency mandatory.
- Changing documented default security behaviour incompatibly.

Not every change is breaking. Compatibility review should distinguish:

- Source compatibility.
- Behavioural compatibility.
- Configuration compatibility.
- Dependency compatibility.
- Operational compatibility.

## 15. Deprecation Policy

A stable public API may be deprecated when:

- A safer or clearer replacement exists.
- The design blocks future development.
- The API creates security or correctness risk.
- A standard interoperability mechanism replaces it.
- A package boundary changes.

Requirements:

- Mark implemented deprecated APIs with `@deprecated`.
- Document the replacement.
- Add a changelog entry.
- Provide migration guidance.
- Keep the deprecated API functional where reasonably possible.
- Emit a development-time deprecation signal only when it does not disrupt normal production behaviour.
- Do not remove a stable deprecated API in a patch release.
- Normally keep a deprecated stable API for at least one minor release before removal.
- Removal normally occurs in the next major release.
- Security-critical exceptions must be documented explicitly.

Deprecation without a usable migration path is incomplete.

## 16. Experimental API Policy

RFC 0002 is reaffirmed:

- Experimental APIs must be clearly labelled.
- They may change in minor releases.
- Changes must be recorded in the changelog.
- Experimental APIs must not be presented as stable.
- Stable first-party contracts should not depend on experimental contracts.
- Promotion to stable requires documentation, tests and compatibility review.
- Removal of an experimental API does not require a new major release, but must not be silent.
- Experimental status should not be used indefinitely to avoid design decisions.

## 17. Internal API Policy

- Internal APIs carry no backward-compatibility guarantee.
- Implemented internal APIs should use `@internal`.
- Internal code may change in minor or patch releases when documented public behaviour remains compatible.
- Internal APIs must not be promoted as extension points.
- First-party packages should avoid depending on another package's internals.
- Tests for public behaviour should not unnecessarily bind to internal structure.

## 18. Security Release Policy

- Security fixes may be released outside the normal feature schedule.
- Security releases should contain the smallest practical change.
- A security patch may alter behaviour when necessary to close a vulnerability.
- Behaviour changes must be documented without exposing exploit-enabling detail before coordinated disclosure is complete.
- Supported stable release lines receive priority.
- Pre-release security fixes may be provided at maintainer discretion.
- Unsupported release lines are not guaranteed a fix.
- Security advisories should identify affected and fixed versions.
- Secrets, exploit payloads and private reporter information must not be placed in public release material.
- The private reporting guidance in `SECURITY.md` remains authoritative for vulnerability intake.

This policy does not promise a guaranteed response time or service-level agreement.

## 19. Release Support Policy

For the initial EvolvePHP 2 lifecycle:

- The current stable major is the primary maintained line.
- The newest stable minor receives normal maintenance.
- Older minors within the same major may receive security fixes when practical.
- Patch support for every historical minor is not guaranteed indefinitely.
- Pre-release versions receive best-effort fixes and no long-term support promise.
- Support status must be documented.
- A release line must not be described as supported merely because its source remains downloadable.
- A formal long-term-support promise requires a separate accepted RFC and resourcing decision.

Do not declare EvolvePHP 2.0 LTS.

## 20. Release Branches

### `2.x`

- Main development and integration branch for EvolvePHP 2.
- Future work targets this line through task branches and pull requests.
- It must remain reviewable and testable.

### Task Branches

- Created from the current target branch.
- Named by purpose.
- Merged through reviewed pull requests.
- Deleted after merge when no longer needed.

### Maintenance Branches

Potential format:

```text
2.0.x
2.1.x
```

Rules:

- Create a maintenance branch only when a stable line requires ongoing fixes.
- Do not create maintenance branches prematurely.
- Fixes should normally be developed against the active line and backported deliberately.
- Backports require tests.
- Do not merge a future feature into a maintenance branch accidentally.

### `master`

- Remains the EvolvePHP 1 legacy line.
- EvolvePHP 2 releases must not be tagged from `master`.

Do not create any release branch in this task.

## 21. Git Tags

Stable and pre-release tags use the semantic version exactly:

```text
2.0.0-alpha.1
2.0.0-beta.1
2.0.0-rc.1
2.0.0
```

Rules:

- Tags must be created from the intended EvolvePHP 2 release commit.
- Published tags are immutable.
- A mistaken tag must not be moved silently.
- Signed or verified tags are preferred where practical.
- Release notes must reference the tagged commit.
- The tag must not claim successful validation that was not executed.
- Legacy EvolvePHP 1 tags and EvolvePHP 2 tags must remain distinguishable by history and documentation.

Do not tag anything in this task.

## 22. Release Readiness

A stable EvolvePHP release requires:

- All required acceptance criteria for the release stage.
- Complete applicable automated test suite.
- CI success on supported PHP versions.
- Composer validation.
- Dependency audit.
- Static analysis where adopted.
- Public API review.
- Backward-compatibility review.
- Security review.
- Updated documentation.
- Updated changelog.
- Upgrade or migration notes where required.
- Confirmed package constraints.
- Confirmed licence metadata.
- Reproducible package build.
- No unresolved release-blocking issue.
- Maintainer approval.

Do not claim these processes currently exist.

## 23. Changelog Policy

- Every release has release notes.
- Material user-facing changes belong in `CHANGELOG.md`.
- Categories may include:

```text
Added
Changed
Deprecated
Removed
Fixed
Security
```

- Breaking changes must be clearly labelled.
- Deprecations must identify replacements.
- Security entries must avoid premature exploit disclosure.
- Internal-only changes may be omitted unless operationally relevant.
- Unreleased entries move into the released version section during release preparation.
- A published changelog entry must not be rewritten to conceal a prior change.
- Editorial corrections are allowed when they preserve historical meaning.

## 24. Release Documentation

Each published release should identify:

- Version.
- Release stage.
- Release date.
- Supported PHP versions.
- Supported package constraints.
- New features.
- Fixes.
- Deprecations.
- Breaking changes.
- Security changes where disclosure is appropriate.
- Upgrade instructions.
- Known limitations.
- Validation environment.
- Links to relevant RFCs.

A release must not claim support for a PHP version absent from the official validation matrix.

## 25. Dependency Compatibility

- Composer constraints must express tested compatibility.
- Dependencies must not be widened without validation.
- Dependencies must not be pinned unnecessarily when compatible ranges are safe.
- Major dependency upgrades require compatibility review.
- A dependency's own support policy does not automatically become EvolvePHP's policy.
- Optional dependencies must remain optional.
- Host-framework dependencies stay in Bridge packages.
- Runtime SDK dependencies stay in Runtime packages.
- Production packages must not depend on testing packages.
- Dependency conflicts must not be hidden through undocumented replacement rules.

## 26. Lock-File Policy

- The root development repository may use a lock file for reproducible contributor and CI tooling.
- Publishable library packages must declare correct dependency constraints independently of the root lock file.
- Consumers of library packages are not governed by the framework repository's lock file.
- Lock files must not conceal invalid package constraints.
- Lock-file changes require review.
- A generated lock file must not be committed accidentally during a documentation-only task.
- Exact monorepo lock-file implementation belongs to the repository-structure phase.

Do not create or modify `composer.lock`.

## 27. Continuous-Integration Policy

The future CI matrix must validate:

- PHP 8.4.
- PHP 8.5.
- Lowest supported dependency versions where practical.
- Current compatible dependency versions.
- Composer validation.
- Test suite.
- Static analysis when adopted.
- Coding standards when adopted.
- Package-boundary checks when implemented.
- Documentation-policy tests.
- Security or dependency audit where appropriate.

Rules:

- Required compatibility jobs must be blocking before stable release.
- Experimental PHP versions may be allowed to fail.
- Officially supported PHP versions must not be allowed to fail.
- CI must use real PHP runtimes.
- A green job with tests skipped does not prove compatibility.
- CI configuration belongs to a later implementation task.

Do not add CI in this task.

## 28. Release Failure And Rollback

- Published package versions and Git tags are immutable.
- A broken release is corrected with a new version.
- Do not overwrite a published archive.
- A critical release may be marked as discouraged or affected in documentation.
- Dependency constraints may exclude a known-broken version in a follow-up release.
- Security incidents follow the security-response process.
- Rollback of application deployments is an operator concern; framework release policy must provide enough information for informed rollback.
- A post-release review should document material release-process failures.

## 29. Governance

- RFC 0003 is authoritative for versioning, compatibility and release policy.
- RFC 0001 remains authoritative for product scope.
- RFC 0002 remains authoritative for package and public API boundaries.
- Material reversals require a superseding RFC.
- Supported-version claims require evidence.
- A package may not silently adopt a different compatibility policy.
- Release automation must implement this policy rather than redefine it.
- Emergency exceptions must be documented.
- Published versions and release history must not be rewritten.

## 30. Explicit Non-Goals

- This RFC does not change `composer.json`.
- It does not change the current PHP requirement.
- It does not add PHP 8.4 syntax.
- It does not add or remove dependencies.
- It does not create CI workflows.
- It does not create release branches.
- It does not create tags.
- It does not publish packages.
- It does not create a Composer lock file.
- It does not declare EvolvePHP 2.0 stable.
- It does not promise LTS.
- It does not guarantee compatibility with every future PHP 8 minor.
- It does not define exact external PHP end-of-life dates.
- It does not provide a support SLA.
- It does not modify EvolvePHP 1 support policy.
- It does not implement automated backward-compatibility tooling.
- It does not define the complete security-response process.
- It does not begin RFC 0004.

## 31. Consequences and Tradeoffs

### Positive Consequences

- Clear compatibility promises.
- Honest PHP support claims.
- Predictable semantic versioning.
- Safer deprecation and removal.
- Better release discipline.
- Reproducible compatibility evidence.
- Clear distinction between pre-release and stable guarantees.
- Stronger security-release handling.
- Better package coordination.
- Reduced pressure to preserve unsupported environments inside Core.

### Negative Consequences

- More CI and release-maintenance work.
- Supporting multiple PHP versions increases test cost.
- Deprecation periods slow removal of poor APIs.
- Package coordination adds operational overhead.
- Security exceptions may still require behaviour changes.
- Compatibility reviews slow releases.
- Maintaining older release lines consumes limited maintainer capacity.
- Strict release evidence may delay publication.

These costs are accepted and must remain visible.

## 32. Alternatives Considered

### Support Only The Newest PHP Version

Rejected because it would unnecessarily reduce adoption and make framework upgrades too tightly coupled to immediate PHP upgrades.

### Support PHP 7 Or PHP 8.0 In Evolve Core

Rejected because it conflicts with RFC 0001's security, typing, dependency and runtime-safety goals.

### Use Composer Acceptance As The Only Support Test

Rejected because dependency resolution does not prove runtime compatibility.

### Promise Support For Every PHP 8 Minor Automatically

Rejected because future compatibility must be validated, not assumed.

### Keep All First-Party Package Versions Identical

Rejected as a strict patch-level requirement because independently maintained packages may need separate patch releases.

Major-version alignment remains required.

### Permit Silent Breaking Changes During Beta

Rejected because beta users still need documented migration information.

Beta does not provide stable compatibility guarantees, but changes must remain visible.

### Create Maintenance Branches For Every Minor Immediately

Rejected because premature branches create unnecessary maintenance burden.

### Declare EvolvePHP 2.0 An LTS Release

Rejected because long-term support requires separate resourcing and governance.
