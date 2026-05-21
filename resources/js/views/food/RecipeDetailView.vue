<template>
  <div class="h-full flex flex-col overflow-y-auto">
    <!-- Loading -->
    <div v-if="isLoading && !recipe" class="flex items-center justify-center py-16">
      <LoadingSpinner size="lg" />
    </div>

    <!-- Not found -->
    <KinEmptyState
      v-else-if="!recipe && !isLoading"
      :icon="ExclamationCircleIcon"
      title="Recipe not found"
      description="This recipe may have been deleted."
      action-text="Back to Recipes"
      @action="$router.push({ name: 'Food' })"
    />

    <!-- Recipe content -->
    <template v-else-if="recipe">
      <!-- Header bar -->
      <div class="px-4 pt-4 pb-2 md:px-6 md:pt-6 flex items-center gap-3">
        <KinButton
          variant="ghost"
          size="sm"
          icon-only
          aria-label="Back to recipes"
          @click="$router.push({ name: 'Food' })"
        >
          <ArrowLeftIcon class="w-5 h-5" />
        </KinButton>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold font-heading text-ink-primary truncate">
            {{ recipe.title }}
          </h1>
        </div>
        <!-- Actions -->
        <div class="flex items-center gap-1">
          <button
            class="p-2 rounded-lg transition-colors"
            :class="recipe.is_favorite ? 'text-status-failed' : 'text-ink-tertiary hover:text-status-failed'"
            aria-label="Toggle favorite"
            @click="handleToggleFavorite"
          >
            <HeartIconSolid v-if="recipe.is_favorite" class="w-5 h-5" />
            <HeartIcon v-else class="w-5 h-5" />
          </button>
          <KinButton
            v-if="isParent"
            variant="ghost"
            size="sm"
            icon-only
            aria-label="Edit recipe"
            @click="showEditForm = true"
          >
            <PencilSquareIcon class="w-5 h-5" />
          </KinButton>
          <KinButton
            v-if="isParent"
            variant="ghost"
            size="sm"
            icon-only
            aria-label="Delete recipe"
            @click="showDeleteConfirm = true"
          >
            <TrashIcon class="w-5 h-5" />
          </KinButton>
        </div>
      </div>

      <!-- Hero image -->
      <div v-if="recipe.image_path" class="px-4 md:px-6 mb-4">
        <div class="aspect-video rounded-xl overflow-hidden bg-surface-sunken">
          <img :src="`/storage/${recipe.image_path}`" :alt="recipe.title" class="w-full h-full object-cover" />
        </div>
      </div>

      <!-- Meta info -->
      <div class="px-4 md:px-6 pb-4 space-y-3">
        <!-- Description -->
        <p v-if="recipe.description" class="text-sm text-ink-secondary">
          {{ recipe.description }}
        </p>

        <!-- Time + servings badges -->
        <div class="flex flex-wrap items-center gap-2">
          <span v-if="recipe.prep_time_minutes" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-surface-sunken text-ink-secondary rounded-full">
            <ClockIcon class="w-3.5 h-3.5" />
            Prep {{ formatTime(recipe.prep_time_minutes) }}
          </span>
          <span v-if="recipe.cook_time_minutes" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-surface-sunken text-ink-secondary rounded-full">
            <FireIcon class="w-3.5 h-3.5" />
            Cook {{ formatTime(recipe.cook_time_minutes) }}
          </span>
          <span v-if="totalTime" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-[#C4975A]/10 text-[#C4975A] rounded-full">
            Total {{ formatTime(totalTime) }}
          </span>
        </div>

        <!-- Tags -->
        <div v-if="recipe.tags?.length" class="flex flex-wrap gap-1.5">
          <KinChip
            v-for="tag in recipe.tags"
            :key="tag.id"
            variant="filter"
            size="sm"
            :custom-color="tag.color"
          >
            {{ tag.name }}
          </KinChip>
        </div>

        <!-- Allergens -->
        <AllergenBadgeRow
          v-if="recipe.allergens?.length"
          :allergens="recipe.allergens"
        />

        <!-- Source -->
        <div v-if="recipe.source_url" class="flex items-center gap-1.5">
          <LinkIcon class="w-3.5 h-3.5 text-ink-tertiary" />
          <a
            :href="recipe.source_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-xs text-[#C4975A] hover:text-[#D4A96A] truncate"
          >
            {{ sourceDomain }}
          </a>
        </div>

        <!-- Creator -->
        <div v-if="recipe.creator" class="flex items-center gap-2 text-xs text-ink-tertiary">
          <UserAvatar :user="recipe.creator" size="xs" />
          <span>Added by {{ recipe.creator.name }}</span>
        </div>
      </div>

      <!-- Divider -->
      <div class="px-4 md:px-6">
        <div class="border-t border-border-subtle"></div>
      </div>

      <!-- Content sections -->
      <div class="px-4 md:px-6 py-4 space-y-6 pb-32 md:pb-6">
        <!-- Ingredients -->
        <section v-if="recipe.ingredients?.length">
          <h2 class="text-base font-semibold font-heading text-ink-primary mb-3">Ingredients</h2>
          <IngredientList
            :ingredients="recipe.ingredients"
            :original-servings="recipe.servings || 4"
          />
        </section>

        <!-- Instructions -->
        <section v-if="recipe.instructions?.length">
          <h2 class="text-base font-semibold font-heading text-ink-primary mb-3">Instructions</h2>
          <StepList :instructions="recipe.instructions" />
        </section>

        <!-- Notes -->
        <section v-if="recipe.notes">
          <h2 class="text-base font-semibold font-heading text-ink-primary mb-2">Notes</h2>
          <p class="text-sm text-ink-secondary whitespace-pre-line">{{ recipe.notes }}</p>
        </section>

        <!-- Rating -->
        <section>
          <h2 class="text-base font-semibold font-heading text-ink-primary mb-3">Rating</h2>
          <FamilyRating
            :recipe-id="recipe.id"
            :average-rating="recipe.family_average_rating || 0"
            :user-rating="recipe.user_rating"
            :ratings="ratings"
            @rate="handleRate"
          />
        </section>

        <!-- Cook Log -->
        <section>
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold font-heading text-ink-primary">Cook Log</h2>
            <button
              class="text-xs font-medium text-[#C4975A] hover:text-[#D4A96A] transition-colors"
              @click="showCookLogModal = true"
            >
              + Log a Cook
            </button>
          </div>

          <div v-if="cookLogs.length === 0" class="text-sm text-ink-tertiary">
            No cook logs yet. Make this recipe and log it!
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="log in cookLogs"
              :key="log.id"
              class="flex gap-3 p-3 bg-surface-sunken rounded-xl"
            >
              <UserAvatar v-if="log.user" :user="log.user" size="sm" class="flex-shrink-0" />
              <div class="min-w-0">
                <div class="flex items-center gap-2 text-xs text-ink-tertiary">
                  <span class="font-medium text-ink-primary">{{ log.user?.name }}</span>
                  <span>{{ formatDate(log.cooked_at) }}</span>
                  <span v-if="log.servings_made">{{ log.servings_made }} servings</span>
                </div>
                <p v-if="log.notes" class="text-sm text-ink-secondary mt-1">{{ log.notes }}</p>
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>

    <!-- Edit form -->
    <KinModalSheet v-model="showEditForm" title="Edit Recipe" size="lg" @close="showEditForm = false">
      <RecipeForm ref="editFormRef" :recipe="recipe" @save="handleUpdate" @cancel="showEditForm = false" />
    </KinModalSheet>

    <!-- Cook log modal -->
    <CookLogEntry
      :show="showCookLogModal"
      :recipe-id="recipe?.id"
      @saved="handleCookLogSaved"
      @close="showCookLogModal = false"
    />

    <!-- Delete confirmation -->
    <ConfirmDialog
      :show="showDeleteConfirm"
      title="Delete Recipe"
      message="This recipe will be moved to trash. You can restore it later."
      confirm-text="Delete"
      variant="danger"
      @confirm="handleDelete"
      @cancel="showDeleteConfirm = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useRecipesStore } from '@/stores/recipes'
import { useAuthStore } from '@/stores/auth'
import { useNotification } from '@/composables/useNotification'
import IngredientList from '@/components/recipes/IngredientList.vue'
import StepList from '@/components/recipes/StepList.vue'
import FamilyRating from '@/components/recipes/FamilyRating.vue'
import CookLogEntry from '@/components/recipes/CookLogEntry.vue'
import RecipeForm from '@/components/recipes/RecipeForm.vue'
import AllergenBadgeRow from '@/components/allergens/AllergenBadgeRow.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UserAvatar from '@/components/common/UserAvatar.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import KinChip from '@/components/design-system/KinChip.vue'
import KinModalSheet from '@/components/design-system/KinModalSheet.vue'
import KinEmptyState from '@/components/design-system/KinEmptyState.vue'
import {
  ArrowLeftIcon,
  HeartIcon,
  PencilSquareIcon,
  TrashIcon,
  ClockIcon,
  FireIcon,
  LinkIcon,
  ExclamationCircleIcon,
} from '@heroicons/vue/24/outline'
import { HeartIcon as HeartIconSolid } from '@heroicons/vue/24/solid'

const route = useRoute()
const router = useRouter()
const recipesStore = useRecipesStore()
const authStore = useAuthStore()
const { currentRecipe: recipe, isLoading } = storeToRefs(recipesStore)
const { success, error: notifyError } = useNotification()

const isParent = computed(() => authStore.isParent)

const showEditForm = ref(false)
const showDeleteConfirm = ref(false)
const showCookLogModal = ref(false)
const cookLogs = ref([])
const ratings = ref([])

const totalTime = computed(() => {
  if (!recipe.value) return null
  if (recipe.value.total_time_minutes) return recipe.value.total_time_minutes
  const prep = recipe.value.prep_time_minutes || 0
  const cook = recipe.value.cook_time_minutes || 0
  return prep + cook || null
})

const sourceDomain = computed(() => {
  if (!recipe.value?.source_url) return ''
  try {
    return new URL(recipe.value.source_url).hostname.replace('www.', '')
  } catch {
    return recipe.value.source_url
  }
})

const formatTime = (minutes) => {
  if (!minutes) return ''
  if (minutes < 60) return `${minutes}m`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const loadRecipe = async () => {
  const id = route.params.id
  if (!id) return

  const result = await recipesStore.fetchRecipe(id)
  if (result.success) {
    // Load cook logs and ratings
    const [logsResult, ratingsResult] = await Promise.all([
      recipesStore.fetchCookLogs(id),
      recipesStore.fetchRatings(id),
    ])
    if (logsResult.success) cookLogs.value = logsResult.cookLogs || []
    if (ratingsResult.success) ratings.value = ratingsResult.ratings || []
  }
}

const handleToggleFavorite = async () => {
  const result = await recipesStore.toggleFavorite(recipe.value.id)
  if (!result.success) notifyError(result.error)
}

const handleRate = async (score) => {
  const result = await recipesStore.rateRecipe(recipe.value.id, score)
  if (result.success) {
    // Refresh ratings
    const ratingsResult = await recipesStore.fetchRatings(recipe.value.id)
    if (ratingsResult.success) ratings.value = ratingsResult.ratings || []
    // Refetch recipe to update average
    await recipesStore.fetchRecipe(recipe.value.id)
  } else {
    notifyError(result.error)
  }
}

const handleCookLogSaved = async (data) => {
  const result = await recipesStore.addCookLog(recipe.value.id, data)
  if (result.success) {
    showCookLogModal.value = false
    success('Cook logged!')
    // Refresh cook logs
    const logsResult = await recipesStore.fetchCookLogs(recipe.value.id)
    if (logsResult.success) cookLogs.value = logsResult.cookLogs || []
  } else {
    notifyError(result.error)
  }
}

const editFormRef = ref(null)

const handleUpdate = async (formData) => {
  const result = await recipesStore.updateRecipe(recipe.value.id, formData)
  if (result.success) {
    showEditForm.value = false
    success('Recipe updated!')
  } else {
    notifyError(result.error)
    if (editFormRef.value) editFormRef.value.saving = false
  }
}

const handleDelete = async () => {
  const result = await recipesStore.deleteRecipe(recipe.value.id)
  if (result.success) {
    showDeleteConfirm.value = false
    success('Recipe deleted')
    router.push({ name: 'Food' })
  } else {
    notifyError(result.error)
  }
}

// Watch for route param changes (e.g., navigating between recipes)
watch(() => route.params.id, (newId) => {
  if (newId) loadRecipe()
})

onMounted(() => {
  recipesStore.clearCurrentRecipe()
  loadRecipe()
})
</script>
