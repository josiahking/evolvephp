# Support Policy

## Support channels and report types

Use the appropriate channel for the type of request:

- Usage questions: ask through public repository discussions or issues when available.
- Bug reports: open a public issue with reproduction details when the report does not contain sensitive security information.
- Security reports: follow `SECURITY.md` and do not disclose vulnerabilities publicly.

## Version status

EvolvePHP 1 legacy support applies to the preserved `master` line. Maintainers may answer questions, review reports and consider narrowly scoped maintenance fixes, but EvolvePHP 1 is not recommended as the foundation for new production applications without a detailed security and compatibility review.

EvolvePHP 2 development is separate from EvolvePHP 1. EvolvePHP 2 is under development and should not be described as production-ready until a reviewed release says so.

## What maintainers may support

Maintainers may reasonably support:

- Clarifying documented EvolvePHP 1 behaviour.
- Reproducing legacy bugs with sufficient evidence.
- Reviewing security reports through the private process in `SECURITY.md`.
- Accepting documentation improvements that preserve historical accuracy.
- Considering narrowly scoped EvolvePHP 1 maintenance fixes when explicitly approved.
- Planning EvolvePHP 2 design work separately from legacy preservation.

## Out of scope

The following are out of scope unless a maintainer explicitly agrees otherwise:

- Free custom application development.
- Emergency production operations for private deployments.
- Broad modernization of EvolvePHP 1 during preservation-only tasks.
- Dependency upgrades without compatibility and licence review.
- Debugging reports that do not include enough reproduction information.
- Public discussion of suspected vulnerabilities.

Commercial or enterprise support may be considered as a future offering, but this repository does not currently claim that a commercial support program exists.

## Bug-report information

For bug reports, include:

- EvolvePHP commit SHA or branch.
- PHP version.
- Composer version and dependency-installation status.
- Operating system.
- Web server details, including Apache rewrite configuration or equivalent.
- Database type and version when database behaviour is involved.
- Relevant configuration values with secrets removed.
- Relevant logs with credentials, tokens and private data removed.
- Exact request URL, route, command or action that triggers the issue.
- Expected result.
- Actual result.
- Minimal reproducible example.

## Reproduction guidance

A useful reproduction should be as small as possible while still showing the issue. Include any route, controller, component, helper, configuration and command needed to reproduce the behaviour from a clean checkout.

Do not include passwords, API keys, private tokens, production session cookies, customer data or other sensitive information in public reports.
