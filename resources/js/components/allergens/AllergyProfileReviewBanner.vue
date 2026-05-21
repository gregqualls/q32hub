<!--
  AllergyProfileReviewBanner — dashboard prompt shown until a member's allergy
  profile has been explicitly reviewed (even if reviewed-as-empty).
  Hidden when food module is off or when the profile is already reviewed.
-->
<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAllergensStore } from '@/stores/allergens'
import KinButton from '@/components/design-system/KinButton.vue'
import { ShieldExclamationIcon } from '@heroicons/vue/24/outline'

const router = useRouter()
const auth = useAuthStore()
const allergens = useAllergensStore()

const foodEnabled = computed(() => auth.userCanAccessModule('food'))
const userId = computed(() => auth.user?.id)

const profile = computed(() => (userId.value ? allergens.profileFor(userId.value) : null))
const reviewed = computed(() => Boolean(profile.value?.reviewed_at))
const visible = computed(() => foodEnabled.value && userId.value && profile.value !== null && !reviewed.value)

onMounted(async () => {
  if (foodEnabled.value && userId.value) {
    await allergens.fetchMemberProfile(userId.value)
  }
})

const goToSettings = () => {
  router.push({ name: 'Settings', hash: '#allergens' })
}
</script>

<template>
  <div
    v-if="visible"
    class="flex items-start gap-3 p-4 rounded-lg bg-status-warning/10 border border-status-warning/30"
    role="status"
  >
    <ShieldExclamationIcon class="w-5 h-5 text-status-warning shrink-0 mt-0.5" />
    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium text-ink-primary">Set up your allergy profile</p>
      <p class="text-xs text-ink-secondary mt-0.5">
        Tell Kinhold about any food allergies so the meal planner can warn you. Takes about 30 seconds.
      </p>
    </div>
    <KinButton variant="secondary" size="sm" @click="goToSettings">
      Set up
    </KinButton>
  </div>
</template>
