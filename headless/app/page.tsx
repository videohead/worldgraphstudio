import { getRecentPosts } from "@/lib/wordpress";
import { PostCard } from "@/components/posts/post-card";
import { Button } from "@/components/ui/button";

const audiences = [
  {
    name: "Writers",
    description:
      "Keep structured story context close while drafting, reviewing, and revising.",
  },
  {
    name: "Filmmakers",
    description:
      "Develop scripts, coverage, storyboards, shots, assets, and editorial handoffs.",
  },
  {
    name: "Game Creators",
    description:
      "Design worlds, characters, locations, props, and narrative relationships.",
  },
  {
    name: "Worldbuilders",
    description:
      "Connect lore, histories, people, places, objects, and cultures in one evolving world.",
  },
  {
    name: "Showrunners",
    description:
      "Track characters, episodes, arcs, continuity, and production decisions across a series.",
  },
  {
    name: "Narrative Teams",
    description:
      "Share durable creative context so collaborators and AI assistants work from the same story knowledge.",
  },
  {
    name: "Production Studios",
    description:
      "Keep projects, production notes, media, and handoffs connected from development through delivery.",
  },
] as const;

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
          An open-source, self-hosted studio for worldbuilding, connected
          storytelling, and AI-powered creative production. Import and develop
          stories, build worlds, generate media, plan productions, and manage
          your assets in one connected workspace.
        </p>
        <Button href="/posts">Browse all posts</Button>
      </section>

      <section
        aria-labelledby="story-graph-title"
        className="space-y-5 border-y border-wg-sepia/40 py-10"
      >
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Persistent Creative Memory
          </p>
          <h2 id="story-graph-title" className="text-4xl text-wg-espresso">
            Your Story Never Forgets.
          </h2>
          <p className="text-lg leading-relaxed text-wg-charcoal/80">
            World Graph Studio stores your story as structured knowledge.
            Characters, locations, props, scenes, storyboards, assets, and
            production notes remain available to AI assistants through the
            World Graph and AI-powered memory retrieval.
          </p>
        </div>
        <p className="mx-auto max-w-4xl rounded-wg bg-wg-blueprint p-6 text-center leading-relaxed text-wg-ivory shadow-wg">
          The result is a creative workspace that understands not only what
          your assets are, but what they mean. Project records, relationships,
          permissions, and media stay in the application you control while
          optional services connect around that core.
        </p>
      </section>

      <section aria-labelledby="audiences-title" className="space-y-5">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            One studio, many disciplines
          </p>
          <h2 id="audiences-title" className="text-4xl text-wg-espresso">
            For people building connected stories.
          </h2>
        </div>
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {audiences.map((audience) => (
            <li
              key={audience.name}
              className="rounded-wg border border-wg-sepia/40 bg-wg-ivory p-6 shadow-wg"
            >
              <h3 className="text-2xl text-wg-espresso">{audience.name}</h3>
              <p className="mt-3 leading-relaxed text-wg-charcoal/80">
                {audience.description}
              </p>
            </li>
          ))}
        </ul>
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
          World Graph Studio gives your stories and production assets a unified
          home that can stay private or become a website you share. Core story
          and production planning continue to work without an AI or generation
          Connection.
        </p>
      </section>

      <section aria-labelledby="extensibility-title" className="space-y-5">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Built to grow with your workflow
          </p>
          <h2 id="extensibility-title" className="text-4xl text-wg-espresso">
            Grow the toolchain, not the project silo.
          </h2>
          <p className="text-lg leading-relaxed text-wg-charcoal/80">
            Creative technology changes quickly. Formats, provider Connections,
            and specialist agents can grow around the Story Graph without
            replacing it.
          </p>
        </div>
        <div className="grid gap-5 md:grid-cols-3">
          <article className="rounded-wg bg-wg-blueprint p-6 text-wg-ivory shadow-wg">
            <h3 className="text-2xl">Exchange creative data</h3>
            <p className="mt-3 leading-relaxed text-wg-ivory/85">
              Import adapters bring outside work into the shared Story Graph.
              Exporters create portable versions of live production data.
            </p>
          </article>
          <article className="rounded-wg bg-wg-blueprint p-6 text-wg-ivory shadow-wg">
            <h3 className="text-2xl">Add or replace Connections</h3>
            <p className="mt-3 leading-relaxed text-wg-ivory/85">
              Change supported local or hosted providers without rebuilding the
              project or giving up its structure.
            </p>
          </article>
          <article className="rounded-wg bg-wg-blueprint p-6 text-wg-ivory shadow-wg">
            <h3 className="text-2xl">Expand your specialist team</h3>
            <p className="mt-3 leading-relaxed text-wg-ivory/85">
              Add or customize portable specialist profiles that share the same
              project context and permissions.
            </p>
          </article>
        </div>
        <p className="rounded-wg border border-wg-sepia/40 bg-wg-ivory p-6 text-center leading-relaxed text-wg-charcoal/80 shadow-wg">
          Your creativity is not metered. Your content is not trapped. Your
          workflow is not limited. Optional third-party providers may still
          have their own prices, quotas, licenses, and terms.
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
