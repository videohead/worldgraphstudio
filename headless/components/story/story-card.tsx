import Link from "next/link";
import type { StoryItem, StoryMedia, StoryMetric } from "@/lib/worldgraph";
import { storyFieldText, storyTermNames } from "@/lib/worldgraph";
import { CharacterFlipCard } from "@/components/story/character-flip-card";
import { MediaPlayer } from "@/components/story/media-player";

function firstField(item: StoryItem, keys: string[]): string {
  for (const key of keys) {
    const value = storyFieldText(item, key);
    if (value) {
      return value;
    }
  }
  return item.excerptHtml;
}

function firstMedia(item: StoryItem, kind?: "image" | "audio" | "video"): StoryMedia | undefined {
  if (!kind) {
    return item.display.media[0];
  }
  return item.display.media.find((media) => media.mimeType.startsWith(`${kind}/`));
}

function StatusPill({ children }: { children: string }) {
  return (
    <span className="rounded-full border border-wg-sepia/45 bg-wg-sepia/10 px-2.5 py-1 text-xs font-semibold text-wg-espresso">
      {children.replace(/[_-]+/g, " ")}
    </span>
  );
}

function projectMetricValue(metric: StoryMetric): string | number {
  if (metric.key === "density" && typeof metric.value === "number") {
    return `${(metric.value * 100).toFixed(1)}%`;
  }
  return metric.value;
}

function CardImage({ media, alt }: { media?: StoryMedia; alt: string }) {
  if (!media) {
    return (
      <div className="flex aspect-[16/9] items-center justify-center bg-wg-espresso/8 font-headline text-sm font-bold uppercase tracking-[0.2em] text-wg-espresso/40">
        {alt}
      </div>
    );
  }
  return <MediaPlayer media={media} compact />;
}

function ProtectedStoryCard({ item, href }: { item: StoryItem; href: string }) {
  return (
    <article className="flex min-h-64 flex-col justify-between rounded-wg border-2 border-wg-espresso bg-wg-ivory p-6 shadow-wg">
      <div className="space-y-3">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
          Protected story item
        </p>
        <h2 className="text-2xl text-wg-espresso">
          <Link
            href={href}
            className="no-underline hover:text-wg-blueprint"
            dangerouslySetInnerHTML={{ __html: item.titleHtml }}
          />
        </h2>
      </div>
      <p className="border-t border-wg-sepia/35 pt-4 text-sm leading-relaxed text-wg-charcoal/70">
        Details and media are not available in this public view.
      </p>
    </article>
  );
}

function ProjectCard({ item, href }: { item: StoryItem; href: string }) {
  const project = item.display.project;
  const stage = project?.productionStage || storyFieldText(item, "production_stage");
  const statuses = storyTermNames(item, "worldgraph_status");
  const summary = firstField(item, ["description"]);

  return (
    <article className="overflow-hidden rounded-wg border-2 border-wg-espresso bg-wg-ivory shadow-wg">
      <CardImage media={firstMedia(item, "image")} alt="Project" />
      <div className="space-y-4 p-5">
        <div className="flex flex-wrap gap-2">
          {stage && <StatusPill>{stage}</StatusPill>}
          {statuses.map((status) => (
            <StatusPill key={status}>{status}</StatusPill>
          ))}
        </div>
        <h2 className="text-2xl text-wg-espresso">
          <Link
            href={href}
            className="no-underline hover:text-wg-blueprint"
            dangerouslySetInnerHTML={{ __html: item.titleHtml }}
          />
        </h2>
        {summary && (
          <div
            className="line-clamp-3 text-sm leading-relaxed text-wg-charcoal/80"
            dangerouslySetInnerHTML={{ __html: summary }}
          />
        )}
        {project?.metrics.length ? (
          <dl className="grid grid-cols-2 gap-2 border-t border-wg-sepia/30 pt-3">
            {project.metrics.slice(0, 4).map((metric) => (
              <div key={metric.key}>
                <dt className="text-[0.68rem] font-semibold uppercase tracking-wider text-wg-charcoal/60">
                  {metric.label}
                </dt>
                <dd className="font-headline text-xl font-semibold text-wg-espresso">
                  {projectMetricValue(metric)}
                </dd>
              </div>
            ))}
          </dl>
        ) : null}
      </div>
    </article>
  );
}

function WorldCard({ item, href }: { item: StoryItem; href: string }) {
  const synopsis = firstField(item, ["synopsis"]);
  const themes = storyFieldText(item, "themes");

  return (
    <article className="grid overflow-hidden rounded-wg border-2 border-wg-espresso bg-wg-ivory shadow-wg md:grid-cols-[1.25fr_1fr]">
      <CardImage media={firstMedia(item, "image")} alt="World" />
      <div className="flex flex-col justify-between gap-5 p-6">
        <div className="space-y-3">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
            Story world
          </p>
          <h2 className="text-3xl text-wg-espresso">
            <Link
              href={href}
              className="no-underline hover:text-wg-blueprint"
              dangerouslySetInnerHTML={{ __html: item.titleHtml }}
            />
          </h2>
          {synopsis && (
            <div
              className="line-clamp-5 text-sm leading-relaxed text-wg-charcoal/80"
              dangerouslySetInnerHTML={{ __html: synopsis }}
            />
          )}
        </div>
        {themes && (
          <div className="border-t border-wg-sepia/35 pt-3">
            <p className="font-headline text-xs font-bold uppercase tracking-wider text-wg-espresso">
              Themes
            </p>
            <div
              className="mt-1 line-clamp-2 text-sm text-wg-charcoal/70"
              dangerouslySetInnerHTML={{ __html: themes }}
            />
          </div>
        )}
      </div>
    </article>
  );
}

