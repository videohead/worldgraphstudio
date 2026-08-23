import { getRecentPosts } from "@/lib/wordpress";
import { PostCard } from "@/components/posts/post-card";
import { Button } from "@/components/ui/button";

export default async function HomePage() {
  const posts = await getRecentPosts();

  return (
    <div className="space-y-14">
      <section
        aria-labelledby="home-hero-title"
        className="space-y-5 text-center"
      >
        <p className="font-headline text-xs font-bold uppercase tracking-[0.24em] text-wg-sepia">
          World Graph Studio
        </p>
        <h1
          id="home-hero-title"
          className="text-5xl font-semibold text-wg-espresso md:text-6xl"
        >
          Your ideas. Your assets.
          <span className="block">No credits needed.</span>
        </h1>
        <p className="mx-auto max-w-3xl text-lg leading-relaxed text-wg-charcoal/80">
          The extensible open-source studio for worldbuilding, storytelling, and
          AI-powered creative production. Import scripts, connect the tools you
          choose, and grow a team of 50+ specialist agents without any credits
          needed for local models.
        </p>
        <Button href="/posts">Browse all posts</Button>
      </section>

      <section
        aria-labelledby="story-graph-title"
        className="space-y-5 border-y border-wg-sepia/40 py-10"
      >
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Story first
          </p>
          <h2 id="story-graph-title" className="text-4xl text-wg-espresso">
            The story graph is the source of truth.
          </h2>
          <p className="text-lg leading-relaxed text-wg-charcoal/80">
            Instead of treating a story as a pile of documents, World Graph
            Studio represents narrative, production, asset, and editorial
            information as structured elements connected by explicit
            relationships.
          </p>
        </div>
        <p className="mx-auto max-w-4xl rounded-wg bg-wg-blueprint p-6 text-center leading-relaxed text-wg-ivory shadow-wg">
          Project records, relationships, permissions, media, and APIs stay in
          the application you control. Optional services connect around that
          core; they do not replace it.
        </p>
      </section>

      <section
        aria-labelledby="capabilities-title"
        className="mx-auto max-w-4xl space-y-3 text-center"
      >
        <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
          Delivered today
        </p>
        <h2 id="capabilities-title" className="text-4xl text-wg-espresso">
          A connected creative workspace that ships now.
        </h2>
        <p className="text-lg leading-relaxed text-wg-charcoal/80">
          Core story and production planning work without an AI or generation
          connection. Extensibly connect Word Graph Studio to a wide array of
          resources for supplementing your story and its production, without
          sacrificing user control or distracting you from building compelling
          stories.
        </p>
      </section>

      <section aria-labelledby="recent-posts-title" className="space-y-5">
        <h2 id="recent-posts-title" className="text-3xl text-wg-espresso">
          Recent posts
        </h2>
        <ul className="grid gap-5 sm:grid-cols-2">
          {posts.map((post) => (
            <PostCard key={post.id} post={post} />
          ))}
        </ul>
      </section>
    </div>
  );
}
