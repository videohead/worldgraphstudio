import "server-only";

export const storyResourceConfig = {
  projects: {
    restBase: "worldgraph_project",
    singular: "Project",
    plural: "Projects",
    description: "Track the shape, production state, and health of each story project.",
  },
  worlds: {
    restBase: "worldgraph_world",
    singular: "World",
    plural: "Worlds",
    description: "Explore the rules, themes, places, and history behind each story world.",
  },
  characters: {
    restBase: "worldgraph_character",
    singular: "Character",
    plural: "Characters",
    description: "Meet the people who carry the story and the forces that drive them.",
  },
  scenes: {
    restBase: "worldgraph_scene",
    singular: "Scene",
    plural: "Scenes",
    description: "Read the story scene by scene, with every shot kept in editorial order.",
  },
  props: {
    restBase: "worldgraph_prop",
    singular: "Prop",
    plural: "Props",
    description: "Inspect important objects through their purpose, ownership, and visual studies.",
  },
  sounds: {
    restBase: "worldgraph_sound",
    singular: "Sound",
    plural: "Sounds & Songs",
    description: "Listen to music, narration, effects, ambience, Foley, and other planned cues.",
  },
} as const;

/**
 * Native REST bases used by the public projection.
 */
export const worldgraphRestBases = {
  projects: storyResourceConfig.projects.restBase,
  worlds: storyResourceConfig.worlds.restBase,
  characters: storyResourceConfig.characters.restBase,
  scenes: storyResourceConfig.scenes.restBase,
  props: storyResourceConfig.props.restBase,
  sounds: storyResourceConfig.sounds.restBase,
} as const;

export type StoryType = keyof typeof storyResourceConfig;

export interface StoryMedia {
  id: number;
  assetId?: number;
  url: string;
  thumbnailUrl?: string;
  posterUrl?: string;
  alt: string;
  title?: string;
  caption?: string;
  mimeType: string;
  intent?: string;
  origin?: string;
  width?: number;
  height?: number;
}

export interface StoryShot {
  id: number;
  slug: string;
  title: string;
  description?: string;
  shotNumber?: number;
  shotType?: string;
  menuOrder: number;
  media: StoryMedia[];
}

export interface StoryMetric {
  key: string;
  label: string;
  value: string | number;
}

export interface StoryConnectedEntity {
  id: number;
  type: string;
  name: string;
  connectionCount: number;
}

export interface StoryDevelopmentEntity {
  id: number;
  type: string;
  name: string;
}

export interface StoryDevelopmentOpportunity {
  id: string;
  type: string;
  priority: "high" | "medium";
  title: string;
  evidence: string;
  question: string;
  suggestedEntityType: string;
  entity?: StoryDevelopmentEntity;
}

export interface StoryDevelopmentElement extends StoryDevelopmentEntity {
  priority: "high" | "medium";
  opportunityIds: string[];
}

export interface StoryDevelopment {
  phase: {
    key: string;
    label: string;
    summary: string;
  };
  totalOpportunities: number;
  hasMore: boolean;
  opportunities: StoryDevelopmentOpportunity[];
  elementsToDevelop: StoryDevelopmentElement[];
}

export interface StoryProjectDisplay {
  id?: number;
  slug?: string;
  title?: string;
  status?: string;
  productionStage?: string;
  metrics: StoryMetric[];
  entityCounts: StoryMetric[];
  mostConnected: StoryConnectedEntity[];
  development?: StoryDevelopment;
}

export interface StoryDisplay {
  media: StoryMedia[];
  shots?: StoryShot[];
  project?: StoryProjectDisplay;
}

export interface StoryTerm {
  id: number;
  name: string;
  slug: string;
  taxonomy: string;
}

export interface StoryItem {
  id: number;
  slug: string;
  wpType: string;
  storyType: StoryType;
  status: string;
  date: string;
  modified: string;
  protected: boolean;
  titleHtml: string;
  titleText: string;
  excerptHtml: string;
  contentHtml: string;
  featuredMediaId: number;
  fields: Record<string, unknown>;
  terms: StoryTerm[];
  display: StoryDisplay;
}

export interface StoryCollectionResult {
  items: StoryItem[];
  total: number;
  totalPages: number;
  page: number;
}

type UnknownRecord = Record<string, unknown>;

interface WpRenderedField {
  rendered?: unknown;
  protected?: unknown;
}

