import {defineConfig} from 'vitepress'
import {withMermaid} from 'vitepress-plugin-mermaid'

export default withMermaid(defineConfig({
    title: 'Pillar',
    description: 'Elegant DDD & Event Sourcing for Laravel',
    base: '/',
    lastUpdated: true,
    themeConfig: {
        nav: [
            {text: 'Getting started', link: '/getting-started/'},
            {text: 'Tutorial', link: '/tutorials/build-a-document-service'},
            {text: 'Why Pillar', link: '/about/philosophy'},
            {text: 'Architecture', link: '/architecture/overview'},
            {text: 'Config', link: '/reference/configuration'},
        ],
        sidebar: [
            {text: 'Getting started', items: [{text: 'Overview', link: '/getting-started/'}]},
            {
                text: 'Tutorial',
                items: [{text: 'Build a Document service', link: '/tutorials/build-a-document-service'}]
            },
            {
                text: 'Concepts', items: [
                    {text: 'Aggregate roots', link: '/concepts/aggregate-roots'},
                    {text: 'Aggregate IDs', link: '/concepts/aggregate-ids'},
                    {text: 'Aggregate sessions', link: '/concepts/aggregate-sessions'},
                    {text: 'Event Store', link: '/event-store/'},
                    {text: 'Event Window', link: '/concepts/event-window'},
                    {text: 'Repositories', link: '/concepts/repositories'},
                    {text: 'Snapshotting', link: '/concepts/snapshotting'},
                    {text: 'Projectors', link: '/concepts/projectors'},
                    {
                        text: 'Serialization', link: '/concepts/serialization', items: [
                            {text: 'Payload encryption', link: '/concepts/serialization#payload-encryption'},
                        ]
                    },
                    {text: 'Context registries', link: '/concepts/context-registries'},
                    {text: 'Event aliases', link: '/concepts/event-aliases'},
                    {text: 'Versioned events', link: '/concepts/versioned-events'},
                    {text: 'Event upcasters', link: '/concepts/event-upcasters'},
                    {text: 'Ephemeral events', link: '/concepts/ephemeral-events'},
                    {text: 'Pillar facade', link: '/concepts/pillar-facade'},
                    {text: 'Commands & Queries', link: '/concepts/commands-and-queries'}
                ]
            },
            {
                text: 'Guides', items: [
                    {text: 'Testing', link: '/guides/testing'}
                ]
            },
            {
                text: 'Architecture', items: [
                    {text: 'Overview', link: '/architecture/overview'}
                ]
            },
            {
                text: 'Reference', items: [
                    {text: 'Configuration', link: '/reference/configuration'},
                    {text: 'CLI — Replay events', link: '/reference/cli-replay'},
                    {text: 'CLI — Make (scaffolding)', link: '/reference/cli-make'},
                ]
            },
            {
                text: 'About', items: [
                    {text: 'Features', link: '/about/features'},
                    {text: 'Why Pillar', link: '/about/philosophy'},
                    {text: 'Contributing', link: '/about/contributing'},
                    {text: 'License', link: '/about/license'},
                    {text: 'FAQ', link: '/about/faq'}
                ]
            }
        ],
        socialLinks: [{icon: 'github', link: 'https://github.com/edvin/pillar'}],
        search: {provider: 'local'},
        outline: [2, 3]
    },
    markdown: {
        theme: {light: 'github-light', dark: 'github-dark'},
        lineNumbers: true
    },
    mermaid: {theme: {light: 'default', dark: 'dark'}}
}))
