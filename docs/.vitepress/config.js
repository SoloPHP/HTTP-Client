import { defineConfig } from 'vitepress'

const description = 'Immutable PSR-18 HTTP client for PHP on Guzzle, with safe retries, credentials bound to their origin and redacted logs.'

export default defineConfig({
  title: 'Solo HTTP Client',
  description,
  base: '/HTTP-Client/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/HTTP-Client/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#0ea5e9' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo HTTP Client' }],
    ['meta', { property: 'og:description', content: description }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/' },
      { text: 'API', link: '/guide/api' },
      { text: 'Details', link: '/guide/details' },
      { text: 'v5.0.0-RC3', link: 'https://github.com/SoloPHP/HTTP-Client/releases' },
    ],

    sidebar: [
      {
        text: 'Solo HTTP Client · 5.0',
        items: [
          { text: 'Getting started', link: '/guide/' },
          { text: 'API reference', link: '/guide/api' },
          { text: 'Behavior in detail', link: '/guide/details' },
          { text: 'Upgrading from 4.x', link: 'https://github.com/SoloPHP/HTTP-Client/blob/main/CHANGELOG.md#upgrading-from-4x' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/HTTP-Client' },
    ],

    footer: {
      message: 'MIT licensed.',
      copyright: 'Copyright © 2025-2026 SoloPHP',
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/HTTP-Client/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: {
      provider: 'local',
    },
  },
})