interface WpStoryPost extends UnknownRecord {
  id?: unknown;
  slug?: unknown;
  type?: unknown;
  status?: unknown;
  date?: unknown;
  modified?: unknown;
  title?: WpRenderedField;
  excerpt?: WpRenderedField;
  content?: WpRenderedField;
  featured_media?: unknown;
  acf?: unknown;
  worldgraph_display?: unknown;
  _embedded?: unknown;
}

function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function stringValue(value: unknown): string {
  if (typeof value === "string") {
    return value;
  }

  if (typeof value === "number" && Number.isFinite(value)) {
    return String(value);
  }

  return "";
}

function firstPresent(...values: unknown[]): unknown {
  return values.find(
    (value) => value !== undefined && value !== null && value !== ""
  );
}

function numberValue(value: unknown): number | undefined {
  if (
    value === undefined ||
    value === null ||
    value === "" ||
    typeof value === "boolean"
  ) {
    return undefined;
  }

  const number = typeof value === "number" ? value : Number(value);
  return Number.isFinite(number) ? number : undefined;
}

function positiveIntegerValue(value: unknown): number | undefined {
  const number = numberValue(value);
  return number !== undefined && Number.isSafeInteger(number) && number > 0
    ? number
    : undefined;
}

function nonNegativeIntegerValue(value: unknown): number | undefined {
  const number = numberValue(value);
  return number !== undefined && Number.isSafeInteger(number) && number >= 0
    ? number
    : undefined;
}

function developmentPriority(value: unknown): "high" | "medium" {
  return stringValue(value) === "high" ? "high" : "medium";
}

function renderedValue(value: unknown): string {
  return isRecord(value) ? stringValue(value.rendered) : stringValue(value);
}

function plainText(html: string): string {
  return html
    .replace(/<[^>]*>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&quot;/gi, '"')
    .replace(/&#039;|&apos;/gi, "'")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&#(\d+);/g, (match, code: string) => {
      const point = Number(code);
      return Number.isInteger(point) && point >= 0 && point <= 0x10ffff
        ? String.fromCodePoint(point)
        : match;
    })
    .replace(/\s+/g, " ")
    .trim();
}

function normalizeMedia(value: unknown): StoryMedia | null {
  if (!isRecord(value)) {
    return null;
  }

  const url = stringValue(firstPresent(value.url, value.source_url, value.src));
  if (!url) {
    return null;
  }

  return {
    id: numberValue(value.id) ?? 0,
    assetId: numberValue(firstPresent(value.asset_id, value.assetId)),
    url,
    thumbnailUrl:
      stringValue(
        firstPresent(value.thumbnail_url, value.thumbnailUrl, value.thumbnail)
      ) ||
      undefined,
    posterUrl:
      stringValue(firstPresent(value.poster_url, value.posterUrl, value.poster)) ||
      undefined,
    alt: stringValue(firstPresent(value.alt, value.alt_text)),
    title: stringValue(value.title) || undefined,
    caption: renderedValue(value.caption) || undefined,
    mimeType:
      stringValue(firstPresent(value.mime_type, value.mimeType, value.media_type)) ||
      "application/octet-stream",
    intent: stringValue(value.intent) || undefined,
    origin: stringValue(value.origin) || undefined,
    width: numberValue(value.width),
    height: numberValue(value.height),
  };
}

function normalizeMediaList(value: unknown): StoryMedia[] {
  if (!Array.isArray(value)) {
    return [];
  }

  const seen = new Set<string>();
  return value.reduce<StoryMedia[]>((media, candidate) => {
    const item = normalizeMedia(candidate);
    if (item && !seen.has(item.url)) {
      seen.add(item.url);
      media.push(item);
    }
    return media;
  }, []);
}

function normalizeShot(value: unknown, index: number): StoryShot | null {
  if (!isRecord(value)) {
    return null;
  }

  const metadata = isRecord(value.meta) ? value.meta : {};
  const title =
    renderedValue(value.title) ||
    stringValue(value.display_name) ||
    `Shot ${index + 1}`;
  const nestedDisplay = isRecord(value.worldgraph_display) ? value.worldgraph_display : {};
  const media = normalizeMediaList(firstPresent(value.media, nestedDisplay.media));
  const fallbackMedia = normalizeMedia(value.featured_image);
  if (!media.length && fallbackMedia) {
    media.push(fallbackMedia);
  }

  return {
    id: numberValue(value.id) ?? 0,
    slug: stringValue(value.slug),
    title: plainText(title),
    description:
      plainText(
        renderedValue(
          firstPresent(
            value.description,
            value.shot_description,
            metadata.shot_description,
            value.excerpt
          )
        )
      ) || undefined,
    shotNumber: numberValue(
      firstPresent(value.shot_number, metadata.shot_number, value.number)
    ),
    shotType:
      stringValue(
        firstPresent(
          value.shot_type_label,
          value.shot_type,
          metadata.shot_type
        )
      ) || undefined,
    menuOrder:
      numberValue(firstPresent(value.menu_order, value.order)) ?? index + 1,
    media,
  };
}

