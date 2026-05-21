<!--
  AllergenBadge — single allergen indicator shown on recipe cards and detail pages.
  Two presence levels (contains, may_contain) get distinct visual treatments.
  An optional `unconfirmed` flag dims the badge for AI-tagged-but-not-yet-reviewed
  entries (used in PR 4 — accepts the prop now so AI tags can flow through).
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
  presence: {
    type: String,
    default: 'contains',
    validator: (v) => ['contains', 'may_contain'].includes(v),
  },
  unconfirmed: { type: Boolean, default: false },
})

const isContains = computed(() => props.presence === 'contains')

// Token-aligned: contains uses status-failed tint, may_contain uses status-warning.
// Unconfirmed dims via reduced opacity + dashed outline.
const classes = computed(() => {
  const base = 'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border'
  const tone = isContains.value
    ? 'bg-status-failed/10 text-status-failed border-status-failed/30'
    : 'bg-status-warning/10 text-status-warning border-status-warning/40'
  const confirmation = props.unconfirmed
    ? 'border-dashed opacity-70'
    : ''
  return [base, tone, confirmation].join(' ')
})

const label = computed(() => (isContains.value ? props.name : `May contain ${props.name.toLowerCase()}`))
</script>

<template>
  <span :class="classes" :title="unconfirmed ? 'AI tagged — not yet confirmed' : null">
    {{ label }}
  </span>
</template>
