<!--
  FamilyAllergenSettings — admin view of family-custom allergens.
  Parents can add, rename, delete; Big 9 are shown read-only.
-->
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAllergensStore } from '@/stores/allergens'
import { useAuthStore } from '@/stores/auth'
import KinChip from '@/components/design-system/KinChip.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import KinInput from '@/components/design-system/KinInput.vue'
import { TrashIcon, PencilSquareIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const allergens = useAllergensStore()
const auth = useAuthStore()

const newName = ref('')
const adding = ref(false)
const addError = ref('')
const editingId = ref(null)
const editName = ref('')
const editError = ref('')
const saving = ref(false)

const isParent = computed(() => auth.isParent)

onMounted(async () => {
  if (allergens.allergens.length === 0) await allergens.fetchAllergens()
})

const addCustom = async () => {
  if (!newName.value.trim()) return
  adding.value = true
  addError.value = ''
  const result = await allergens.createCustom(newName.value.trim())
  adding.value = false
  if (result.success) {
    newName.value = ''
  } else {
    addError.value = result.error
  }
}

const startEdit = (allergen) => {
  editingId.value = allergen.id
  editName.value = allergen.name
  editError.value = ''
}

const cancelEdit = () => {
  editingId.value = null
  editName.value = ''
  editError.value = ''
}

const saveEdit = async () => {
  if (!editName.value.trim()) return
  saving.value = true
  editError.value = ''
  const result = await allergens.renameCustom(editingId.value, editName.value.trim())
  saving.value = false
  if (result.success) {
    cancelEdit()
  } else {
    editError.value = result.error
  }
}

const remove = async (allergen) => {
  if (!confirm(`Remove "${allergen.name}" from your family's allergen list? This won't change anyone's profile.`)) {
    return
  }
  await allergens.deleteCustom(allergen.id)
}
</script>

<template>
  <div class="space-y-5">
    <div>
      <p class="text-xs font-medium text-ink-secondary mb-2">Big 9 (always available)</p>
      <div class="flex flex-wrap gap-2">
        <KinChip v-for="a in allergens.bigNine" :key="a.id" variant="category" color="neutral">
          {{ a.name }}
        </KinChip>
      </div>
    </div>

    <div>
      <p class="text-xs font-medium text-ink-secondary mb-2">Your family's allergens</p>
      <div v-if="allergens.customs.length === 0" class="text-xs text-ink-tertiary italic">
        No custom allergens yet.
      </div>
      <ul class="space-y-2">
        <li
          v-for="a in allergens.customs"
          :key="a.id"
          class="flex items-center gap-2 p-2 rounded-md bg-surface-sunken"
        >
          <template v-if="editingId === a.id">
            <KinInput v-model="editName" class="flex-1" @keyup.enter="saveEdit" />
            <button
              class="p-1.5 text-status-success hover:bg-status-success/10 rounded"
              aria-label="Save"
              :disabled="saving"
              @click="saveEdit"
            >
              <CheckIcon class="w-4 h-4" />
            </button>
            <button
              class="p-1.5 text-ink-tertiary hover:bg-surface-raised rounded"
              aria-label="Cancel"
              @click="cancelEdit"
            >
              <XMarkIcon class="w-4 h-4" />
            </button>
          </template>
          <template v-else>
            <span class="flex-1 text-sm text-ink-primary">{{ a.name }}</span>
            <template v-if="isParent">
              <button
                class="p-1.5 text-ink-tertiary hover:bg-surface-raised rounded"
                aria-label="Rename"
                @click="startEdit(a)"
              >
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button
                class="p-1.5 text-status-error hover:bg-status-error/10 rounded"
                aria-label="Delete"
                @click="remove(a)"
              >
                <TrashIcon class="w-4 h-4" />
              </button>
            </template>
          </template>
        </li>
      </ul>
      <p v-if="editError" class="text-xs text-status-error mt-2" role="alert">{{ editError }}</p>
    </div>

    <div v-if="isParent" class="pt-2 border-t border-border-subtle">
      <p class="text-xs font-medium text-ink-secondary mb-2">Add a custom allergen</p>
      <div class="flex flex-wrap gap-2">
        <KinInput
          v-model="newName"
          placeholder="e.g. Corn, Kiwi, Mustard"
          class="flex-1 min-w-[200px]"
          @keyup.enter="addCustom"
        />
        <KinButton variant="primary" size="sm" :loading="adding" @click="addCustom">
          Add
        </KinButton>
      </div>
      <p v-if="addError" class="text-xs text-status-error mt-2" role="alert">{{ addError }}</p>
    </div>
  </div>
</template>
