---
title: Source Preview
description: What developers can currently run from the EvolvePHP repository.
sidebar_position: 4
---

# Source preview and installation status

The current public artifact is the source repository. First-party packages are not yet independently published, and public Packagist installation or a public composer create-project flow for the skeleton is **not available**. Do not treat the root project as an application.

For contributor and framework development, the root README documents this flow:

    git clone https://github.com/josiahking/evolvephp.git
    cd evolvephp
    git branch --show-current
    composer install
    composer quality

Verify the checkout is on 2.x before using these commands. Composer installs the committed root lockfile and local path packages for repository development. This is not a published-package installation recipe.

The skeleton in the repository is the intended application template. Its own CLI composition registers doctor and route:list, with development-only generator commands when DevTools is present. The route configuration initially returns an empty collection; route:list then says that no routes are configured. Repository-owned release validation exercises local prerelease create-project behavior with public package/network access disabled. It does not prove a public installer exists.

The local offline skeleton validation also checks explicit module composition. It generates an application-owned module, installs a separate library module from a local Composer path repository, and verifies the installed class originates under the generated application's vendor directory. The application explicitly selects enabled definitions for the Kernel. Module-provided localization and migrations require explicit application composition; migrations are not run during boot. This check does not establish public package availability.

If you are exploring architecture before publication, start with [architecture](../concepts/architecture.md) and the [package map](../packages/overview.md). Application installation instructions will be added only after package publication is verified.