function labelFromKey(key: string): string {
  return key
    .replace(/^worldgraph_/, "")
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function normalizeConnectedEntities(value: unknown): StoryConnectedEntity[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.reduce<StoryConnectedEntity[]>((entities, candidate) => {
    if (!isRecord(candidate)) {
      return entities;
    }

    const id = positiveIntegerValue(candidate.id);
    const name = stringValue(candidate.name);
    const connectionCount = nonNegativeIntegerValue(
      firstPresent(candidate.connection_count, candidate.connectionCount)
    );
    if (id === undefined || !name || connectionCount === undefined) {
      return entities;
    }

    entities.push({
      id,
      name,
      type: stringValue(candidate.type),
      connectionCount,
    });
    return entities;
  }, []);
}

function normalizeDevelopmentEntity(value: unknown): StoryDevelopmentEntity | undefined {
  if (!isRecord(value)) {
    return undefined;
  }

  const id = positiveIntegerValue(value.id);
  const type = stringValue(value.type);
  const name = stringValue(value.name);
  if (id === undefined || !type || !name) {
    return undefined;
  }

  return { id, type, name };
}

function normalizeDevelopment(value: unknown): StoryDevelopment | undefined {
  if (!isRecord(value) || !isRecord(value.phase)) {
    return undefined;
  }

  const phase = {
    key: stringValue(value.phase.key),
    label: stringValue(value.phase.label),
    summary: stringValue(value.phase.summary),
  };
  if (!phase.key || !phase.label || !phase.summary) {
    return undefined;
  }

  const seenOpportunityIds = new Set<string>();
  const opportunities = Array.isArray(value.opportunities)
    ? value.opportunities.slice(0, 12).reduce<StoryDevelopmentOpportunity[]>((items, candidate) => {
        if (!isRecord(candidate)) {
          return items;
        }

        const id = stringValue(candidate.id);
        const type = stringValue(candidate.type);
        const title = stringValue(candidate.title);
        const evidence = stringValue(candidate.evidence);
        const question = stringValue(candidate.question);
        const suggestedEntityType = stringValue(candidate.suggested_entity_type);
        if (
          !id ||
          seenOpportunityIds.has(id) ||
          !type ||
          !title ||
          !evidence ||
          !question ||
          !suggestedEntityType
        ) {
          return items;
        }

        seenOpportunityIds.add(id);
        const entity = normalizeDevelopmentEntity(candidate.entity);
        items.push({
          id,
          type,
          priority: developmentPriority(candidate.priority),
          title,
          evidence,
          question,
          suggestedEntityType,
          ...(entity ? { entity } : {}),
        });
        return items;
      }, [])
    : [];

  const seenElementIds = new Set<string>();
  const elementsToDevelop = Array.isArray(value.elements_to_develop)
    ? value.elements_to_develop.slice(0, 12).reduce<StoryDevelopmentElement[]>((items, candidate) => {
        const entity = normalizeDevelopmentEntity(candidate);
        const entityKey = entity ? `${entity.type}:${entity.id}` : "";
        if (!entity || !isRecord(candidate) || seenElementIds.has(entityKey)) {
          return items;
        }

        const opportunityIds = Array.isArray(candidate.opportunity_ids)
          ? Array.from(
              new Set(
                candidate.opportunity_ids
                  .map((opportunityId) => stringValue(opportunityId))
                  .filter(Boolean)
              )
            ).slice(0, 12)
          : [];
        seenElementIds.add(entityKey);
        items.push({
          ...entity,
          priority: developmentPriority(candidate.priority),
          opportunityIds,
        });
        return items;
      }, [])
    : [];

  const reportedTotal = nonNegativeIntegerValue(value.total_opportunities);
  const totalOpportunities = Math.max(
    opportunities.length,
    reportedTotal ?? opportunities.length
  );

  return {
    phase,
    totalOpportunities,
    hasMore: value.has_more === true && totalOpportunities > opportunities.length,
    opportunities,
    elementsToDevelop,
  };
}

function normalizeMetrics(value: unknown): StoryMetric[] {
  if (Array.isArray(value)) {
    return value.reduce<StoryMetric[]>((metrics, candidate, index) => {
      if (!isRecord(candidate)) {
        return metrics;
      }

      const metricValue = candidate.value;
      if (typeof metricValue !== "string" && typeof metricValue !== "number") {
        return metrics;
      }

      const key = stringValue(candidate.key) || `metric-${index + 1}`;
      metrics.push({
        key,
        label: stringValue(candidate.label) || labelFromKey(key),
        value: metricValue,
      });
      return metrics;
    }, []);
  }

  if (!isRecord(value)) {
    return [];
  }

  return Object.entries(value).reduce<StoryMetric[]>((metrics, [key, metricValue]) => {
    if (typeof metricValue === "string" || typeof metricValue === "number") {
      metrics.push({ key, label: labelFromKey(key), value: metricValue });
    }
    return metrics;
  }, []);
}

function normalizeProject(value: unknown): StoryProjectDisplay | undefined {
  if (!isRecord(value)) {
    return undefined;
  }

  const analytics = isRecord(value.analytics) ? value.analytics : {};
  const metrics = normalizeMetrics(firstPresent(value.analytics, value.metrics));

  return {
    id: numberValue(value.id),
    slug: stringValue(value.slug) || undefined,
    title: renderedValue(value.title) || undefined,
    status:
      stringValue(firstPresent(value.status_label, value.status)) || undefined,
    productionStage:
      stringValue(
        firstPresent(
          value.stage_label,
          value.stage,
          value.production_stage,
          value.productionStage
        )
      ) || undefined,
    metrics,
    entityCounts: normalizeMetrics(analytics.entity_counts),
    mostConnected: normalizeConnectedEntities(analytics.most_connected),
    development: normalizeDevelopment(analytics.development),
  };
}

function embeddedRecord(post: WpStoryPost): UnknownRecord {
  return isRecord(post._embedded) ? post._embedded : {};
}

function normalizeEmbeddedMedia(post: WpStoryPost): StoryMedia[] {
  const embedded = embeddedRecord(post);
  const featured = embedded["wp:featuredmedia"];
  if (!Array.isArray(featured) || !featured.length || !isRecord(featured[0])) {
    return [];
  }

  const candidate = featured[0];
  const details = isRecord(candidate.media_details) ? candidate.media_details : {};
  const sizes = isRecord(details.sizes) ? details.sizes : {};
  const thumbnail = isRecord(sizes.thumbnail) ? sizes.thumbnail : {};
  const media = normalizeMedia({
    ...candidate,
    url: candidate.source_url,
    thumbnail_url: thumbnail.source_url,
  });

  return media ? [media] : [];
}

function normalizeTerms(post: WpStoryPost): StoryTerm[] {
  const embedded = embeddedRecord(post);
  const termGroups = embedded["wp:term"];
  if (!Array.isArray(termGroups)) {
    return [];
  }

  return termGroups.flatMap((group) => {
    if (!Array.isArray(group)) {
      return [];
    }

    return group.reduce<StoryTerm[]>((terms, candidate) => {
      if (!isRecord(candidate)) {
        return terms;
      }

      const id = numberValue(candidate.id);
      const name = stringValue(candidate.name);
      const slug = stringValue(candidate.slug);
      const taxonomy = stringValue(candidate.taxonomy);
      if (id !== undefined && name && slug && taxonomy) {
        terms.push({ id, name, slug, taxonomy });
      }
      return terms;
    }, []);
  });
}

function normalizeDisplay(post: WpStoryPost): StoryDisplay {
  const raw = isRecord(post.worldgraph_display) ? post.worldgraph_display : {};
  const media = normalizeMediaList(raw.media);
  if (!media.length) {
    media.push(...normalizeEmbeddedMedia(post));
  }

  const shots = Array.isArray(raw.shots)
    ? raw.shots
        .map(normalizeShot)
        .filter((shot): shot is StoryShot => shot !== null)
    : undefined;

  return {
    media,
    shots,
    project: normalizeProject(raw.project),
  };
}

function normalizeStoryItem(post: WpStoryPost, storyType: StoryType): StoryItem {
  const titleHtml = renderedValue(post.title);
  // WordPress uses content.protected as the public REST boundary signal. Keep
  // only the core listing shell when it is set; registered fields such as ACF
  // and worldgraph_display may otherwise still be present in the raw response.
  const protectedContent = post.content?.protected === true;
  const fields = !protectedContent && isRecord(post.acf) ? post.acf : {};

  return {
    id: numberValue(post.id) ?? 0,
    slug: stringValue(post.slug),
    wpType: stringValue(post.type),
    storyType,
    status: stringValue(post.status),
    date: stringValue(post.date),
    modified: stringValue(post.modified),
    protected: protectedContent,
    titleHtml,
    titleText: plainText(titleHtml) || "Untitled",
    excerptHtml: protectedContent ? "" : renderedValue(post.excerpt),
    contentHtml: protectedContent ? "" : renderedValue(post.content),
    featuredMediaId: protectedContent
      ? 0
      : numberValue(post.featured_media) ?? 0,
    fields,
    terms: protectedContent ? [] : normalizeTerms(post),
    display: protectedContent ? { media: [] } : normalizeDisplay(post),
  };
}

function wordpressUrl(): string {
  const value = process.env.WORDPRESS_URL?.trim();
  if (!value) {
    throw new Error("WORDPRESS_URL is not configured in the headless environment.");
  }

  return value.replace(/\/$/, "");
}

async function fetchStoryPosts(
  storyType: StoryType,
  params: Record<string, string | number | boolean | undefined>,
  tags: string[],
  resourceId?: number
): Promise<{ posts: WpStoryPost[]; total: number; totalPages: number }> {
  const config = storyResourceConfig[storyType];
  const path = resourceId ? `${config.restBase}/${resourceId}` : config.restBase;
  const url = new URL(`${wordpressUrl()}/wp-json/wp/v2/${path}`);

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  }

  const response = await fetch(url.toString(), {
    // Webhooks normally refresh these tags immediately. The time-based fallback
    // prevents an isolated delivery failure from leaving Story views stale.
    next: { tags: ["wordpress", ...tags], revalidate: 300 },
  });

  if (!response.ok) {
    throw new Error(
      `World Graph public API error: ${response.status} ${response.statusText}`
    );
  }

  const payload: unknown = await response.json();
  const posts = Array.isArray(payload)
    ? payload.filter((post): post is WpStoryPost => isRecord(post))
    : isRecord(payload)
      ? [payload]
      : [];

  return {
    posts,
    total: Number(response.headers.get("X-WP-Total") ?? posts.length),
    totalPages: Number(response.headers.get("X-WP-TotalPages") ?? 1),
  };
}

