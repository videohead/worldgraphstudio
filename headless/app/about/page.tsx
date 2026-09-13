import type { Metadata } from "next";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "About | World Graph Studio",
  description:
    "The creative origin of World Graph Studio and its open promise to storytellers.",
};

export default function AboutPage() {
  return (
    <div className="space-y-14">
      <section className="space-y-5 text-center" aria-labelledby="about-title">
        <p className="font-headline text-xs font-bold uppercase tracking-[0.24em] text-wg-sepia">
          About World Graph Studio
        </p>
        <h1
          id="about-title"
          className="text-5xl font-semibold text-wg-espresso md:text-6xl"
        >
          Why does this project exist?
        </h1>
        <p className="mx-auto max-w-3xl text-lg leading-relaxed text-wg-charcoal/80">
          World Graph Studio grew from a creator&apos;s need for an open
          production workspace that keeps story—not tools, trends, or
          platforms—at the center.
        </p>
      </section>

      <section
        className="space-y-6 border-y border-wg-sepia/40 py-10"
        aria-labelledby="creative-start-title"
      >
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            The creative starting point
          </p>
          <h2
            id="creative-start-title"
            className="text-4xl text-wg-espresso"
          >
            Built from many disciplines. Focused on story.
          </h2>
        </div>
        <div className="grid gap-5 md:grid-cols-2">
          <article className="rounded-wg border-t-4 border-wg-sepia bg-wg-ivory p-6 shadow-wg">
            <h3 className="text-2xl text-wg-espresso">
              A multifaceted creative career
            </h3>
            <p className="mt-3 leading-relaxed text-wg-charcoal/80">
              Hi! I am a multifaceted creative professional. At various points
              in my careers, I have been a digital artist, writer, filmmaker,
              video producer, web developer, production manager, camera
              operator, and about 20 other things.
            </p>
          </article>
          <article className="rounded-wg border-t-4 border-wg-sepia bg-wg-ivory p-6 shadow-wg">
            <h3 className="text-2xl text-wg-espresso">The missing center</h3>
            <p className="mt-3 leading-relaxed text-wg-charcoal/80">
              I discovered AI filmmaking through friends, including the
              Machine Cinema group, and was intrigued. But there was a lot
              missing from the AI gold rush and the never-ending FOMO stream of
              new models and tools. Most of all, I saw a missing focus on story.
            </p>
          </article>
        </div>
      </section>

      <section
        className="rounded-wg bg-wg-blueprint px-6 py-10 text-wg-ivory shadow-wg md:px-10"
        aria-labelledby="wordpress-title"
      >
        <div className="mx-auto max-w-4xl space-y-5">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Why WordPress
          </p>
          <h2 id="wordpress-title" className="text-4xl">
            A platform that already understands content.
          </h2>
          <p className="text-lg leading-relaxed text-wg-ivory/85">
            WordPress is a web platform used across the world. There are more
            WordPress websites than any other kind of website; it is a
            juggernaut. And it is good at understanding content—it is a content
            management system.
          </p>
          <p className="text-lg leading-relaxed text-wg-ivory/85">
            World Graph Studio builds on that foundation with Story Graph-aware
            agents and schema-described WordPress Abilities. Through a
            compatible MCP Adapter, assistants can use those permission-checked
            abilities to import and develop stories, review projects, plan
            generation, inspect assets, and exchange editorial data without
            receiving unrestricted access to the site.
          </p>
        </div>
      </section>

      <section className="space-y-5" aria-labelledby="story-graph-about-title">
        <div className="mx-auto max-w-4xl space-y-4">
          <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
            Why a Story Graph
          </p>
          <h2 id="story-graph-about-title" className="text-4xl text-wg-espresso">
            Where human stories and machine context meet.
          </h2>
          <p className="text-lg leading-relaxed text-wg-charcoal/80">
            Story graphs and plot-analysis graphs have been around for a
            while—the 1950s? They are not something I invented. I have enjoyed
            working in this space for a long time, and it is pretty much the
            perfect intersection between how we humans understand the world and
            its stories and how AI perceives ideas and concepts.
          </p>
        </div>
      </section>

      <section
        className="space-y-6 rounded-wg bg-wg-espresso px-6 py-10 text-center text-wg-ivory shadow-wg md:px-10"
        aria-labelledby="open-promise-title"
      >
        <p className="font-headline text-xs font-bold uppercase tracking-[0.22em] text-wg-sepia">
          The original experiment
        </p>
        <h2 id="open-promise-title" className="text-4xl">
          Keep it open. Keep the story yours.
        </h2>
        <p className="mx-auto max-w-4xl text-lg leading-relaxed text-wg-ivory/85">
          My original idea was simply to marry my project sketches in WordPress
          with ComfyUI, see what I could write and generate for free on my
          crappy desktop, run it through some Story Graph analysis tools, and
          see what happened. Pretty quickly, I found that I needed a lot more
          tools, so I built them in.
        </p>
        <p className="mx-auto max-w-3xl rounded-wg bg-wg-sepia p-6 text-lg leading-relaxed text-wg-ink shadow-wg">
          The idea of keeping it open—get in and get out for free—was a core
          part of the effort. I promise never to change that.
        </p>
        <Button href="/story">Explore the Story Graph</Button>
      </section>
    </div>
  );
}
