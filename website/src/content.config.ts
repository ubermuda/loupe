import { defineCollection } from 'astro:content';
import { docsSchema } from '@astrojs/starlight/schema';
import { glob } from 'astro/loaders';

// Starlight's own docsLoader() hardcodes its base to src/content/docs. The
// Markdown lives at the repository root instead, so it stays browsable on
// GitHub and usable by anyone handed a path — hence glob() with an explicit
// base. The negated patterns are internal documents that must never publish:
// the open-work tracker, the manual QA checklist, and the automation notes.
export const collections = {
  docs: defineCollection({
    loader: glob({
      base: '../docs',
      pattern: ['**/[^_]*.md', '!NEXT_STEPS.md', '!MANUAL_TEST_PLAN.md', '!AUTOMATIONS.md', '!superpowers/**'],
    }),
    schema: docsSchema(),
  }),
};
