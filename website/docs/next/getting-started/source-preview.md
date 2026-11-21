---
title: Source Preview
description: What developers can currently run from the EvolvePHP repository.
sidebar_position: 4
---

# Source preview and installation status

The current `2.x` repository remains the development source. The original 34 first-party Beta packages and the separately published `evolvephp/skeleton:2.0.0-beta.1` are publicly available through Packagist. Do not treat the root project as an application.

For contributor and framework development, the root README documents this flow:

    git clone https://github.com/josiahking/evolvephp.git
    cd evolvephp
    git branch --show-current
    composer install
    composer quality

Verify the checkout is on 2.x before using these commands. Composer installs the committed root lockfile and local path packages for repository development. This is not a published-package installation recipe.

The skeleton in the repository is the intended application template. Its own CLI composition registers doctor and route:list, with development-only generator commands when DevTools is present. The route configuration initially returns an empty collection; route:list then says that no routes are configured. Repository-owned release validation exercises local prerelease create-project behavior with public package/network access disabled. This offline check alone does not prove public availability; separate live external Composer validation has established the public Beta installer.

The local offline skeleton validation also checks explicit module composition. It generates an application-owned module, installs a separate library module from a local Composer path repository, and verifies the installed class originates under the generated application's vendor directory. The application explicitly selects enabled definitions for the Kernel. Module-provided localization and migrations require explicit application composition; migrations are not run during boot. This offline check alone does not establish public package availability; independent public Composer consumer validation is accepted separately.

For public Beta installation, run `composer create-project evolvephp/skeleton my-app 2.0.0-beta.1` and follow the [standalone skeleton README](https://github.com/josiahking/evolvephp-skeleton). The [architecture](../concepts/architecture.md) and [package map](../packages/overview.md) describe development source and package boundaries.
