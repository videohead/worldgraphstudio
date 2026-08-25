# Template module

This directory owns the provider-neutral Template extension boundary:
scheduled provisioning and idempotent creation or update of provider-managed
generation Templates.

Start with the concise
[Adding Connections and Templates](../../../../../../about/Adding_Connections_and_Templates.md)
guide. The exhaustive provider contract is the
[Connection Adapter Development Specification](../../../../../../about/Connection_Adapter_Development_Specification.md).
Portable definition producers can validate against the
[provider Template definition schema](../../../../../../about/schemas/worldgraph-provider-template-definition.schema.json).

A Template says which provider operation or model runs, which registered
modality it implements, and which safe defaults and schema apply. It does not
own credentials or arbitrary endpoints.

Provider catalogs should call
`WorldGraph\Templates\Template_Repository::upsert_provider_template()` and use
the exact `(connection_id, provider_template_id)` identity. Provisioning must
be repeatable and must not erase operator-owned fields or delete a Template
merely because a remote catalog temporarily omits it.
