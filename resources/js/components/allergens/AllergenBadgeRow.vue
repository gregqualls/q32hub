<!--
  AllergenBadgeRow — wraps a recipe's allergen list into a row of AllergenBadge
  chips. Hides itself when there are no allergens. Optional `limit` truncates
  to N badges with a "+N more" indicator, useful on dense cards.
-->
<script setup>
import { computed } from 'vue'
import AllergenBadge from './AllergenBadge.vue'

const props = defineProps({
  allergens: { type: Array, required: true },
  limit: { type: Number, default: 0 }, // 0 = show all
})

const visible = computed(() => {
  if (!props.limit || props.allergens.length <= props.limit) return props.allergens
  return props.allergens.slice(0, props.limit)
})

const overflow = computed(() => {
  if (!props.limit) return 0
  return Math.max(0, props.allergens.length - props.limit)
})
</script>

<template>
  <div v-if="allergens.length" class="flex flex-wrap gap-1.5">
    <AllergenBadge
      v-for="row in visible"
      :key="`${row.id}-${row.presence}`"
      :name="row.name"
      :presence="row.presence"
      :unconfirmed="row.source && row.source !== 'human_confirmed'"
    />
    <span
      v-if="overflow"
      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-sunken text-ink-tertiary"
    >
      +{{ overflow }} more
    </span>
  </div>
</template>
