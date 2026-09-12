# Application Foundations

EvolvePHP 2 Alpha provides implemented foundations rather than a complete production web runtime.

Implemented application-facing areas include explicit configuration, a restricted service registry / container, and three service lifetimes: Application, Execution and Transient. Execution scopes provide one execution-local resolver, explicit reset participation and deterministic reset behavior. Execution orchestration creates an execution context, invokes the operation, closes the execution scope and reports success, failure, cleanup/reset failure and process reuse decisions without ambient current-execution state.

HTTP foundations include a PSR-15 middleware pipeline, route definitions, route collections, route matching, routed dispatch and typed routing failures. The HTTP kernel wraps a composed PSR-15 handler in Core execution orchestration and returns an execution outcome. Response resolution is explicit through the response resolver, and response emission is a separate boundary.

The concrete web/SAPI runtime remains deferred. EvolvePHP 2 does not yet supply concrete SAPI request creation, concrete response emission, complete runtime adapters or normal production web bootstrap. Applications or later runtime work must provide concrete PSR-7 requests and responses, runtime policy and transmission.

Component foundations include component identity, module and plugin descriptors, dependency/capability graph declarations, Core-owned graph resolution, restricted service registration, component lifecycle orchestration, Composer plugin discovery and application-controlled enablement. Disabled components stay inert, and enabled components are validated before entry points are created.

CLI command foundations include a command registry, command runner and runtime-neutral command input/output abstractions. The skeleton composes `doctor` and `route:list` explicitly. Development-only generators from `evolvephp/dev-tools` can create module and plugin starter files when installed as development dependencies; generated code remains application-owned and is not auto-enabled.

These foundations are experimental and pre-release. They describe what the repository currently implements, while web runtime adapters, deployment scaffolding, automatic route discovery, dotenv loading, production bootstrap, retries, process recycling and complete application conventions remain deferred.
