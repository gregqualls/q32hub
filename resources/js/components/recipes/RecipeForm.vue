<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
    <!-- Basic info -->
    <div class="space-y-4">
      <!-- Title -->
      <div>
        <label class="block text-sm font-medium text-ink-primary mb-1">Title <span class="text-status-failed">*</span></label>
        <input
          v-model="form.title"
          type="text"
          required
          placeholder="Recipe name"
          class="input-base"
        />
      </div>

      <!-- Description -->
      <div>
        <label class="block text-sm font-medium text-ink-primary mb-1">Description</label>
        <textarea
          v-model="form.description"
          rows="2"
          placeholder="Brief description"
          class="input-base resize-none"
        ></textarea>
      </div>

      <!-- Row: Servings / Prep / Cook -->
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-ink-primary mb-1">Servings</label>
          <input
            v-model.number="form.servings"
            type="number"
            min="1"
            max="100"
            placeholder="4"
            class="input-base"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-ink-primary mb-1">Prep (min)</label>
          <input
            v-model.number="form.prep_time_minutes"
            type="number"
            min="0"
            placeholder="0"
            class="input-base"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-ink-primary mb-1">Cook (min)</label>
          <input
            v-model.number="form.cook_time_minutes"
            type="number"
            min="0"
            placeholder="0"
            class="input-base"
          />
        </div>
      </div>

      <!-- Source URL (readonly if imported) -->
      <div v-if="form.source_url">
        <label class="block text-sm font-medium text-ink-primary mb-1">Source</label>
        <input
          v-model="form.source_url"
          type="url"
          readonly
          class="input-base bg-surface-sunken cursor-not-allowed"
        />
      </div>

      <!-- Photo -->
      <PhotoUpload v-model="imageDisplayUrl" label="Photo" :uploader="uploadRecipeImage" />
    </div>

    <!-- Ingredients -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <label class="text-sm font-semibold text-ink-primary">Ingredients</label>
        <button
          type="button"
          class="text-xs font-medium text-[#C4975A] hover:text-[#D4A96A] transition-colors"
          @click="addIngredient"
        >
          + Add ingredient
        </button>
      </div>
      <div class="space-y-2">
        <div
          v-for="(ing, index) in form.ingredients"
          :key="index"
          class="flex items-start gap-2 p-3 bg-surface-sunken rounded-xl"
        >
          <div class="flex-1 grid grid-cols-12 gap-2">
            <!-- Quantity -->
            <input
              v-model="ing.quantity"
              type="text"
              placeholder="Qty"
              class="input-base col-span-2 text-center"
            />
            <!-- Unit -->
            <input
              v-model="ing.unit"
              type="text"
              placeholder="Unit"
              class="input-base col-span-2"
            />
            <!-- Name -->
            <input
              v-model="ing.name"
              type="text"
              placeholder="Ingredient name"
              required
              class="input-base col-span-5"
            />
            <!-- Preparation -->
            <input
              v-model="ing.preparation"
              type="text"
              placeholder="Prep"
              class="input-base col-span-3"
            />
          </div>
          <!-- Remove -->
          <button
            type="button"
            class="p-1.5 text-ink-tertiary hover:text-status-failed transition-colors flex-shrink-0 mt-1"
            aria-label="Remove ingredient"
            @click="removeIngredient(index)"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
      <button
        v-if="form.ingredients.length === 0"
        type="button"
        class="w-full mt-2 py-3 text-sm text-ink-tertiary border border-dashed border-border-subtle rounded-xl hover:border-[#C4975A] hover:text-[#C4975A] transition-colors"
        @click="addIngredient"
      >
        + Add your first ingredient
      </button>
    </div>

    <!-- Instructions -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <label class="text-sm font-semibold text-ink-primary">Instructions</label>
        <button
          type="button"
          class="text-xs font-medium text-[#C4975A] hover:text-[#D4A96A] transition-colors"
          @click="addInstruction"
        >
          + Add step
        </button>
      </div>
      <div class="space-y-2">
        <div
          v-for="(step, index) in form.instructions"
          :key="index"
          class="flex items-start gap-2"
        >
          <!-- Step number -->
          <span class="w-7 h-7 rounded-full bg-[#C4975A]/10 text-[#C4975A] text-xs font-semibold flex items-center justify-center flex-shrink-0 mt-1">
            {{ index + 1 }}
          </span>
          <!-- Step text -->
          <textarea
            v-model="form.instructions[index]"
            rows="2"
            placeholder="Describe this step..."
            class="input-base flex-1 resize-none"
          ></textarea>
          <!-- Remove -->
          <button
            type="button"
            class="p-1.5 text-ink-tertiary hover:text-status-failed transition-colors flex-shrink-0 mt-1"
            aria-label="Remove step"
            @click="removeInstruction(index)"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
      <button
        v-if="form.instructions.length === 0"
        type="button"
        class="w-full mt-2 py-3 text-sm text-ink-tertiary border border-dashed border-border-subtle rounded-xl hover:border-[#C4975A] hover:text-[#C4975A] transition-colors"
        @click="addInstruction"
      >
        + Add your first step
      </button>
    </div>

    <!-- Tags -->
    <div v-if="allTags.length > 0">
      <label class="block text-sm font-semibold text-ink-primary mb-2">Tags</label>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="tag in recipeTags"
          :key="tag.id"
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-full transition-colors"
          :class="form.tag_ids.includes(tag.id)
            ? 'text-white'
            : 'bg-surface-sunken text-ink-secondary hover:bg-surface-overlay'"
          :style="form.tag_ids.includes(tag.id) ? { backgroundColor: tag.color || '#C4975A' } : {}"
          @click="toggleTag(tag.id)"
        >
          {{ tag.name }}
        </button>
      </div>
    </div>

    <!-- Allergens -->
    <div v-if="foodEnabled && allergensStore.allergens.length > 0">
      <label class="block text-sm font-semibold text-ink-primary mb-1">Allergens</label>
      <p class="text-xs text-ink-secondary mb-2">
        Tap once for "contains", twice for "may contain", three times to clear.
      </p>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="allergen in allergensStore.allergens"
          :key="allergen.id"
          type="button"
          :class="allergenChipClass(allergen.id)"
          @click="cycleAllergen(allergen.id)"
        >
          {{ allergenChipLabel(allergen) }}
        </button>
      </div>
    </div>

    <!-- Notes -->
    <div>
      <label class="block text-sm font-medium text-ink-primary mb-1">Notes</label>
      <textarea
        v-model="form.notes"
        rows="3"
        placeholder="Tips, variations, or personal notes..."
        class="input-base resize-none"
      ></textarea>
    </div>

    <!-- Actions -->
    <div class="flex gap-3 justify-end pt-2">
      <button
        type="button"
        class="px-4 py-2.5 text-sm font-medium text-ink-primary bg-surface-sunken hover:bg-surface-overlay rounded-[10px] transition-colors"
        @click="$emit('cancel')"
      >
        Cancel
      </button>
      <button
        type="submit"
        class="px-6 py-2.5 text-sm font-medium text-white bg-[#C4975A] hover:bg-[#D4A96A] rounded-[10px] transition-colors disabled:opacity-50"
        :disabled="!form.title || saving"
      >
        {{ saving ? 'Saving...' : (recipe ? 'Update Recipe' : 'Save Recipe') }}
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRecipesStore } from '@/stores/recipes'
import { useAllergensStore } from '@/stores/allergens'
import { useAuthStore } from '@/stores/auth'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import PhotoUpload from '@/components/food/PhotoUpload.vue'

