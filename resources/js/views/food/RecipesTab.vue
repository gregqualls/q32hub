<template>
  <div class="h-full flex flex-col overflow-hidden">
    <!-- Search + controls -->
    <div class="px-4 md:px-6 pt-2 md:pt-4 pb-2 space-y-2 md:space-y-3">
      <!-- Search + view toggle -->
      <div class="flex items-center gap-2 sm:gap-3">
        <div class="flex-1">
          <KinSearch v-model="searchInput" placeholder="Search recipes..." @input="onSearchInput" />
        </div>
        <button
          class="flex-shrink-0 p-2.5 rounded-[10px] transition-colors bg-surface-sunken text-ink-secondary hover:bg-surface-overlay"
          :title="viewMode === 'grid' ? 'Switch to compact view' : 'Switch to grid view'"
          @click="toggleViewMode"
        >
          <Squares2X2Icon v-if="viewMode === 'compact'" class="w-4 h-4" />
          <ListBulletIcon v-else class="w-4 h-4" />
        </button>
      </div>

      <!-- Filter row -->
      <div class="flex items-center gap-2 overflow-x-auto scrollbar-hide pb-1">
        <!-- Sort dropdown -->
        <KinSelect
          v-model="sortBy"
          size="sm"
          :options="sortOptions"
          class="w-32"
          @change="onSortChange"
        />

        <!-- Favorites chip -->
        <KinChip
          variant="filter"
          size="sm"
          color="peach"
          :active="showFavoritesOnly"
          @click="toggleFavorites"
        >
          <template #leading><HeartIcon class="w-3 h-3" /></template>
          Favorites
        </KinChip>

        <!-- Safe-for-member filter (food module) -->
        <SafeForMemberFilter v-if="foodEnabled" v-model="safeForMemberIds" />

        <!-- Divider -->
        <div class="w-px h-4 bg-border-subtle flex-shrink-0"></div>

        <!-- Tag chips — only show tags used on at least one recipe -->
        <KinChip
          v-for="tag in recipeTags"
          :key="tag.id"
          variant="filter"
          size="sm"
          :custom-color="tag.color || '#C4975A'"
          :active="isTagSelected(tag.id)"
          class="flex-shrink-0 whitespace-nowrap"
          @click="toggleTagFilter(tag.id)"
        >
          {{ tag.name }}
        </KinChip>
      </div>
    </div>

    <!-- Divider -->
    <div class="px-4 md:px-6">
      <div class="border-t border-border-subtle"></div>
    </div>

    <!-- Loading -->
    <div v-if="isLoading && recipes.length === 0" class="flex items-center justify-center py-16">
      <LoadingSpinner size="lg" />
    </div>

    <!-- Empty state -->
    <KinEmptyState
      v-else-if="!isLoading && recipes.length === 0"
      :icon="FireIcon"
      title="No recipes yet"
      description="Add your first recipe to get started. Import from a URL, snap a photo, or create one from scratch."
      accent-color="peach"
      size="md"
    >
      <template #cta>
        <KinButton variant="primary" @click="showAddMenu = true">Add Recipe</KinButton>
      </template>
    </KinEmptyState>

    <!-- Recipe list -->
    <div v-else class="flex-1 overflow-y-auto px-4 md:px-6 pt-2 md:pt-4 pb-32 md:pb-6">
      <!-- Grid view -->
      <div v-if="viewMode === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <RecipeCard
          v-for="recipe in recipes"
          :key="recipe.id"
          :recipe="recipe"
          @toggle-favorite="handleToggleFavorite"
        />
      </div>

      <!-- Compact list view -->
      <div v-else class="space-y-1">
        <div
          v-for="recipe in recipes"
          :key="recipe.id"
          class="flex items-center gap-3 px-3 py-2.5 rounded-[10px] bg-surface-raised border border-border-subtle cursor-pointer hover:border-[#C4975A]/40 transition-colors"
          @click="$router.push({ name: 'RecipeDetail', params: { id: recipe.id } })"
        >
          <!-- Thumbnail — image when present, gradient fallback otherwise (matches the grid card pattern) -->
          <div
            class="w-12 h-12 rounded-lg overflow-hidden flex-shrink-0 bg-surface-raised"
            :style="recipe.image_path ? null : recipeFallbackStyle(recipe)"
          >
            <img
              v-if="recipe.image_path"
              :src="resolveImageUrl(recipe.image_path)"
              :alt="recipe.title"
              class="w-full h-full object-cover"
            />
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-ink-primary truncate">{{ recipe.title }}</p>
            <div class="flex items-center gap-2 mt-0.5">
              <span v-if="compactTime(recipe)" class="flex items-center gap-1 text-xs text-ink-tertiary">
                <ClockIcon class="w-3 h-3" />
                {{ compactTime(recipe) }}
              </span>
              <span
                v-for="tag in (recipe.tags || []).slice(0, 3)"
                :key="tag.id"
                class="px-1.5 py-0.5 text-[10px] font-medium rounded-full"
                :style="{ backgroundColor: (tag.color || '#C4975A') + '20', color: tag.color || '#C4975A' }"
              >
                {{ tag.name }}
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-1 flex-shrink-0">
            <span v-if="recipe.family_average_rating > 0" class="flex items-center gap-0.5 text-xs text-ink-tertiary">
              <StarIcon class="w-3.5 h-3.5 text-[#C4975A]" />
              {{ recipe.family_average_rating }}
            </span>
            <button
              class="p-1.5 rounded-full transition-colors"
              :class="recipe.is_favorite ? 'text-red-500' : 'text-ink-tertiary hover:text-red-400'"
              aria-label="Toggle favorite"
              @click.stop="handleToggleFavorite(recipe.id)"
            >
              <HeartIconSolid v-if="recipe.is_favorite" class="w-4 h-4" />
              <HeartIcon v-else class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Load more -->
      <div v-if="hasMore" class="flex justify-center mt-6">
        <KinButton variant="secondary" size="md" :loading="isLoading" @click="loadMore">
          Load More
        </KinButton>
      </div>
    </div>

    <!-- Floating Action Button -->
    <FloatingActionButton :mobile-only="false" @click="showAddMenu = true" />

    <!-- Add recipe menu (sheet) -->
    <KinModalSheet
      :model-value="showAddMenu"
      title="Add Recipe"
      size="sm"
      @update:model-value="(v) => !v && (showAddMenu = false)"
    >
      <div class="space-y-1">
        <button
          class="w-full flex items-center gap-3 px-4 py-3 text-sm text-ink-primary hover:bg-surface-sunken rounded-[10px] transition-colors"
          @click="openManualCreate"
        >
          <PencilSquareIcon class="w-5 h-5 text-ink-tertiary" />
          Create from scratch
        </button>
        <button
          class="w-full flex items-center gap-3 px-4 py-3 text-sm text-ink-primary hover:bg-surface-sunken rounded-[10px] transition-colors"
          @click="openImportUrl"
        >
          <LinkIcon class="w-5 h-5 text-ink-tertiary" />
          Import from URL
        </button>
        <button
          class="w-full flex items-center gap-3 px-4 py-3 text-sm text-ink-primary hover:bg-surface-sunken rounded-[10px] transition-colors"
          @click="openImportPhoto"
        >
          <CameraIcon class="w-5 h-5 text-ink-tertiary" />
          Import from photo
        </button>
      </div>
    </KinModalSheet>

    <!-- Recipe Form Modal (manual create) -->
    <KinModalSheet
      :model-value="showCreateForm"
      title="New Recipe"
      size="lg"
      @update:model-value="(v) => !v && (showCreateForm = false)"
    >
      <RecipeForm ref="createFormRef" @save="handleCreateRecipe" @cancel="showCreateForm = false" />
    </KinModalSheet>

    <!-- Import Modal -->
    <RecipeImportModal
      v-if="showImportModal"
      :show="showImportModal"
      :initial-tab="importTab"
      @saved="handleImportSaved"
      @close="showImportModal = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRecipesStore } from '@/stores/recipes'
