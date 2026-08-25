import Link from "next/link";
import { MediaGallery } from "@/components/story/media-gallery";
import type {
  StoryConnectedEntity,
  StoryDevelopment,
  StoryItem,
  StoryMetric,
  StoryShot,
} from "@/lib/worldgraph";
import {
  storyField,
  storyFieldText,
  storyResourceConfig,
  storyTermNames,
} from "@/lib/worldgraph";

type DialogueEntry = {
  speaker: string;
  line: string;
  description: string;
  sequence: number;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function formatValue(value: string): string {
  return value.replace(/_+/g, " ");
}

function field(item: StoryItem, ...keys: string[]): string {
  for (const key of keys) {
    const value = storyFieldText(item, key);
    if (value) {
      return value;
    }
  }
  return "";
}

function DetailFacts({ facts }: { facts: Array<[string, string]> }) {
  const visibleFacts = facts.filter(([, value]) => value);
  if (!visibleFacts.length) {
    return null;
  }

  return (
    <dl className="grid gap-px overflow-hidden rounded-wg border border-wg-sepia/40 bg-wg-sepia/40 sm:grid-cols-2">
      {visibleFacts.map(([label, value]) => (
        <div key={label} className="bg-wg-ivory px-4 py-3">
          <dt className="font-headline text-[0.7rem] font-bold uppercase tracking-[0.16em] text-wg-charcoal/60">
            {label}
          </dt>
          <dd className="mt-1 text-sm font-semibold capitalize text-wg-espresso">
            {formatValue(value)}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function HtmlSection({ title, html }: { title: string; html: string }) {
  if (!html) {
    return null;
  }

  return (
    <section className="space-y-3">
      <h2 className="border-b border-wg-sepia/40 pb-2 text-2xl text-wg-espresso">
        {title}
      </h2>
      <div
        className="prose prose-headings:font-headline prose-headings:text-wg-espresso prose-a:text-wg-blueprint max-w-none text-wg-charcoal/85"
        dangerouslySetInnerHTML={{ __html: html }}
      />
    </section>
  );
}

function TextSection({ title, text }: { title: string; text: string }) {
  if (!text) {
    return null;
  }

  return (
    <section className="space-y-3">
      <h2 className="border-b border-wg-sepia/40 pb-2 text-2xl text-wg-espresso">
        {title}
      </h2>
      <p className="whitespace-pre-wrap leading-relaxed text-wg-charcoal/85">{text}</p>
    </section>
  );
}

function metricValue(metric: StoryMetric): string | number {
  if (metric.key === "density" && typeof metric.value === "number") {
    return `${(metric.value * 100).toFixed(1)}%`;
  }
  if (typeof metric.value === "number" && !Number.isInteger(metric.value)) {
    return metric.value.toFixed(2);
  }
  return metric.value;
}

function Metrics({
  metrics,
  title = "Published story analysis",
}: {
  metrics: StoryMetric[];
  title?: string;
}) {
  if (!metrics.length) {
    return null;
  }

  return (
    <section className="space-y-3">
      <h2 className="text-2xl text-wg-espresso">{title}</h2>
      <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric) => (
          <div
            key={metric.key}
            className="rounded-wg border border-wg-sepia/45 bg-white/45 p-4"
          >
            <dt className="font-headline text-xs font-bold uppercase tracking-wider text-wg-charcoal/60">
              {metric.label}
            </dt>
            <dd className="mt-1 break-words font-headline text-3xl font-semibold text-wg-espresso">
              {metricValue(metric)}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

function ConnectedEntities({ entities }: { entities: StoryConnectedEntity[] }) {
  if (!entities.length) {
    return null;
  }

  return (
    <section className="space-y-3">
      <h2 className="text-2xl text-wg-espresso">Most connected story items</h2>
      <ol className="grid gap-2 sm:grid-cols-2">
        {entities.map((entity) => (
          <li
            key={`${entity.type}:${entity.id}`}
            className="flex items-center justify-between gap-4 rounded-wg border border-wg-sepia/40 bg-white/40 px-4 py-3"
          >
            <span>
              <span className="block font-semibold text-wg-espresso">{entity.name}</span>
              {entity.type && (
                <span className="block text-xs capitalize text-wg-charcoal/55">
                  {formatValue(entity.type.replace(/^worldgraph_/, ""))}
                </span>
              )}
            </span>
            <span className="shrink-0 font-headline text-2xl font-semibold text-wg-sepia">
              {entity.connectionCount}
            </span>
          </li>
        ))}
      </ol>
    </section>
  );
}

function DevelopmentCompass({ development }: { development?: StoryDevelopment }) {
  if (!development) {
    return null;
  }

  return (
    <section
      aria-labelledby="development-compass-heading"
      className="space-y-5 rounded-wg border-2 border-wg-espresso bg-white/45 p-6 shadow-wg"
    >
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="max-w-3xl">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
            Where the story could open next
          </p>
          <h2 id="development-compass-heading" className="mt-1 text-3xl text-wg-espresso">
            Development Compass
          </h2>
          <p className="mt-2 text-sm leading-relaxed text-wg-charcoal/75">
            These questions come from published Story Graph connections. They invite
            exploration; they do not score the story or prescribe a plot.
          </p>
        </div>
        <span className="rounded-full border border-wg-sepia/50 bg-wg-sepia/10 px-3 py-1 font-headline text-xs font-bold uppercase tracking-wider text-wg-espresso">
          {development.phase.label}
        </span>
      </div>

      <p className="text-base leading-relaxed text-wg-charcoal/80">
        {development.phase.summary}
      </p>
      {development.hasMore && (
        <p className="text-xs font-semibold text-wg-charcoal/75">
          Showing {development.opportunities.length} of {development.totalOpportunities} prompts
          from the published graph.
        </p>
      )}

      {development.opportunities.length ? (
        <ol className="grid gap-4 lg:grid-cols-2">
          {development.opportunities.map((opportunity) => (
            <li
              key={opportunity.id}
              className="flex flex-col rounded-wg border border-wg-sepia/45 bg-wg-ivory p-5"
            >
              <div className="flex flex-wrap gap-2 font-headline text-[0.65rem] font-bold uppercase tracking-[0.14em] text-wg-charcoal/75">
                <span>{formatValue(opportunity.type)}</span>
                <span aria-hidden="true">·</span>
                <span>{opportunity.priority} priority</span>
              </div>
              <h3 className="mt-2 text-xl text-wg-espresso">{opportunity.title}</h3>
              <p className="mt-3 text-sm leading-relaxed text-wg-charcoal/75">
                <span className="font-semibold text-wg-charcoal/80">Graph evidence: </span>
                {opportunity.evidence}
              </p>
              <blockquote className="mt-4 border-l-4 border-wg-sepia pl-4 text-base leading-relaxed text-wg-espresso">
                {opportunity.question}
              </blockquote>
            </li>
          ))}
        </ol>
      ) : (
        <div className="rounded-wg border border-wg-sepia/40 bg-wg-sepia/10 px-5 py-4 text-sm text-wg-charcoal/70">
          No structural prompts surfaced from the published graph. A closer creative
          reading may still reveal other directions.
        </div>
      )}

      {development.elementsToDevelop.length > 0 && (
        <div className="space-y-3 border-t border-wg-sepia/40 pt-5">
          <h3 className="text-xl text-wg-espresso">Elements to bring forward</h3>
          <ul className="flex flex-wrap gap-2" aria-label="Story elements to develop">
            {development.elementsToDevelop.map((element) => (
              <li
                key={`${element.type}:${element.id}`}
                className="rounded-full border border-wg-sepia/45 bg-white/55 px-3 py-1.5 text-sm text-wg-espresso"
              >
                <span className="font-semibold">{element.name}</span>
                <span className="ml-1 capitalize text-wg-charcoal/75">
                  · {formatValue(element.type.replace(/^worldgraph_/, ""))}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

function ProjectDetail({ item }: { item: StoryItem }) {
  const project = item.display.project;
  const status = project?.status || storyTermNames(item, "worldgraph_status").join(", ");
  const stage = project?.productionStage || field(item, "production_stage");
  const frameWidth = field(item, "frame_width");
  const frameHeight = field(item, "frame_height");

  return (
    <div className="space-y-8">
      <section className="rounded-wg border-2 border-wg-espresso bg-wg-espresso p-6 text-wg-ivory shadow-wg">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.2em] text-wg-sepia">
          Completion status
        </p>
        <div className="mt-2 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h2 className="text-3xl text-wg-ivory">{stage || status || "Not scheduled"}</h2>
            {stage && status && <p className="mt-1 capitalize text-wg-muted">{formatValue(status)}</p>}
          </div>
        </div>
      </section>

      <DetailFacts
        facts={[
          ["Target medium", field(item, "target_medium")],
          ["Genres", storyTermNames(item, "worldgraph_genre").join(", ")],
          ["Start date", field(item, "start_date")],
          ["End date", field(item, "end_date")],
          ["Frame size", frameWidth && frameHeight ? `${frameWidth} × ${frameHeight} px` : ""],
          ["Aspect ratio", field(item, "aspect_ratio")],
          ["Frame rate", field(item, "frame_rate") ? `${field(item, "frame_rate")} fps` : ""],
        ]}
      />
      <HtmlSection title="Project overview" html={field(item, "description")} />
      <DevelopmentCompass development={project?.development} />
      <Metrics metrics={project?.metrics ?? []} />
      <Metrics metrics={project?.entityCounts ?? []} title="Published entity mix" />
      <ConnectedEntities entities={project?.mostConnected ?? []} />
    </div>
  );
}

function WorldDetail({ item }: { item: StoryItem }) {
  return (
    <div className="space-y-9">
      <HtmlSection title="World synopsis" html={field(item, "synopsis")} />
      <div className="grid gap-9 lg:grid-cols-2">
        <HtmlSection title="World rules" html={field(item, "rules")} />
        <HtmlSection title="Themes" html={field(item, "themes")} />
        <HtmlSection title="Geography" html={field(item, "geography")} />
        <HtmlSection title="Timeline" html={field(item, "timeline")} />
      </div>
      <HtmlSection title="References" html={field(item, "references")} />
    </div>
  );
}

function CharacterDetail({ item }: { item: StoryItem }) {
  return (
    <div className="space-y-9">
      <DetailFacts
        facts={[
          ["Age", field(item, "age")],
          ["Roles", storyTermNames(item, "worldgraph_character_role").join(", ")],
          ["Voice", field(item, "voice_profile")],
        ]}
      />
      <HtmlSection title="Biography" html={field(item, "biography")} />
      <div className="grid gap-9 lg:grid-cols-2">
        <HtmlSection title="Appearance" html={field(item, "appearance")} />
        <HtmlSection title="Personality" html={field(item, "personality")} />
        <HtmlSection title="Motivation" html={field(item, "motivation")} />
        <HtmlSection title="Backstory" html={field(item, "backstory")} />
      </div>
    </div>
  );
}

function dialogueEntries(item: StoryItem): DialogueEntry[] {
  let value = storyField(item, "dialogue");

  if (typeof value === "string" && value.trim().startsWith("[")) {
    try {
      value = JSON.parse(value) as unknown;
    } catch {
      return [];
    }
  }

  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .reduce<DialogueEntry[]>((entries, candidate, index) => {
      if (!isRecord(candidate)) {
        return entries;
      }

      const line = typeof candidate.line === "string" ? candidate.line : "";
      if (!line) {
        return entries;
      }

      const rawSequence = Number(candidate.sequence);
      entries.push({
        speaker: typeof candidate.speaker === "string" ? candidate.speaker : "",
        line,
        description:
          typeof candidate.description === "string" ? candidate.description : "",
        sequence: Number.isFinite(rawSequence) ? rawSequence : index + 1,
      });
      return entries;
    }, [])
    .sort((left, right) => left.sequence - right.sequence);
}

function Dialogue({ entries }: { entries: DialogueEntry[] }) {
  if (!entries.length) {
    return null;
  }

  return (
    <section className="space-y-4">
      <h2 className="border-b border-wg-sepia/40 pb-2 text-2xl text-wg-espresso">
        Dialogue
      </h2>
      <ol className="space-y-4">
        {entries.map((entry, index) => (
          <li key={`${entry.sequence}:${index}`} className="border-l-4 border-wg-sepia pl-4">
            {entry.speaker && (
              <p className="font-headline text-xs font-bold uppercase tracking-[0.16em] text-wg-espresso">
                {entry.speaker}
              </p>
            )}
            {entry.description && (
              <p className="mt-1 text-sm italic text-wg-charcoal/60">{entry.description}</p>
            )}
            <p className="mt-1 whitespace-pre-wrap leading-relaxed text-wg-charcoal/90">
              {entry.line}
            </p>
          </li>
        ))}
      </ol>
    </section>
  );
}

function ShotSequence({ shots }: { shots: StoryShot[] }) {
  return (
    <section aria-labelledby="shot-sequence-heading" className="space-y-5">
      <div>
        <h2 id="shot-sequence-heading" className="text-3xl text-wg-espresso">
          Shot sequence
        </h2>
        <p className="mt-1 text-sm text-wg-charcoal/65">
          Published shots are shown in the editorial order set in WordPress.
        </p>
      </div>
      {shots.length ? (
        <ol className="space-y-6">
          {shots.map((shot, index) => (
            <li
              key={shot.id || `${shot.slug}:${index}`}
              className="grid gap-5 rounded-wg border-2 border-wg-espresso bg-white/40 p-5 shadow-wg lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
            >
              <div className="space-y-3">
                <p className="font-headline text-xs font-bold uppercase tracking-[0.18em] text-wg-sepia">
                  Shot {shot.shotNumber ?? index + 1}
                  {shot.shotType ? ` · ${formatValue(shot.shotType)}` : ""}
                </p>
                <h3 className="text-2xl text-wg-espresso">{shot.title}</h3>
                {shot.description && (
                  <p className="leading-relaxed text-wg-charcoal/75">{shot.description}</p>
                )}
              </div>
              {shot.media.length ? (
                <MediaGallery media={shot.media} label={`${shot.title} media`} />
              ) : (
                <div className="flex min-h-32 items-center justify-center rounded-wg border border-dashed border-wg-sepia/55 text-sm text-wg-charcoal/55">
                  Media pending
                </div>
              )}
            </li>
          ))}
        </ol>
      ) : (
        <div className="rounded-wg border-2 border-dashed border-wg-sepia/55 bg-white/30 px-6 py-10 text-center text-wg-charcoal/65">
          This published Scene does not have any published Shots yet.
        </div>
      )}
    </section>
  );
}

function SceneDetail({ item }: { item: StoryItem }) {
  return (
    <div className="space-y-9">
      <DetailFacts
        facts={[
          ["Scene number", field(item, "scene_number")],
          ["Sequence", storyTermNames(item, "worldgraph_sequence").join(", ")],
          ["Time of day", field(item, "time_of_day")],
          ["Emotional tone", field(item, "emotional_tone")],
        ]}
      />
      <HtmlSection title="Summary" html={field(item, "summary")} />
      <HtmlSection title="Script" html={field(item, "script_content")} />
      <Dialogue entries={dialogueEntries(item)} />
      <ShotSequence shots={item.display.shots ?? []} />
      <HtmlSection title="Production notes" html={field(item, "production_notes")} />
    </div>
  );
}

function PropDetail({ item }: { item: StoryItem }) {
  return (
    <div className="space-y-9">
      <DetailFacts facts={[["Story purpose", field(item, "purpose")]]} />
      <HtmlSection title="Description" html={field(item, "description")} />
      <HtmlSection title="Design notes" html={field(item, "notes")} />
    </div>
  );
}

function SoundDetail({ item }: { item: StoryItem }) {
  const soundTypes = storyTermNames(item, "worldgraph_sound_type");
  const lyrics = field(item, "lyrics");
  const isSong = soundTypes.some((type) => type.toLowerCase() === "music") || Boolean(lyrics);

  return (
    <div className="space-y-9">
      <DetailFacts
        facts={[
          ["Format", isSong ? "Song" : soundTypes.join(", ")],
          ["Production status", storyTermNames(item, "worldgraph_status").join(", ")],
          ["Starts at", field(item, "start_timecode")],
          ["Duration", field(item, "duration")],
          ["Story-world relation", field(item, "diegetic")],
        ]}
      />
      <TextSection title="Spoken text" text={field(item, "spoken_text")} />
      <TextSection title="Lyrics" text={lyrics} />
      <TextSection title="Production notes" text={field(item, "production_notes")} />
    </div>
  );
}

function DetailBody({ item }: { item: StoryItem }) {
  if (item.storyType === "projects") {
    return <ProjectDetail item={item} />;
  }
  if (item.storyType === "worlds") {
    return <WorldDetail item={item} />;
  }
  if (item.storyType === "characters") {
    return <CharacterDetail item={item} />;
  }
  if (item.storyType === "scenes") {
    return <SceneDetail item={item} />;
  }
  if (item.storyType === "props") {
    return <PropDetail item={item} />;
  }
  return <SoundDetail item={item} />;
}

export function StoryDetail({ item }: { item: StoryItem }) {
  const config = storyResourceConfig[item.storyType];

  if (item.protected) {
    return (
      <article className="space-y-8">
        <nav aria-label="Breadcrumb" className="text-sm text-wg-charcoal/65">
          <Link href="/story" className="font-semibold text-wg-blueprint">
            Story
          </Link>
          <span aria-hidden="true"> / </span>
          <Link
            href={`/story/${item.storyType}`}
            className="font-semibold text-wg-blueprint"
          >
            {config.plural}
          </Link>
        </nav>
        <div className="rounded-wg border-2 border-wg-espresso bg-wg-ivory p-8 shadow-wg">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Protected story item
          </p>
          <h1
            className="mt-3 max-w-4xl text-4xl font-semibold text-wg-espresso md:text-5xl"
            dangerouslySetInnerHTML={{ __html: item.titleHtml }}
          />
          <p className="mt-5 max-w-2xl leading-relaxed text-wg-charcoal/75">
            This item is protected in WordPress. Its story details and media are not
            available in the public headless view.
          </p>
        </div>
      </article>
    );
  }

  return (
    <article className="space-y-10">
      <nav aria-label="Breadcrumb" className="text-sm text-wg-charcoal/65">
        <Link href="/story" className="font-semibold text-wg-blueprint">
          Story
        </Link>
        <span aria-hidden="true"> / </span>
        <Link href={`/story/${item.storyType}`} className="font-semibold text-wg-blueprint">
          {config.plural}
        </Link>
      </nav>

      <header className="space-y-4 border-b-2 border-wg-espresso pb-7">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
          {config.singular}
        </p>
        <h1
          className="max-w-5xl text-5xl font-semibold text-wg-espresso md:text-6xl"
          dangerouslySetInnerHTML={{ __html: item.titleHtml }}
        />
        {item.terms.length > 0 && (
          <ul aria-label="Story classifications" className="flex flex-wrap gap-2">
            {item.terms.map((term) => (
              <li
                key={`${term.taxonomy}:${term.id}`}
                className="rounded-full border border-wg-sepia/45 bg-wg-sepia/10 px-3 py-1 text-sm font-semibold text-wg-espresso"
              >
                {term.name}
              </li>
            ))}
          </ul>
        )}
      </header>

      {item.display.media.length > 0 && (
        <div className={item.storyType === "worlds" ? "max-w-none" : "mx-auto max-w-5xl"}>
          <MediaGallery media={item.display.media} label={`${item.titleText} media`} />
        </div>
      )}

      <DetailBody item={item} />

      {item.contentHtml && (
        <HtmlSection title="Published notes" html={item.contentHtml} />
      )}
    </article>
  );
}
