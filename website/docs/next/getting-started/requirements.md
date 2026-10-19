---
title: Requirements
description: Requirements for source review and framework development.
sidebar_position: 3
---

# Requirements

EvolvePHP 2 packages target **PHP 8.4 or later**. Official root quality CI currently covers PHP 8.4 and PHP 8.5. The repository development workflow uses **Composer 2** and **Git**. Run Composer with the actual target PHP runtime; the repository does not use a fake platform PHP setting to establish compatibility.

A source-preview contributor also needs the extensions required by the chosen package dependency graph. For example, the current root lockfile includes the MCP SDK, which requires fileinfo; the development environment uses Intl for some optional formatting work. Optional package extensions are not a single global requirement for every Evolve application. Review the relevant package composer.json and Composer's platform check for the composition you are testing.

The documentation site has its own requirements: Node 20 or later and npm. CI uses Node 24. Node is needed to build this manual, not to run EvolvePHP packages.

The [source-preview guide](source-preview.md) gives repository commands. It is separate from future published-package application installation.