// Convert a decimal like 0.5 to a display string like "1/2" for common fractions.
// Used when loading stored recipes into the form.
const decimalToFraction = (value) => {
  if (value === null || value === undefined || value === '') return ''
  const num = parseFloat(value)
  if (isNaN(num)) return String(value)
  if (num === Math.floor(num)) return String(Math.floor(num))

  const whole = Math.floor(num)
  const dec = Math.round((num - whole) * 1000) / 1000
  const map = { 0.125: '1/8', 0.25: '1/4', 0.333: '1/3', 0.375: '3/8', 0.5: '1/2', 0.625: '5/8', 0.667: '2/3', 0.75: '3/4', 0.875: '7/8' }

  const frac = Object.entries(map).find(([d]) => Math.abs(dec - parseFloat(d)) < 0.005)?.[1]
  if (!frac) return String(num)
  return whole > 0 ? `${whole} ${frac}` : frac
}

// Parse a fraction/unicode/mixed-number string to a float for the API payload.
// Returns the original string if it's already numeric, or null if empty.
const parseFractionToFloat = (value) => {
  if (value === null || value === undefined || value === '') return null
  const str = String(value).trim()
  if (str === '') return null
  if (Number.isFinite(Number(str))) return Number(str)

  const unicodeMap = { '½': 0.5, '¼': 0.25, '¾': 0.75, '⅓': 1/3, '⅔': 2/3, '⅛': 0.125, '⅜': 0.375, '⅝': 0.625, '⅞': 0.875 }
  if (unicodeMap[str] !== undefined) return unicodeMap[str]

  // Integer + unicode: "1½"
  for (const [ch, frac] of Object.entries(unicodeMap)) {
    const m = str.match(new RegExp('^(\\d+)' + ch + '$'))
    if (m) return parseInt(m[1]) + frac
    const m2 = str.match(new RegExp('^(\\d+)\\s+' + ch + '$'))
    if (m2) return parseInt(m2[1]) + frac
  }

  // Simple fraction: "1/2"
  const sf = str.match(/^(\d+)\s*\/\s*(\d+)$/)
  if (sf && parseInt(sf[2]) !== 0) return parseInt(sf[1]) / parseInt(sf[2])

  // Mixed number: "1 1/2"
  const mf = str.match(/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/)
  if (mf && parseInt(mf[3]) !== 0) return parseInt(mf[1]) + parseInt(mf[2]) / parseInt(mf[3])

  return str // Return as-is; backend will reject if truly invalid
}

