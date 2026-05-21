<!--
  MealPlannerAllergenWarning — modal shown when adding a recipe to the meal
  plan that contains allergens for one or more family members with reviewed
  profiles. The user must explicitly acknowledge before the entry can land.
-->
<script setup>
import { computed } from 'vue'
import KinModalSheet from '@/components/design-system/KinModalSheet.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import { ShieldExclamationIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show: { type: Boolean, required: true },
  hits: { type: Array, required: true },
  recipeTitle: { type: String, default: '' },
})

const emit = defineEmits(['acknowledge', 'cancel'])

// Group hits by member so the modal reads as "Bob can't have peanuts, milk"
// rather than one line per (member, allergen) pair.
const grouped = computed(() => {
  const byMember = new Map()
  for (const hit of props.hits) {
    if (!byMember.has(hit.member_id)) {
      byMember.set(hit.member_id, {
        member_id: hit.member_id,
        member_name: hit.member_name,
        items: [],
      })
    }
    byMember.get(hit.member_id).items.push({
      allergen_name: hit.allergen_name,
      presence: hit.presence,
    })
  }
  return [...byMember.values()]
})
</script>

<template>
  <KinModalSheet :model-value="show" @update:model-value="(v) => !v && emit('cancel')">
    <div class="space-y-4 p-1">
      <div class="flex items-start gap-3">
        <div class="shrink-0 w-10 h-10 rounded-full bg-status-error/10 flex items-center justify-center">
          <ShieldExclamationIcon class="w-5 h-5 text-status-error" />
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-base font-semibold text-ink-primary">Allergen warning</h2>
          <p class="text-xs text-ink-secondary mt-0.5">
            <template v-if="recipeTitle">"{{ recipeTitle }}" contains</template>
            <template v-else>This recipe contains</template>
            allergens for one or more family members with reviewed profiles.
          </p>
        </div>
      </div>

      <ul class="space-y-2">
        <li
          v-for="entry in grouped"
          :key="entry.member_id"
          class="p-3 rounded-lg bg-status-error/5 border border-status-error/20"
        >
          <p class="text-sm font-semibold text-ink-primary">{{ entry.member_name }}</p>
          <p class="text-xs text-ink-secondary mt-0.5">
            <template v-for="(item, idx) in entry.items" :key="`${entry.member_id}-${item.allergen_name}-${item.presence}`">
              <span v-if="idx > 0">, </span>
              <span :class="item.presence === 'may_contain' ? 'text-status-warning' : 'text-status-error font-medium'">
                {{ item.presence === 'may_contain' ? 'may contain ' : '' }}{{ item.allergen_name.toLowerCase() }}
              </span>
            </template>
          </p>
        </li>
      </ul>

      <p class="text-xs text-ink-tertiary">
        If you'll prepare a separate dish or are aware of the risk, you can plan it anyway. Otherwise pick a different recipe.
      </p>

      <div class="flex flex-wrap gap-2 justify-end pt-2">
        <KinButton variant="ghost" @click="emit('cancel')">Pick something else</KinButton>
        <KinButton variant="primary" @click="emit('acknowledge')">I understand, plan anyway</KinButton>
      </div>
    </div>
  </KinModalSheet>
</template>