export function isStoryType(value: string): value is StoryType {
  return Object.prototype.hasOwnProperty.call(storyResourceConfig, value);
}

export async function getStoryItems(
  storyType: StoryType,
  page = 1,
  perPage = 12
): Promise<StoryCollectionResult> {
  const safePage = Number.isFinite(page) ? Math.max(1, Math.floor(page)) : 1;
  const safePerPage = Number.isFinite(perPage)
    ? Math.max(1, Math.min(100, Math.floor(perPage)))
    : 12;
  const result = await fetchStoryPosts(
    storyType,
    {
      page: safePage,
      per_page: safePerPage,
      _embed: true,
      orderby: "title",
      order: "asc",
    },
    ["story", `story:${storyType}`]
  );

  return {
    items: result.posts.map((post) => normalizeStoryItem(post, storyType)),
    total: result.total,
    totalPages: result.totalPages,
    page: safePage,
  };
}

export async function getStoryItemBySlug(
  storyType: StoryType,
  slug: string
): Promise<StoryItem | undefined> {
  const lookup = await fetchStoryPosts(
    storyType,
    { slug, per_page: 1, _fields: "id,slug" },
    ["story", `story:${storyType}`, `story:${storyType}:${slug}`]
  );
  const id = lookup.posts[0] ? numberValue(lookup.posts[0].id) : undefined;

  if (id === undefined) {
    return undefined;
  }

  // The public display projection reserves expensive Scene-shot and Project-
  // analytics aggregates for native single-resource requests. Resolve the
  // slug publicly, then request that canonical by-ID resource without adding
  // server credentials or coupling the UI to an admin endpoint.
  const result = await fetchStoryPosts(
    storyType,
    { _embed: true },
    [
      "story",
      `story:${storyType}`,
      `story:${storyType}:${id}`,
      `story:${storyType}:${slug}`,
    ],
    id
  );

  return result.posts[0]
    ? normalizeStoryItem(result.posts[0], storyType)
    : undefined;
}

export function storyField(item: StoryItem, key: string): unknown {
  return item.fields[key];
}

export function storyFieldText(item: StoryItem, key: string): string {
  return renderedValue(storyField(item, key));
}

export function storyTermNames(item: StoryItem, taxonomy: string): string[] {
  return item.terms
    .filter((term) => term.taxonomy === taxonomy)
    .map((term) => term.name);
}
