# EvolvePHP MCP

`evolvephp/mcp` is an optional, experimental EvolvePHP 2 package requiring PHP `^8.4`. Optional experimental MCP package and official PHP MCP SDK dependency foundation for EvolvePHP 2.

EvolvePHP 2 is pre-release. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp; packages are not yet independently published. This package uses the BSD-3-Clause licence in `LICENSE.md`.

This foundation depends on the official PHP MCP SDK `mcp/sdk` at `^0.8.1` for MCP protocol behavior rather than reimplementing MCP. The upstream SDK is pre-1.0, and the EvolvePHP MCP surface remains experimental during Beta.

The package currently reserves the `Evolve\Mcp\` namespace and has no Evolve-owned server, client or transport API. It performs no automatic filesystem discovery or hidden global registration. Server composition, STDIO and Streamable HTTP integration, remote client support, plugin and module contributions, security and authorization, and provider-neutral AI integration belong to later work.
