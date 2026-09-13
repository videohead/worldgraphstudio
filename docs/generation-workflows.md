# Generation Workflows

Generation workflows connect a Story Graph record to an optional image, audio,
music, speech, or video service. World Graph Studio stores the request, tracks
the job, imports completed media when supported, and records provenance with
the resulting Asset.

## The basic workflow

1. An administrator creates and tests a provider Connection.
2. The Connection provisions or enables reviewed Templates for supported
   operations.
3. A creator opens a relevant World Graph record and chooses a Template.
4. The creator reviews the prompt and required inputs, including reference
   media, before submitting.
5. WordPress queues the request and processes it through WP-Cron.
6. The creator checks job status and reviews the imported result.
7. The accepted Asset remains related to its story or production context.

Connections contain service and authentication settings. Templates describe a
specific allowed operation and its inputs. Keeping them separate lets a team
reuse a provider without rebuilding every creative workflow.

## Available approaches

The current system includes adapters for local ComfyUI and several optional
hosted REST or MCP providers. Each supports a particular set of media and
operations; no single Connection automatically enables every modality.

You can also generate media manually in an external application and upload it
to the WordPress Media Library. Add its source, rights, prompt, model, or other
provenance information when creating the Asset.

## Costs, rights, and review

World Graph Studio does not sell generation credits. External providers may
charge per request or require subscriptions. Confirm cost and input settings
before submission, especially for video and other expensive operations.

Generated media may be inaccurate, unsafe, non-unique, or subject to provider
and model licenses. Obtain consent before using a person's voice or likeness,
and verify the rights needed for publishing and distribution.

## If a job does not finish

Confirm that the Connection still passes its test, the required Template and
model are available, and the WordPress site has a reliable WP-Cron runner.
Some providers finish work asynchronously, so closing the browser does not
necessarily cancel a submitted provider job.

For exact provider capabilities and limitations, see the
[integration catalog](../about/Integration_Catalog.md).
