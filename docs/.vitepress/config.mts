import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

export default withMermaid(defineConfig({
  title: 'Pillar',
  description: 'Elegant DDD & Event Sourcing for Laravel',
  base: '/',
  lastUpdated: true,
  themeConfig: {
    nav: [
      { text: 'Getting started', link: '/getting-started/' },
      { text: 'Tutorial', link: '/tutorials/build-a-document-service' },
    ],
    sidebar: [
      { text: 'Getting started', items: [{ text: 'Overview', link: '/getting-started/' }] },
      { text: 'Tutorial', items: [{ text: 'Build a Document service', link: '/tutorials/build-a-document-service' }] },
      { text: 'Concepts', items: [
        { text: 'Aggregate sessions', link: '/concepts/aggregate-sessions' },
        { text: 'Pillar facade', link: '/concepts/pillar-facade' },
        { text: 'Event Store', link: '/event-store/' },
        { text: 'Ephemeral events', link: '/concepts/ephemeral-events' },
        { text: 'Context registries', link: '/concepts/context-registries' },
        { text: 'Event aliases', link: '/concepts/event-aliases' },
        { text: 'Versioned events', link: '/concepts/versioned-events' },
        { text: 'Aggregate roots', link: '/concepts/aggregate-roots' },
        { text: 'Snapshotting', link: '/concepts/snapshotting' },
        { text: 'Aggregate IDs', link: '/concepts/aggregate-ids' },
        { text: 'Repositories', link: '/concepts/repositories' },
        { text: 'Projectors', link: '/concepts/projectors' }
      ]},
      { text: 'Reference', items: [
        { text: 'CLI — Replay events', link: '/reference/cli-replay' },
        { text: 'Full README (archived)', link: '/reference/readme-full' }
      ]}
    ],
    socialLinks: [{ icon: 'github', link: 'https://github.com/edvin/pillar' }],
    search: { provider: 'local' },
    outline: 'deep'
  },
  markdown: {
    theme: { light: 'github-light', dark: 'github-dark' },
    lineNumbers: true
  },
  mermaid: { theme: { light: 'default', dark: 'dark' } }
}))