const props = defineProps({
  recipe: { type: Object, default: null },
  initialData: { type: Object, default: null },
})

const emit = defineEmits(['save', 'cancel'])

const recipesStore = useRecipesStore()
const allergensStore = useAllergensStore()
const authStore = useAuthStore()
const { tags: allTags } = storeToRefs(recipesStore)

const foodEnabled = computed(() => authStore.userCanAccessModule('food'))

// Server returns only food-scoped tags via the recipes store.
const recipeTags = computed(() => allTags.value)

const saving = ref(false)
const imageDisplayUrl = ref(null)

const createEmptyForm = () => ({
  title: '',
  description: '',
  servings: 4,
  prep_time_minutes: null,
  cook_time_minutes: null,
  source_url: '',
  source_type: 'manual',
  image_path: null,
  notes: '',
  ingredients: [],
  instructions: [],
  tag_ids: [],
  allergens: [], // [{ allergen_id, presence }]
})

const form = reactive(createEmptyForm())

const populateFromRecipe = (recipe) => {
  form.title = recipe.title || ''
  form.description = recipe.description || ''
  form.servings = recipe.servings || 4
  form.prep_time_minutes = recipe.prep_time_minutes || null
  form.cook_time_minutes = recipe.cook_time_minutes || null
  form.source_url = recipe.source_url || ''
  form.source_type = recipe.source_type || 'manual'
  form.image_path = recipe.image_path || null
  form.notes = recipe.notes || ''
  if (recipe.image_path) {
    imageDisplayUrl.value = `/storage/${recipe.image_path}`
  }
  form.ingredients = (recipe.ingredients || []).map((ing) => ({
    name: ing.name || '',
    quantity: decimalToFraction(ing.quantity),
    unit: ing.unit || '',
    preparation: ing.preparation || '',
    group_name: ing.group_name || '',
    is_optional: ing.is_optional || false,
  }))
  form.instructions = (recipe.instructions || []).map((step) =>
    typeof step === 'string' ? step : (step.text || '')
  )
  form.tag_ids = (recipe.tags || []).map((t) => t.id)
  form.allergens = (recipe.allergens || []).map((a) => ({
    allergen_id: a.id,
    presence: a.presence || 'contains',
  }))
}

const populateFromImportPreview = (data) => {
  form.title = data.title || ''
  form.description = data.description || ''
  form.servings = data.servings || 4
  form.prep_time_minutes = data.prep_time || data.prep_time_minutes || null
  form.cook_time_minutes = data.cook_time || data.cook_time_minutes || null
  form.source_url = data.source_url || ''
  form.source_type = data.source_type || 'url'
  form.image_path = data.image_path || null
  if (data.image_path) {
    imageDisplayUrl.value = `/storage/${data.image_path}`
  }
  form.ingredients = (data.ingredients || []).map((ing) => ({
    name: ing.name || '',
    quantity: decimalToFraction(ing.quantity),
    unit: ing.unit || '',
    preparation: ing.preparation || '',
    group_name: '',
    is_optional: false,
  }))
  form.instructions = (data.instructions || []).map((step) =>
    typeof step === 'string' ? step : (step.text || '')
  )
  form.tag_ids = []
  form.allergens = []
}