function SceneCard({ item, href }: { item: StoryItem; href: string }) {
  const number = storyFieldText(item, "scene_number");
  const summary = firstField(item, ["summary"]);
  const time = storyFieldText(item, "time_of_day");
  const tone = storyFieldText(item, "emotional_tone");
  const shotCount = item.display.shots?.length ?? 0;

  return (
    <article className="grid overflow-hidden rounded-wg border-2 border-wg-espresso bg-wg-ivory shadow-wg sm:grid-cols-[10rem_1fr]">
      <CardImage media={firstMedia(item, "image")} alt="Scene" />
      <div className="space-y-3 p-5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
            Scene {number || "—"}
          </span>
          {time && <StatusPill>{time}</StatusPill>}
          {tone && <StatusPill>{tone}</StatusPill>}
        </div>
        <h2 className="text-2xl text-wg-espresso">
          <Link
            href={href}
            className="no-underline hover:text-wg-blueprint"
            dangerouslySetInnerHTML={{ __html: item.titleHtml }}
          />
        </h2>
        {summary && (
          <div
            className="line-clamp-3 text-sm leading-relaxed text-wg-charcoal/80"
            dangerouslySetInnerHTML={{ __html: summary }}
          />
        )}
        <p className="font-headline text-xs font-semibold uppercase tracking-wider text-wg-charcoal/60">
          {shotCount
            ? `${shotCount} ordered shot${shotCount === 1 ? "" : "s"}`
            : "Open the published shot sequence"}
        </p>
      </div>
    </article>
  );
}

function PropCard({ item, href }: { item: StoryItem; href: string }) {
  const purpose = storyFieldText(item, "purpose");
  const summary = firstField(item, ["description", "notes"]);
  const media = item.display.media.slice(0, 3);

  return (
    <article className="overflow-hidden rounded-wg border-2 border-wg-espresso bg-wg-ivory shadow-wg">
      <div className="grid grid-cols-3 bg-wg-espresso/8">
        {(media.length ? media : [undefined]).map((itemMedia, index) => (
          <div key={itemMedia ? `${itemMedia.id}:${itemMedia.url}` : "empty"} className="min-w-0">
            <CardImage media={itemMedia} alt={index === 0 ? "Prop" : `Prop view ${index + 1}`} />
          </div>
        ))}
      </div>
      <div className="space-y-3 p-5">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
          {purpose || "Story prop"}
        </p>
        <h2 className="text-2xl text-wg-espresso">
          <Link
            href={href}
            className="no-underline hover:text-wg-blueprint"
            dangerouslySetInnerHTML={{ __html: item.titleHtml }}
          />
        </h2>
        {summary && (
          <div
            className="line-clamp-3 text-sm leading-relaxed text-wg-charcoal/80"
            dangerouslySetInnerHTML={{ __html: summary }}
          />
        )}
        {item.display.media.length > 1 && (
          <p className="text-xs font-semibold text-wg-charcoal/60">
            {item.display.media.length} visual studies
          </p>
        )}
      </div>
    </article>
  );
}

function SoundCard({ item, href }: { item: StoryItem; href: string }) {
  const soundTypes = storyTermNames(item, "worldgraph_sound_type");
  const audio = firstMedia(item, "audio");
  const spokenText = storyFieldText(item, "spoken_text");
  const lyrics = storyFieldText(item, "lyrics");
  const isSong = soundTypes.some((type) => type.toLowerCase() === "music") || Boolean(lyrics);

  return (
    <article className="space-y-4 rounded-wg border-2 border-wg-espresso bg-wg-ivory p-5 shadow-wg">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
          {isSong ? "Song" : soundTypes.join(" • ") || "Sound cue"}
        </p>
        {storyFieldText(item, "duration") && (
          <StatusPill>{storyFieldText(item, "duration")}</StatusPill>
        )}
      </div>
      <h2 className="text-2xl text-wg-espresso">
        <Link
          href={href}
          className="no-underline hover:text-wg-blueprint"
          dangerouslySetInnerHTML={{ __html: item.titleHtml }}
        />
      </h2>
      {audio ? (
        <MediaPlayer media={audio} />
      ) : (
        <p className="rounded-wg bg-wg-espresso/5 px-3 py-2 text-sm text-wg-charcoal/65">
          Audio render pending
        </p>
      )}
      {(lyrics || spokenText) && (
        <div className="line-clamp-3 whitespace-pre-line text-sm leading-relaxed text-wg-charcoal/75">
          {lyrics || spokenText}
        </div>
      )}
    </article>
  );
}

export function StoryCard({ item }: { item: StoryItem }) {
  const href = `/story/${item.storyType}/${item.slug}`;

  if (item.protected) {
    return <ProtectedStoryCard item={item} href={href} />;
  }

  if (item.storyType === "characters") {
    return (
      <CharacterFlipCard
        href={href}
        titleHtml={item.titleHtml}
        name={item.titleText}
        media={firstMedia(item, "image")}
        age={storyFieldText(item, "age") || undefined}
        roles={storyTermNames(item, "worldgraph_character_role")}
        biographyHtml={firstField(item, ["biography"]) || undefined}
        personalityHtml={storyFieldText(item, "personality") || undefined}
        motivationHtml={storyFieldText(item, "motivation") || undefined}
      />
    );
  }

  if (item.storyType === "projects") {
    return <ProjectCard item={item} href={href} />;
  }
  if (item.storyType === "worlds") {
    return <WorldCard item={item} href={href} />;
  }
  if (item.storyType === "scenes") {
    return <SceneCard item={item} href={href} />;
  }
  if (item.storyType === "props") {
    return <PropCard item={item} href={href} />;
  }
  return <SoundCard item={item} href={href} />;
}