import { useNotification } from '@/composables/useNotification'
import RecipeCard from '@/components/recipes/RecipeCard.vue'
import RecipeForm from '@/components/recipes/RecipeForm.vue'
import RecipeImportModal from '@/components/recipes/RecipeImportModal.vue'
import FloatingActionButton from '@/components/common/FloatingActionButton.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import KinSearch from '@/components/design-system/KinSearch.vue'
import KinSelect from '@/components/design-system/KinSelect.vue'
import KinChip from '@/components/design-system/KinChip.vue'
import KinEmptyState from '@/components/design-system/KinEmptyState.vue'
import KinModalSheet from '@/components/design-system/KinModalSheet.vue'
import SafeForMemberFilter from '@/components/allergens/SafeForMemberFilter.vue'
import { useAuthStore } from '@/stores/auth'
import {
  HeartIcon,
  PencilSquareIcon,
  LinkIcon,
  CameraIcon,
  FireIcon,
  Squares2X2Icon,
  ListBulletIcon,
  ClockIcon,
  StarIcon,
} from '@heroicons/vue/24/outline'
import { HeartIcon as HeartIconSolid } from '@heroicons/vue/24/solid'

const recipesStore = useRecipesStore()
const authStore = useAuthStore()
const { recipes, tags, isLoading, hasMore, searchQuery, sortBy, selectedTagIds, showFavoritesOnly, safeForMemberIds } = storeToRefs(recipesStore)
const { success, error: notifyError } = useNotification()

const foodEnabled = computed(() => authStore.userCanAccessModule('food'))

const searchInput = ref('')
const showAddMenu = ref(false)
const showCreateForm = ref(false)
const showImportModal = ref(false)
const importTab = ref('url')
const viewMode = ref(localStorage.getItem('kinhold-recipe-view') || 'compact')

// Pass-through for absolute URLs; storage prefix for relative paths.
const resolveImageUrl = (path) => {
  if (!path) return null
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('/')) return path
  return `/storage/${path}`
}

