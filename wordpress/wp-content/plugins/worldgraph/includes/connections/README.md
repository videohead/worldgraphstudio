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

In generation metadata, `generation.adapter` is only a fixed, sanitized marker
string. Use the separate callable `generation.adapter_resolver` when trusted
saved Connection or Template state must select the marker dynamically; do not
put a callable in `generation.adapter`. The strict authoring contract treats
the two fields as mutually exclusive.

A Connection whose health status is `disabled` cannot be health-tested. Core
does not invoke its `callbacks.test` callable or replace the disabled status;
an administrator must enable and save the Connection before testing it.
