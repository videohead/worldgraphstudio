import type {
  Author,
  Category,
  Media,
  PaginatedResponse,
  Page,
  Post,
  Tag,
} from "@/lib/wordpress.d";

const WORDPRESS_URL = process.env.WORDPRESS_URL;

if (!WORDPRESS_URL) {
  console.warn(
    "WORDPRESS_URL is not set. Set it in .env.local, e.g. http://localhost:8080"
  );
}

interface FetchOptions {
  next?: NextFetchRequestConfig;
}

async function wpFetch<T>(
  endpoint: string,
  params: Record<string, string | number | boolean | undefined> = {},
  options: FetchOptions = {}
): Promise<T> {
  const url = new URL(`${WORDPRESS_URL}/wp-json/wp/v2${endpoint}`);

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  }

  const response = await fetch(url.toString(), {
    next: options.next ?? { tags: ["wordpress"] },
  });

  if (!response.ok) {
    throw new Error(
      `WordPress API error: ${response.status} ${response.statusText} (${url})`
    );
  }

  return response.json();
}

async function wpFetchPaginated<T>(
  endpoint: string,
  params: Record<string, string | number | boolean | undefined>,
  options: FetchOptions = {}
): Promise<PaginatedResponse<T>> {
  const url = new URL(`${WORDPRESS_URL}/wp-json/wp/v2${endpoint}`);

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  }

  const response = await fetch(url.toString(), {
    next: options.next ?? { tags: ["wordpress"] },
  });

  if (!response.ok) {
    throw new Error(
      `WordPress API error: ${response.status} ${response.statusText} (${url})`
    );
  }

  const data = (await response.json()) as T[];

  return {
    data,
    headers: {
      total: Number(response.headers.get("X-WP-Total") ?? data.length),
      totalPages: Number(response.headers.get("X-WP-TotalPages") ?? 1),
    },
  };
}

export interface PostFilters {
  category?: string;
  tag?: string;
  author?: string;
  search?: string;
}

// Posts

export function getRecentPosts(filters: PostFilters = {}) {
  return wpFetch<Post[]>(
    "/posts",
    {
      per_page: 100,
      _embed: true,
      categories: filters.category,
      tags: filters.tag,
      author: filters.author,
      search: filters.search,
    },
    { next: { tags: ["posts"] } }
  );
}

export function getPostsPaginated(
  page = 1,
  perPage = 9,
  filters: PostFilters = {}
) {
  return wpFetchPaginated<Post>(
    "/posts",
    {
      page,
      per_page: perPage,
      _embed: true,
      categories: filters.category,
      tags: filters.tag,
      author: filters.author,
      search: filters.search,
    },
    { next: { tags: ["posts"] } }
  );
}

export async function getPostBySlug(slug: string) {
  const posts = await wpFetch<Post[]>(
    "/posts",
    { slug, _embed: true },
    { next: { tags: ["posts", `post:${slug}`] } }
  );
  return posts[0];
}

export function getPostById(id: number) {
  return wpFetch<Post>(
    `/posts/${id}`,
    { _embed: true },
    { next: { tags: ["posts", `post:${id}`] } }
  );
}

export async function getAllPostSlugs() {
  const posts = await wpFetch<Post[]>("/posts", {
    per_page: 100,
    _fields: "slug",
  });
  return posts.map((post) => post.slug);
}

export async function getAllPostsForSitemap() {
  return wpFetch<Post[]>("/posts", {
    per_page: 100,
    _fields: "slug,modified_gmt",
  });
}

// Pages

export function getAllPages() {
  return wpFetch<Page[]>("/pages", { per_page: 100 }, { next: { tags: ["pages"] } });
}

export function getPageById(id: number) {
  return wpFetch<Page>(`/pages/${id}`, {}, { next: { tags: ["pages", `page:${id}`] } });
}

export async function getPageBySlug(slug: string) {
  const pages = await wpFetch<Page[]>(
    "/pages",
    { slug },
    { next: { tags: ["pages", `page:${slug}`] } }
  );
  return pages[0];
}

// Categories

export function getAllCategories() {
  return wpFetch<Category[]>(
    "/categories",
    { per_page: 100 },
    { next: { tags: ["categories"] } }
  );
}

export function getCategoryById(id: number) {
  return wpFetch<Category>(`/categories/${id}`);
}

export async function getCategoryBySlug(slug: string) {
  const categories = await wpFetch<Category[]>("/categories", { slug });
  return categories[0];
}

export function getPostsByCategory(id: number) {
  return getRecentPosts({ category: String(id) });
}

export function getPostsByCategoryPaginated(id: number, page = 1, perPage = 9) {
  return getPostsPaginated(page, perPage, { category: String(id) });
}

// Tags

export function getAllTags() {
  return wpFetch<Tag[]>("/tags", { per_page: 100 }, { next: { tags: ["tags"] } });
}

export function getTagById(id: number) {
  return wpFetch<Tag>(`/tags/${id}`);
}

export async function getTagBySlug(slug: string) {
  const tags = await wpFetch<Tag[]>("/tags", { slug });
  return tags[0];
}

export function getPostsByTag(id: number) {
  return getRecentPosts({ tag: String(id) });
}

export function getPostsByTagPaginated(id: number, page = 1, perPage = 9) {
  return getPostsPaginated(page, perPage, { tag: String(id) });
}

export function getTagsByPost(postId: number) {
  return wpFetch<Tag[]>("/tags", { post: postId });
}

// Authors

export function getAllAuthors() {
  return wpFetch<Author[]>(
    "/users",
    { per_page: 100 },
    { next: { tags: ["authors"] } }
  );
}

export function getAuthorById(id: number) {
  return wpFetch<Author>(`/users/${id}`);
}

export async function getAuthorBySlug(slug: string) {
  const authors = await wpFetch<Author[]>("/users", { slug });
  return authors[0];
}

export function getPostsByAuthor(id: number) {
  return getRecentPosts({ author: String(id) });
}

export function getPostsByAuthorPaginated(id: number, page = 1, perPage = 9) {
  return getPostsPaginated(page, perPage, { author: String(id) });
}

// Media

export function getMediaById(id: number) {
  return wpFetch<Media>(`/media/${id}`);
}

// Search

export async function searchCategories(query: string) {
  return wpFetch<Category[]>("/categories", { search: query });
}

export async function searchTags(query: string) {
  return wpFetch<Tag[]>("/tags", { search: query });
}

export async function searchAuthors(query: string) {
  return wpFetch<Author[]>("/users", { search: query });
}
