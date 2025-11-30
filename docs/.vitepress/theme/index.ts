import DefaultTheme from 'vitepress/theme'
import { h } from 'vue'
import './custom.css'
import HomeHeroInfo from './HomeHeroInfo.vue'

export default {
    ...DefaultTheme,
    Layout() {
        return h(DefaultTheme.Layout, null, {
            // override the left side of the hero
            'home-hero-info': () => h(HomeHeroInfo),
        })
    },
}
