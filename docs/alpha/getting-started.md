# Alpha Getting Started

EvolvePHP 2 is a modernization-first PHP framework project for building modular applications and evolving existing PHP systems without a full rewrite. The current Alpha documentation describes the source-preview state of the repository as it exists now.

EvolvePHP 2 requires PHP 8.4. The current official framework CI evidence covers PHP 8.4 and PHP 8.5 for the root quality pipeline, tooling and package foundation.

For repository development you need PHP 8.4, Composer and Git. Cloning the repository root and running Composer quality commands is contributor and framework development setup, not the public application installation flow.

An Evolve application starts from the skeleton application template. The skeleton owns its CLI composition, application namespace, command configuration and route configuration. Public package publication is not complete: first-party packages are not yet independently published, and public create-project installation is not yet available.

This is a source-preview guide. Future published-package installation will be documented when package publication begins. This page does not provide a public package-install command and does not describe a manual local Composer workaround as an application installation procedure.

Current skeleton CLI capabilities include:

- `doctor`, for the configured runtime and Composer extension checks.
- `route:list`, for listing explicitly configured routes.

Route configuration begins explicitly in `config/routes.php`. The initial route collection may be empty, and an empty skeleton reports `No routes are configured.` through `route:list`.

Continue with [Application foundations](application-foundations.md) for the implemented framework surface, and [Status and limitations](status-and-limitations.md) for Alpha stability, publication and runtime boundaries.