// ── Ingredient actions ──

const addIngredient = () => {
  form.ingredients.push({ name: '', quantity: '', unit: '', preparation: '', group_name: '', is_optional: false })
}

const removeIngredient = (index) => {
  form.ingredients.splice(index, 1)
}

// ── Instruction actions ──

const addInstruction = () => {
  form.instructions.push('')
}

const removeInstruction = (index) => {
  form.instructions.splice(index, 1)
}

// ── Image upload (used by PhotoUpload component) ──

const uploadRecipeImage = async (file) => {
  const result = await recipesStore.uploadImage(file)
  if (result.success) {
    form.image_path = result.imagePath
    imageDisplayUrl.value = `/storage/${result.imagePath}`
    return { success: true, url: imageDisplayUrl.value }
  }
  return { success: false }
}

// ── Tag toggle ──

const toggleTag = (tagId) => {
  const idx = form.tag_ids.indexOf(tagId)
  if (idx === -1) {
    form.tag_ids.push(tagId)
  } else {
    form.tag_ids.splice(idx, 1)
  }
}

// ── Allergen tri-state cycle: off → contains → may_contain → off ──

const allergenState = (id) => {
  const entry = form.allergens.find((a) => a.allergen_id === id)
  return entry?.presence || 'off'
}

const cycleAllergen = (id) => {
  const idx = form.allergens.findIndex((a) => a.allergen_id === id)
  if (idx === -1) {
    form.allergens.push({ allergen_id: id, presence: 'contains' })
  } else if (form.allergens[idx].presence === 'contains') {
    form.allergens[idx] = { allergen_id: id, presence: 'may_contain' }
  } else {
    form.allergens.splice(idx, 1)
  }
}

const allergenChipClass = (id) => {
  const base = 'px-3 py-1.5 text-xs font-medium rounded-full border transition-colors'
  const state = allergenState(id)
  if (state === 'contains') return `${base} bg-status-error/10 text-status-error border-status-error/40`
  if (state === 'may_contain') return `${base} bg-status-warning/10 text-status-warning border-status-warning/40 border-dashed`
  return `${base} bg-surface-sunken text-ink-secondary border-transparent hover:bg-surface-overlay`
}

const allergenChipLabel = (allergen) => {
  const state = allergenState(allergen.id)
  if (state === 'may_contain') return `May contain ${allergen.name.toLowerCase()}`
  return allergen.name
}

// ── Submit ──

const handleSubmit = () => {
  if (!form.title) return
  saving.value = true

  // Build the payload matching the API schema
  const payload = {
    title: form.title,
    description: form.description || null,
    servings: form.servings || 4,
    prep_time_minutes: form.prep_time_minutes || null,
    cook_time_minutes: form.cook_time_minutes || null,
    source_url: form.source_url || null,
    source_type: form.source_type || 'manual',
    image_path: form.image_path || null,
    notes: form.notes || null,
    ingredients: form.ingredients
      .filter((ing) => ing.name.trim())
      .map((ing, idx) => ({
        name: ing.name.trim(),
        quantity: parseFractionToFloat(ing.quantity),
        unit: ing.unit || null,
        preparation: ing.preparation || null,
        group_name: ing.group_name || null,
        is_optional: ing.is_optional || false,
        sort_order: idx,
      })),
    instructions: form.instructions
      .filter((s) => s.trim())
      .map((text, idx) => ({ step: idx + 1, text: text.trim() })),
    tag_ids: form.tag_ids,
    allergens: form.allergens,
  }

  emit('save', payload)
  // Parent is responsible for resetting saving state
}

// Watch for external data changes
watch(() => props.initialData, (data) => {
  if (data) populateFromImportPreview(data)
}, { immediate: true })

onMounted(() => {
  if (props.recipe) {
    populateFromRecipe(props.recipe)
  } else if (props.initialData) {
    populateFromImportPreview(props.initialData)
  }

  // Ensure tags are loaded
  if (allTags.value.length === 0) {
    recipesStore.fetchTags()
  }

  // Load allergens (food module gating already enforced at API level)
  if (foodEnabled.value && allergensStore.allergens.length === 0) {
    allergensStore.fetchAllergens()
  }
})

defineExpose({ saving })
</script>