// Same fallback-gradient picker as the grid RecipeCard, used by compact rows.
const RECIPE_TAG_TO_GRADIENT = {
  Breakfast: 'sun',
  Lunch:     'mint',
  Dinner:    'lavender',
  Dessert:   'peach',
  Snack:     'warm',
}
const RECIPE_HASH_GRADIENTS = ['warm', 'lavender', 'peach', 'mint', 'sun', 'cool']
const recipeFallbackGradient = (recipe) => {
  const tag = (recipe.tags || [])[0]
  const tagName = typeof tag === 'string' ? tag : tag?.name
  if (tagName && RECIPE_TAG_TO_GRADIENT[tagName]) return RECIPE_TAG_TO_GRADIENT[tagName]
  const s = recipe.title || ''
  let h = 0
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return RECIPE_HASH_GRADIENTS[Math.abs(h) % RECIPE_HASH_GRADIENTS.length]
}

// Inline gradient CSS for the compact-row thumbnail. Same wash math as
// KinPhotoCard's scoped fallback styles, but applied via :style so it works
// cross-component without exposing the scoped class.
const FALLBACK_BG = {
  iridescent: 'var(--gradient-iridescent-subtle)',
  warm:       'var(--gradient-iridescent-warm)',
  lavender:   'radial-gradient(ellipse 100% 90% at 30% 20%, rgb(var(--accent-lavender-soft) / 0.85) 0%, transparent 70%)',
  peach:      'radial-gradient(ellipse 100% 90% at 30% 20%, rgb(var(--accent-peach-soft) / 0.85) 0%, transparent 70%)',
  mint:       'radial-gradient(ellipse 100% 90% at 30% 20%, rgb(var(--accent-mint-soft) / 0.85) 0%, transparent 70%)',
  sun:        'radial-gradient(ellipse 100% 90% at 30% 20%, rgb(var(--accent-sun-soft) / 0.85) 0%, transparent 70%)',
  cool:       'radial-gradient(ellipse 80% 70% at 18% 20%, rgb(var(--accent-lavender-soft) / 0.80) 0%, transparent 70%), radial-gradient(ellipse 70% 60% at 82% 80%, rgb(var(--accent-mint-soft) / 0.80) 0%, transparent 70%)',
}
const recipeFallbackStyle = (recipe) => ({
  backgroundImage: FALLBACK_BG[recipeFallbackGradient(recipe)] ?? FALLBACK_BG.warm,
})

const toggleViewMode = () => {
  viewMode.value = viewMode.value === 'grid' ? 'compact' : 'grid'
  localStorage.setItem('kinhold-recipe-view', viewMode.value)
}

const compactTime = (recipe) => {
  const total = recipe.total_time_minutes || (recipe.prep_time_minutes || 0) + (recipe.cook_time_minutes || 0)
  if (!total) return null
  if (total < 60) return `${total}m`
  const h = Math.floor(total / 60)
  const m = total % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

let searchDebounce = null

const sortOptions = [
  { value: 'recent', label: 'Recent' },
  { value: 'alpha', label: 'A-Z' },
  { value: 'rating', label: 'Top Rated' },
]

// Server returns only food-scoped tags. Show all of them as filter chips so
// the user can filter even before any recipe is tagged.
const recipeTags = computed(() => tags.value)

const isTagSelected = (tagId) => selectedTagIds.value.includes(tagId)

const onSearchInput = () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    searchQuery.value = searchInput.value
    recipesStore.fetchRecipes()
  }, 300)
}

const onSortChange = () => recipesStore.fetchRecipes()

const toggleFavorites = () => {
  showFavoritesOnly.value = !showFavoritesOnly.value
  recipesStore.fetchRecipes()
}

const handleToggleFavorite = async (id) => {
  const result = await recipesStore.toggleFavorite(id)
  if (!result.success) notifyError(result.error)
}

const loadMore = () => {
  const nextPage = recipesStore.pagination.current_page + 1
  recipesStore.fetchRecipes({ page: nextPage })
}

// Tag filter triggers refetch
watch(selectedTagIds, () => {
  recipesStore.fetchRecipes()
}, { deep: true })

// Safe-for-members filter triggers refetch
watch(safeForMemberIds, () => {
  recipesStore.fetchRecipes()
}, { deep: true })

// ── Add recipe flows ──

const openManualCreate = () => {
  showAddMenu.value = false
  showCreateForm.value = true
}

const openImportUrl = () => {
  showAddMenu.value = false
  importTab.value = 'url'
  showImportModal.value = true
}

const openImportPhoto = () => {
  showAddMenu.value = false
  importTab.value = 'photo'
  showImportModal.value = true
}

const createFormRef = ref(null)

const handleCreateRecipe = async (formData) => {
  const result = await recipesStore.createRecipe(formData)
  if (result.success) {
    showCreateForm.value = false
    success('Recipe created!')
  } else {
    notifyError(result.error)
    if (createFormRef.value) createFormRef.value.saving = false
  }
}

const handleImportSaved = () => {
  showImportModal.value = false
  success('Recipe imported!')
}

onMounted(() => {
  recipesStore.fetchRecipes()
  recipesStore.fetchTags()
})
</script>
