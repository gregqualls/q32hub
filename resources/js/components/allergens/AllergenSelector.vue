<!--
  AllergenSelector — chip-based multi-select for allergens.
  Used by AllergyProfileEditor and (in PR2) the recipe form.
  Parents-only when allowAddCustom is true.
-->
<script setup>
import { computed } from 'vue'
import KinChip from '@/components/design-system/KinChip.vue'

const props = defineProps({
  allergens: { type: Array, required: true },
  modelValue: { type: Array, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const selectedSet = computed(() => new Set(props.modelValue))

const bigNine = computed(() => props.allergens.filter((a) => a.is_big_nine))
const customs = computed(() => props.allergens.filter((a) => !a.is_big_nine))

const toggle = (id) => {
  if (props.disabled) return
  const next = new Set(selectedSet.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  emit('update:modelValue', Array.from(next))
}
</script>

<template>
  <div class="space-y-3">
    <div>
      <p class="text-xs font-medium text-ink-secondary mb-2">Common allergens</p>
      <div class="flex flex-wrap gap-2">
        <KinChip
          v-for="allergen in bigNine"
          :key="allergen.id"
          variant="filter"
          :active="selectedSet.has(allergen.id)"
          :disabled="disabled"
          @click="toggle(allergen.id)"
        >
          {{ allergen.name }}
        </KinChip>
      </div>
    </div>

    <div v-if="customs.length">
      <p class="text-xs font-medium text-ink-secondary mb-2">Your family's allergens</p>
      <div class="flex flex-wrap gap-2">
        <KinChip
          v-for="allergen in customs"
          :key="allergen.id"
          variant="filter"
          color="lavender"
          :active="selectedSet.has(allergen.id)"
          :disabled="disabled"
          @click="toggle(allergen.id)"
        >
          {{ allergen.name }}
        </KinChip>
      </div>
    </div>
  </div>
</template>
