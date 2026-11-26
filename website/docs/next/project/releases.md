---
title: Releases
description: Current EvolvePHP 2 release and support state.
sidebar_position: 1
---

# Releases

The repository records [2.0.0-alpha.1](https://github.com/josiahking/evolvephp/blob/2.x/docs/releases/2.0.0-alpha.1.md), tagged on 2026-09-13, as a historical prerelease. [GitHub Releases](https://github.com/josiahking/evolvephp/releases) is the public release listing. The current 2.x branch has continued development since that tag, so **Next** documentation describes current source and is not a reconstruction of Alpha. See the [documentation versioning policy](documentation-versioning.md).

EvolvePHP 2 remains experimental and not production-ready. The original 34 first-party packages have public Beta distributions, and the separate skeleton supports public `composer create-project` installation. Framework 2.0.0-beta.1 was published as a public GitHub prerelease on November 23, 2026. Prerelease support is best effort, not an LTS promise or production SLA. See the repository [support policy](https://github.com/josiahking/evolvephp/blob/2.x/SUPPORT.md) and [security policy](https://github.com/josiahking/evolvephp/blob/2.x/SECURITY.md) for reporting boundaries.

The current `2.x` source manifests enumerate 37 packages, including source-only Audit Log, Webhooks and API Keys; this does not expand the published original 34-package Beta set. Offline `composer release:plan` and synthetic `2.0.0-beta.1` consumer tools remain available, while independent public Composer installation of the original 34 packages has been verified. Planning requires a clean committed source and preserves Git state. See the [development guide](https://github.com/josiahking/evolvephp/blob/2.x/DEVELOPMENT.md#local-beta-distribution-planning) for source commit/tree review, root stability requirements and the separate authorization required before publication.

A separate offline pilot executor preflight still checks saved plans against local source and cannot publish packages or tags. The original 34 package Beta distributions and standalone skeleton are publicly available. Framework 2.0.0-beta.1 was published as a public GitHub prerelease on November 23, 2026. Publication of the additional source-only packages remains independently controlled. Publishing an additional package alone does not constitute a coordinated framework release. The coordinated framework release is a distinct decision tracked through GitHub Releases.

Do not use a source-branch package feature to infer that the earlier Alpha tag included it. Consult release notes and the tagged source for historical behavior.
