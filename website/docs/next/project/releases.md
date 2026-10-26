---
title: Releases
description: Current EvolvePHP 2 release and support state.
sidebar_position: 1
---

# Releases

The repository records [2.0.0-alpha.1](https://github.com/josiahking/evolvephp/blob/2.x/docs/releases/2.0.0-alpha.1.md), tagged on 2026-09-13, as a historical prerelease. [GitHub Releases](https://github.com/josiahking/evolvephp/releases) is the public release listing. The current 2.x branch has continued development since that tag, so **Next** documentation describes current source and is not a reconstruction of Alpha. See the [documentation versioning policy](documentation-versioning.md).

EvolvePHP 2 remains experimental and not production-ready. First-party packages are not yet independently published, and a public create-project installation is not claimed. Current Alpha support is best effort, not an LTS promise or production SLA. See the repository [support policy](https://github.com/josiahking/evolvephp/blob/2.x/SUPPORT.md) and [security policy](https://github.com/josiahking/evolvephp/blob/2.x/SECURITY.md) for reporting boundaries.

Local distribution readiness now includes `composer release:plan`, a deterministic offline plan for 34 proposed split-repository destinations, plus synthetic Beta consumer validation using `2.0.0-beta.1`. The proposed version is for simulation only; no Beta release or Packagist registration is claimed. Planning requires a clean committed source and preserves Git state. See the [development guide](https://github.com/josiahking/evolvephp/blob/2.x/DEVELOPMENT.md#local-beta-distribution-planning) for source commit/tree review, root stability requirements and the separate authorization required before publication.

Do not use a source-branch package feature to infer that the earlier Alpha tag included it. Consult release notes and the tagged source for historical behavior.
