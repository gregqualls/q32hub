<template>
  <div>
    <FoodCard
      :title="recipe.title"
      :image-url="recipe.image_path"
      :fallback-gradient="fallbackGradient"
      :is-favorite="!!recipe.is_favorite"
      :meta-items="metaItems"
      :tags="recipe.tags || []"
      @click="$router.push({ name: 'RecipeDetail', params: { id: recipe.id } })"
      @toggle-favorite="$emit('toggleFavorite', recipe.id)"
    />
    <AllergenBadgeRow
      v-if="recipe.allergens && recipe.allergens.length"
      :allergens="recipe.allergens"
      :limit="3"
      class="mt-2 px-1"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import {
  ClockIcon,
  UsersIcon,
  StarIcon,
} from '@heroicons/vue/24/outline'
import FoodCard from '@/components/food/FoodCard.vue'
import AllergenBadgeRow from '@/components/allergens/AllergenBadgeRow.vue'

const props = defineProps({
  recipe: { type: Object, required: true },
})

defineEmits(['toggleFavorite'])

const totalTime = computed(() => {
  if (props.recipe.total_time_minutes) return props.recipe.total_time_minutes
  const prep = props.recipe.prep_time_minutes || 0
  const cook = props.recipe.cook_time_minutes || 0
  return prep + cook || null
})

const formatTime = (minutes) => {
  if (!minutes) return null
  if (minutes < 60) return `${minutes}m`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

const metaItems = computed(() => {
  const items = []
  if (totalTime.value) items.push({ icon: ClockIcon, text: formatTime(totalTime.value) })
  if (props.recipe.servings) items.push({ icon: UsersIcon, text: String(props.recipe.servings) })
  if (props.recipe.family_average_rating > 0) items.push({ icon: StarIcon, text: String(props.recipe.family_average_rating), iconClass: 'text-[#C4975A]' })
  return items
})

// ── Gradient picker for the no-image fallback ────────────────────────────────
//
// Pick by first food tag if one is present (Breakfast → sun, Lunch → mint,
// Dinner → lavender, Dessert → peach, Snack → warm), otherwise hash the title
// across the four accent families so each recipe gets a stable, distinct
// gradient instead of every fallback looking identical.
const TAG_TO_GRADIENT = {
  Breakfast: 'sun',
  Lunch:     'mint',
  Dinner:    'lavender',
  Dessert:   'peach',
  Snack:     'warm',
}
const HASH_GRADIENTS = ['warm', 'lavender', 'peach', 'mint', 'sun', 'cool']

function hashTitle(s) {
  let h = 0
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return Math.abs(h)
}

const fallbackGradient = computed(() => {
  const tag = (props.recipe.tags || [])[0]
  const tagName = typeof tag === 'string' ? tag : tag?.name
  if (tagName && TAG_TO_GRADIENT[tagName]) return TAG_TO_GRADIENT[tagName]
  return HASH_GRADIENTS[hashTitle(props.recipe.title || '') % HASH_GRADIENTS.length]
})
</script>
