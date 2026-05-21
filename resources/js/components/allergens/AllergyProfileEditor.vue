<!--
  AllergyProfileEditor — per-member allergy profile editor. Wraps AllergenSelector,
  surfaces Save and "I have no allergies" (mark reviewed) actions.
-->
<script setup>
import { ref, computed, watch } from 'vue'
import { useAllergensStore } from '@/stores/allergens'
import AllergenSelector from './AllergenSelector.vue'
import KinButton from '@/components/design-system/KinButton.vue'

const props = defineProps({
  user: { type: Object, required: true }, // { id, name }
  canEdit: { type: Boolean, default: true },
})

const allergens = useAllergensStore()

const selectedIds = ref([])
const saving = ref(false)
const markingReviewed = ref(false)
const lastError = ref('')
const lastSavedAt = ref(null)

const profile = computed(() => allergens.profileFor(props.user.id))
const reviewed = computed(() => Boolean(profile.value?.reviewed_at))

const loadProfile = async () => {
  await allergens.fetchMemberProfile(props.user.id)
  selectedIds.value = [...(profile.value?.allergen_ids ?? [])]
}

watch(() => props.user.id, loadProfile, { immediate: true })

const save = async () => {
  if (!props.canEdit) return
  saving.value = true
  lastError.value = ''
  const result = await allergens.saveMemberProfile(props.user.id, selectedIds.value)
  saving.value = false
  if (!result.success) {
    lastError.value = result.error
  } else {
    lastSavedAt.value = new Date()
  }
}

const markNoAllergies = async () => {
  if (!props.canEdit) return
  markingReviewed.value = true
  lastError.value = ''
  selectedIds.value = []
  const save = await allergens.saveMemberProfile(props.user.id, [])
  markingReviewed.value = false
  if (!save.success) {
    lastError.value = save.error
  } else {
    lastSavedAt.value = new Date()
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-baseline justify-between gap-3">
      <h3 class="text-sm font-semibold text-ink-primary">{{ user.name }}</h3>
      <p v-if="!reviewed" class="text-xs text-status-warning font-medium">
        Not yet reviewed
      </p>
      <p v-else class="text-xs text-ink-tertiary">
        Reviewed
      </p>
    </div>

    <AllergenSelector
      v-model="selectedIds"
      :allergens="allergens.allergens"
      :disabled="!canEdit"
    />

    <p v-if="lastError" class="text-xs text-status-failed" role="alert">
      {{ lastError }}
    </p>

    <div v-if="canEdit" class="flex flex-wrap items-center gap-2 pt-1">
      <KinButton variant="primary" size="sm" :loading="saving" @click="save">
        Save profile
      </KinButton>
      <KinButton
        v-if="!reviewed && selectedIds.length === 0"
        variant="secondary"
        size="sm"
        :loading="markingReviewed"
        @click="markNoAllergies"
      >
        Confirm: no allergies
      </KinButton>
    </div>
  </div>
</template>
