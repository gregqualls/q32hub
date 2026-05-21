<!--
  SafeForMemberFilter — chip that opens a small popover to pick one or more
  family members to filter recipes by. Only members with a reviewed allergy
  profile are listed; filtering against an unreviewed profile would be a false-
  safe and is silently dropped on the API side anyway.
-->
<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useAllergensStore } from '@/stores/allergens'
import KinChip from '@/components/design-system/KinChip.vue'
import { ShieldCheckIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: { type: Array, required: true }, // selected member IDs
})

const emit = defineEmits(['update:modelValue'])

const authStore = useAuthStore()
const allergensStore = useAllergensStore()

const open = ref(false)
const rootRef = ref(null)

const familyMembers = computed(() => authStore.familyMembers || [])

const reviewedMembers = computed(() => {
  return familyMembers.value
    .map((m) => ({
      id: m.id,
      name: m.name,
      reviewed: Boolean(allergensStore.profileFor(m.id)?.reviewed_at),
    }))
    .filter((m) => m.reviewed)
})

const selectedNames = computed(() => {
  const map = new Map(familyMembers.value.map((m) => [m.id, m.name]))
  return props.modelValue.map((id) => map.get(id)).filter(Boolean)
})

const label = computed(() => {
  if (props.modelValue.length === 0) return 'Safe for…'
  if (props.modelValue.length === 1) return `Safe for ${selectedNames.value[0]}`
  return `Safe for ${props.modelValue.length} members`
})

const isSelected = (id) => props.modelValue.includes(id)

const toggle = (id) => {
  const next = isSelected(id)
    ? props.modelValue.filter((x) => x !== id)
    : [...props.modelValue, id]
  emit('update:modelValue', next)
}

const clearAll = () => emit('update:modelValue', [])

const onDocumentClick = (e) => {
  if (!open.value) return
  if (rootRef.value && !rootRef.value.contains(e.target)) open.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  // Lazy-load profiles for all family members so reviewed status is accurate
  familyMembers.value.forEach((m) => {
    if (!allergensStore.profileFor(m.id)) allergensStore.fetchMemberProfile(m.id)
  })
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>

<template>
  <div ref="rootRef" class="relative inline-block">
    <KinChip
      variant="filter"
      size="sm"
      color="mint"
      :active="modelValue.length > 0"
      class="flex-shrink-0 whitespace-nowrap"
      @click="open = !open"
    >
      <template #leading>
        <ShieldCheckIcon class="w-3 h-3" />
      </template>
      {{ label }}
      <ChevronDownIcon class="w-3 h-3 ml-0.5" />
    </KinChip>

    <div
      v-if="open"
      class="absolute z-30 top-full left-0 mt-1 w-56 rounded-lg shadow-lg border border-border-subtle bg-surface-raised p-2"
    >
      <div v-if="reviewedMembers.length === 0" class="px-2 py-2 text-xs text-ink-secondary">
        No family member has a reviewed allergy profile yet. Set one up in Settings → Allergens.
      </div>
      <template v-else>
        <button
          v-for="member in reviewedMembers"
          :key="member.id"
          type="button"
          class="w-full flex items-center gap-2 px-2 py-1.5 text-sm text-left rounded hover:bg-surface-sunken"
          @click="toggle(member.id)"
        >
          <input
            type="checkbox"
            class="rounded border-border-subtle"
            :checked="isSelected(member.id)"
            @click.stop="toggle(member.id)"
          />
          <span class="flex-1 text-ink-primary">{{ member.name }}</span>
        </button>
        <button
          v-if="modelValue.length > 0"
          type="button"
          class="w-full mt-1 px-2 py-1.5 text-xs text-ink-tertiary hover:text-ink-primary text-left"
          @click="clearAll"
        >
          Clear filter
        </button>
      </template>
    </div>
  </div>
</template>
