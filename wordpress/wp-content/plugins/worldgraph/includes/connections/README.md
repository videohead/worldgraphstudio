# Connection module

This directory owns the provider-neutral Connection extension boundary:
adapter metadata and conditional loading, callback-driven health testing, and
generation-client selection. Persisted Connection reads and resolution remain
in `includes/utils/connection_repository.php`.

Start with the concise
[Adding Connections and Templates](../../../../../../about/Adding_Connections_and_Templates.md)
guide. The exhaustive REST, API, MCP, security, lifecycle, and compatibility
contract is the
[Connection Adapter Development Specification](../../../../../../about/Connection_Adapter_Development_Specification.md).

A Connection says where WordPress connects and which account or environment it
uses. It does not define a workflow. Register provider behavior through
`worldgraph_conn_adapters`; keep credentials on the Connection, and keep
provider operations in Templates.

Endpoint metadata alone is non-executable. Do not infer authentication, MCP
protocol behavior, tools, generation semantics, or safe media handling from an
API or MCP URL.
