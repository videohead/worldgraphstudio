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

When an adapter declares `templates.status_meta_prefix`, the generic
`Template_Manager` is authoritative for `<prefix>_synced_at` and
`<prefix>_error`. A completed provisioning pass stamps the sync time and clears
a stale error unless the result contains a non-empty `warning`; scheduling or
provisioning failure records an actionable error without advancing the sync
time. New provisioners should return `WP_Error` for failure and rely on the
manager for these writes. Bundled legacy hooks and direct health-test entry
points may mirror the same keys for backward compatibility.
