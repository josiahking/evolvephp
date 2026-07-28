# Security Policy

## Supported versions

| Line | Status | Security support |
| --- | --- | --- |
| EvolvePHP 1 / `master` | Legacy, maintenance-only | Security fixes may be considered for verified issues affecting preserved EvolvePHP 1 users. |
| EvolvePHP 2 | Under development, not production-ready | Security design work is active, but EvolvePHP 2 should not be treated as production-ready until a release and support policy says so. |

## Reporting vulnerabilities

Do not disclose security vulnerabilities through public GitHub issues, public discussions or public pull requests.

Report suspected vulnerabilities privately to the maintainer using the public repository contact details currently listed in `composer.json`:

```text
josiahaccounts@gmail.com
```

If a private GitHub security advisory channel becomes available for this repository, prefer that channel.

## What to include

Please include as much of the following as possible:

- A clear description of the vulnerability.
- Affected EvolvePHP line or commit SHA.
- Affected file, route, component, helper or configuration.
- Steps to reproduce the issue.
- Proof-of-concept code or request details when safe to share privately.
- Expected impact and likely severity.
- PHP version, server type and database details if relevant.
- Any logs with secrets, credentials, tokens and private data removed.
- Whether the issue appears to affect EvolvePHP 1, planned EvolvePHP 2 work or both.

## Acknowledgement

Maintainers should acknowledge receipt when they are able to review the report. This policy does not promise a guaranteed response time or service-level agreement.

## Coordinated disclosure

Please allow maintainers reasonable time to investigate, validate and prepare a fix or advisory before public disclosure. Public disclosure should be coordinated so users have a practical path to understand and respond to the issue.

## Scope

In scope:

- Vulnerabilities in EvolvePHP framework code.
- Vulnerabilities in default configuration and documented framework usage.
- Security problems caused by framework-provided helpers, routing, sessions, views, logging or database foundations.

Out of scope:

- Issues caused only by a user's private application code.
- Reports that require access to systems not owned by the reporter.
- Denial-of-service testing against live services without permission.
- Dependency vulnerabilities already publicly tracked upstream unless there is an EvolvePHP-specific impact or mitigation.

Security fixes will be prioritised according to severity, exploitability and affected supported versions.
