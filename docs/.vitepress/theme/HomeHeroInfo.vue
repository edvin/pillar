<script setup lang="ts">
import { useData } from 'vitepress'

const { frontmatter } = useData()
</script>

<template>
  <section class="PillarHeroInfo">
    <div class="PillarHeroInfo__content">
      <img
          src="/logo.svg"
          alt="Pillar – PHP Event Sourcing"
          class="PillarHeroInfo__logo"
      />

      <p class="PillarHeroInfo__tagline">
        {{ frontmatter.hero?.tagline }}
      </p>
    </div>
  </section>
</template>

<style scoped>
.PillarHeroInfo {
  display: flex;
  align-items: center;
}

.PillarHeroInfo__content {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.PillarHeroInfo__logo {
  max-width: 420px;
  height: auto;
}

.PillarHeroInfo__tagline {
  font-size: 1.25rem;
  color: rgba(255, 255, 255, 0.8);
  max-width: 34rem;
}
</style>