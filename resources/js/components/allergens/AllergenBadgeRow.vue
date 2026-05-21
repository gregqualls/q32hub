<!--
  AllergenBadgeRow — wraps a recipe's allergen list into a row of AllergenBadge
  chips. Hides itself when there are no allergens. Optional `limit` truncates
  to N badges with a "+N more" indicator, useful on dense cards.
-->
<script setup>
import { computed } from 'vue'
import AllergenBadge from './AllergenBadge.vue'
import { CheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  allergens: { type: Array, required: true },
  limit: { type: Number, default: 0 }, // 0 = show all
  // When true, unconfirmed (AI-tagged) badges get an inline "confirm" action.
  // Used on the recipe detail view; cards are display-only.
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm'])

const visible = computed(() => {
  if (!props.limit || props.allergens.length <= props.limit) return props.allergens
  return props.allergens.slice(0, props.limit)
})

const overflow = computed(() => {
  if (!props.limit) return 0
  return Math.max(0, props.allergens.length - props.limit)
})

const isUnconfirmed = (row) => row.source && row.source !== 'human_confirmed'
</script>

<template>
  <div v-if="allergens.length" class="flex flex-wrap gap-1.5 items-center">
    <template v-for="row in visible" :key="`${row.id}-${row.presence}`">
      <span v-if="editable && isUnconfirmed(row)" class="inline-flex items-center gap-0.5">
        <AllergenBadge :name="row.name" :presence="row.presence" :unconfirmed="true" />
        <button
          type="button"
          class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-status-success/15 text-status-success hover:bg-status-success/30 transition-colors"
          :aria-label="`Confirm ${row.name}`"
          :title="`Confirm AI tag for ${row.name}`"
          @click="emit('confirm', row)"
        >
          <CheckIcon class="w-3 h-3" />
        </button>
      </span>
      <AllergenBadge
        v-else
        :name="row.name"
        :presence="row.presence"
        :unconfirmed="isUnconfirmed(row)"
      />
    </template>
    <span
      v-if="overflow"
      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-sunken text-ink-tertiary"
    >
      +{{ overflow }} more
    </span>
  </div>
</template>
