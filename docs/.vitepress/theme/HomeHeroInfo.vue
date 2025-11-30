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

/* Left side on desktop */
.PillarHeroInfo__content {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.PillarHeroInfo__logo {
  max-width: 420px;
  width: 100%;         /* let it shrink on narrow screens */
  height: auto;
}

.PillarHeroInfo__tagline {
  font-size: 1.25rem;
  color: rgba(255, 255, 255, 0.8);
  max-width: 34rem;
}

/* 📱 Mobile tweaks */
@media (max-width: 768px) {
  .PillarHeroInfo {
    justify-content: center;
  }

  .PillarHeroInfo__content {
    align-items: center;
    text-align: center;
  }

  .PillarHeroInfo__logo {
    max-width: 260px;   /* smaller logo on mobile */
  }

  .PillarHeroInfo__tagline {
    font-size: 1.05rem;
    max-width: 90vw;
  }
}
</style>