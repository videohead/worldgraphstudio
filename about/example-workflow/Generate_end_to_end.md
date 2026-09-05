Project-level Generate should queue a complete rough cut, reuse identity/reference assets, maintain shot continuity, generate or graphically substitute soundtrack/title elements, then assemble a watchable deliverable while preserving per-item rerender controls and cancellation.

## Needs to be completed

- Prefer a capability-verified ComfyUI rough-cut assembly Template when the
  selected local Connection exposes a trusted, bounded World Graph FFmpeg
  assembly node. ComfyUI's generic HTTP API does not expose its FFmpeg binary,
  and the currently installed frame-batch nodes are not safe for a whole-story
  timeline because they decode the complete cut into memory. The node must
  accept a versioned, bounded manifest rather than raw FFmpeg arguments, limit
  inputs to ComfyUI-managed storage, construct an argument vector without a
  shell, expose its capability/version through ComfyUI node metadata, and
  return one downloadable video. The frozen batch state must retain the
  Connection, Template, workflow, manifest, upload, and remote prompt IDs so an
  uncertain dispatch can be reconciled instead of creating a duplicate cut.
- Run both WP-Cron workers without manual intervention and provision at least
  one runnable ComfyUI `video_to_video` Template with `start_frame` and
  `end_frame` bindings so the local demonstration acceptance pass covers the
  complete queue, first/last-frame generation, and final assembly.

## Available fallback

The resumable WordPress assembler is the verified fallback. FFmpeg runs in the
Docker Compose `wordpress` service, where PHP and WP-Cron execute; an
executable in the separate `node` container is not an automatic runtime
fallback without an authenticated job bridge. The repository installs FFmpeg
in `wordpress`, and the local acceptance pass assembled batch 506 as a
12-segment, 75-second, 1280×720 H.264/AAC rough cut with burned subtitles.

"I want to be able to import a story and be able to generate the whole story as a single demonstration video with complementary stills for the characters, audio, voiceover, music, sound etc where possible in the model, on screen titles or subtitle graphics where not possible.
Individual scenes and shots can be modified, updated, new text input and then re-rendered once there is an existing pass, but that first Generate pass should at least be able to be watched by a human end to end (even if there are flaws and obvious problems). If a character is re-used then the generated image for that character should at least be provided i2v, and first frame last frame should be used for shot sequences so it can be stitched together automatically.
An automatic stitching tool using ffmpeg would be great to have as a feature.
Getting at these settings and Generate option should be in the "Project" editor screen.
It's fine if this video takes hours and hours (or even days) to generate the whole story, we can put them in a queue and then stitch when complete. Stop items using the Generate queue if it's way off base from the user's intent."
