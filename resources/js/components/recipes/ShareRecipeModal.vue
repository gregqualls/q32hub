<!--
  ShareRecipeModal — parent-only sheet for publishing a recipe to a public URL.
  Generates an unguessable token, shows the share URL with a copy button, and
  exposes the attribution toggle (anonymous by default, opt-in to show family
  name). Revoking clears the token; the old URL hard-404s after that.
-->
<script setup>
import { ref, computed, watch } from 'vue'
import KinModalSheet from '@/components/design-system/KinModalSheet.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import KinSwitch from '@/components/design-system/KinSwitch.vue'
import { ShareIcon, ClipboardDocumentIcon, CheckIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'
import { useNotification } from '@/composables/useNotification'

const props = defineProps({
  show: { type: Boolean, required: true },
  recipe: { type: Object, required: true },
})

const emit = defineEmits(['close', 'updated'])

const { success: notifySuccess, error: notifyError } = useNotification()

const working = ref(false)
const copied = ref(false)
const local = ref({
  is_shared: false,
  url: null,
  visible_attribution: false,
})

watch(() => props.recipe?.share, (s) => {
  if (s) {
    local.value = { ...s }
    copied.value = false
  }
}, { immediate: true })

const isShared = computed(() => local.value.is_shared)

const publish = async () => {
  working.value = true
  try {
    const response = await api.post(`/recipes/${props.recipe.id}/share`)
    local.value = response.data.share
    emit('updated', response.data.share)
    notifySuccess('Recipe shared. Copy the link to send it anywhere.')
  } catch (err) {
    notifyError(err.response?.data?.message || 'Failed to publish recipe')
  } finally {
    working.value = false
  }
}

const setAttribution = async (visible) => {
  working.value = true
  try {
    const response = await api.patch(`/recipes/${props.recipe.id}/share`, {
      visible_attribution: visible,
    })
    local.value = response.data.share
    emit('updated', response.data.share)
  } catch (err) {
    notifyError(err.response?.data?.message || 'Failed to update share settings')
  } finally {
    working.value = false
  }
}

const revoke = async () => {
  if (!confirm('Revoke this share? The public link will stop working immediately and re-sharing will create a brand new link.')) return
  working.value = true
  try {
    const response = await api.delete(`/recipes/${props.recipe.id}/share`)
    local.value = response.data.share
    emit('updated', response.data.share)
    notifySuccess('Recipe unshared. The old link no longer works.')
  } catch (err) {
    notifyError(err.response?.data?.message || 'Failed to revoke share')
  } finally {
    working.value = false
  }
}

const copyLink = async () => {
  if (!local.value.url) return
  try {
    await navigator.clipboard.writeText(local.value.url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    notifyError('Copy failed. Long-press the link to copy manually.')
  }
}
</script>

<template>
  <KinModalSheet :model-value="show" title="Share this recipe" @update:model-value="(v) => !v && emit('close')">
    <div class="space-y-5">
      <!-- Header copy -->
      <div class="flex items-start gap-3">
        <div class="shrink-0 w-10 h-10 rounded-full bg-accent-lavender-soft flex items-center justify-center">
          <ShareIcon class="w-5 h-5 text-accent-lavender-bold" />
        </div>
        <div class="text-sm text-ink-secondary">
          Publish a public link anyone can open — no account needed. Allergen badges and the source link travel with it.
        </div>
      </div>

      <!-- Not yet shared: single CTA -->
      <div v-if="!isShared" class="space-y-3">
        <KinButton variant="primary" class="w-full" :loading="working" @click="publish">
          Publish public link
        </KinButton>
      </div>

      <!-- Shared: URL + copy + attribution + revoke -->
      <div v-else class="space-y-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-tertiary mb-1.5">Public URL</p>
          <div class="flex items-stretch gap-2">
            <input
              :value="local.url"
              readonly
              class="flex-1 min-w-0 px-3 py-2 text-sm font-mono rounded-lg bg-surface-sunken border border-border-subtle text-ink-secondary truncate"
              @focus="$event.target.select()"
            />
            <button
              type="button"
              class="px-3 py-2 rounded-lg bg-accent-lavender-bold text-white text-sm font-semibold hover:bg-accent-lavender-bold/90 transition-colors inline-flex items-center gap-1.5"
              :aria-label="copied ? 'Copied' : 'Copy link'"
              @click="copyLink"
            >
              <CheckIcon v-if="copied" class="w-4 h-4" />
              <ClipboardDocumentIcon v-else class="w-4 h-4" />
              {{ copied ? 'Copied' : 'Copy' }}
            </button>
          </div>
        </div>

        <div class="flex items-start justify-between gap-3 p-3 rounded-lg bg-surface-sunken">
          <div class="flex-1">
            <p class="text-sm font-medium text-ink-primary">Show family name</p>
            <p class="text-xs text-ink-secondary mt-0.5">
              Off by default. When on, the public page reads "Shared by {{ recipe.family_name || 'your family' }}" instead of "Shared via Kinhold."
            </p>
          </div>
          <KinSwitch
            :model-value="local.visible_attribution"
            :disabled="working"
            @update:model-value="setAttribution"
          />
        </div>

        <div class="pt-2 border-t border-border-subtle">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 text-sm text-status-failed hover:underline"
            :disabled="working"
            @click="revoke"
          >
            <ExclamationCircleIcon class="w-4 h-4" />
            Revoke this share
          </button>
        </div>
      </div>
    </div>
  </KinModalSheet>
</template>
