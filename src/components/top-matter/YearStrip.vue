<template>
  <div class="year-strip hide-scrollbar" v-if="years.length > 1 || selected" ref="strip">
    <NcButton
      v-if="selected"
      class="year-btn"
      :aria-label="t('memories', 'All years')"
      variant="tertiary"
      @click="openYear(null)"
    >
      <template #icon> <CloseIcon :size="18" /> </template>
      {{ t('memories', 'All years') }}
    </NcButton>
    <NcButton
      v-for="y of years"
      :key="y.year"
      class="year-btn"
      :class="{ active: y.year === selected }"
      :title="n('memories', '{n} item', '{n} items', y.count, { n: y.count })"
      :variant="y.year === selected ? 'primary' : 'secondary'"
      @click="openYear(y.year)"
    >
      {{ y.year }}
    </NcButton>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';

import { openYear, routeYear, timelineYears } from '@services/years';

import CloseIcon from 'vue-material-design-icons/Close.vue';

export default defineComponent({
  name: 'YearStrip',

  components: {
    NcButton,
    CloseIcon,
  },

  computed: {
    years() {
      return timelineYears.value;
    },

    selected(): number | null {
      return routeYear();
    },
  },

  watch: {
    selected: 'scrollToActive',
    'years.length': 'scrollToActive',
  },

  mounted() {
    this.scrollToActive();
  },

  methods: {
    openYear,

    async scrollToActive() {
      await this.$nextTick();
      const strip = this.$refs.strip as HTMLDivElement | undefined;
      strip?.querySelector('.active')?.scrollIntoView({ block: 'nearest', inline: 'center' });
    },
  },
});
</script>

<style lang="scss" scoped>
.year-strip {
  display: flex;
  gap: 4px;
  overflow-x: auto;
  white-space: nowrap;
  padding: 4px 10px;

  .year-btn {
    flex-shrink: 0;
    min-height: unset;
    font-size: 0.9em;
  }

  @media (min-width: 769px) {
    margin-right: 44px;
  }
}
</style>
