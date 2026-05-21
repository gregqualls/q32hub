<!--
  RecipeImageGallery — multi-image editor for the recipe form.
  Thumbnail strip with drag-to-reorder, tap-to-set-primary, delete, and an
  upload tile. Used inside RecipeForm (replaces single PhotoUpload).

  The first image in the list is always the primary. Tapping a non-primary
  thumbnail moves it to the front; dragging works the same way through
  SortableJS. Both keep the data model honest.
-->
<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import Sortable from 'sortablejs'
import { CameraIcon, TrashIcon, StarIcon, ArrowsUpDownIcon } from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

const props = defineProps({
  modelValue: { type: Array, required: true }, // [{ id?, path, sort_order, is_primary }]
  uploader: { type: Function, required: true }, // (File) => Promise<{success, url}>
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const fileInput = ref(null)
const stripRef = ref(null)
const uploading = ref(false)
const localPreviews = ref([]) // { tempId, dataUrl } shown during upload
let sortableInstance = null

const resolveUrl = (path) => {
  if (!path) return null
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:') || path.startsWith('/storage/')) return path
  return `/storage/${path}`
}

// The list rendered in the strip = uploaded + currently-uploading temps.
const visible = computed(() => {
  const rows = (props.modelValue || []).map((img, idx) => ({
    key: img.id || `path-${img.path}`,
    src: resolveUrl(img.path),
    isPrimary: idx === 0,
    isLocal: false,
    row: img,
  }))
  for (const t of localPreviews.value) {
    rows.push({ key: t.tempId, src: t.dataUrl, isPrimary: false, isLocal: true })
  }
  return rows
})

const moveToFront = (path) => {
  if (props.disabled) return
  const list = [...props.modelValue]
  const idx = list.findIndex((i) => i.path === path)
  if (idx <= 0) return
  const [picked] = list.splice(idx, 1)
  list.unshift(picked)
  emit('update:modelValue', renumber(list))
}

const removeAt = (path) => {
  if (props.disabled) return
  emit('update:modelValue', renumber((props.modelValue || []).filter((i) => i.path !== path)))
}

const renumber = (list) =>
  list.map((img, idx) => ({
    ...img,
    sort_order: idx,
    is_primary: idx === 0,
  }))

const openPicker = () => {
  if (props.disabled || uploading.value) return
  fileInput.value?.click()
}

const handleSelect = async (event) => {
  const files = Array.from(event.target.files || [])
  if (event.target) event.target.value = ''
  if (!files.length) return

  uploading.value = true
  try {
    for (const file of files) {
      const tempId = `temp-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      const dataUrl = URL.createObjectURL(file)
      localPreviews.value.push({ tempId, dataUrl })

      const result = await props.uploader(file)
      localPreviews.value = localPreviews.value.filter((t) => t.tempId !== tempId)
      URL.revokeObjectURL(dataUrl)

      if (result?.success && result.url) {
        const next = [...(props.modelValue || []), {
          path: result.url,
          sort_order: (props.modelValue || []).length,
          is_primary: (props.modelValue || []).length === 0,
        }]
        emit('update:modelValue', renumber(next))
      }
    }
  } finally {
    uploading.value = false
  }
}

const initSortable = () => {
  if (!stripRef.value || sortableInstance) return
  sortableInstance = Sortable.create(stripRef.value, {
    animation: 200,
    draggable: '[data-sortable="1"]',
    filter: '.no-drag',
    preventOnFilter: false,
    ghostClass: 'opacity-30',
    onEnd: (evt) => {
      if (evt.oldIndex === evt.newIndex || props.disabled) return
      const list = [...props.modelValue]
      const [picked] = list.splice(evt.oldIndex, 1)
      list.splice(evt.newIndex, 0, picked)
      emit('update:modelValue', renumber(list))
    },
  })
}

watch(visible, async () => {
  await nextTick()
  initSortable()
}, { immediate: true })

onBeforeUnmount(() => {
  sortableInstance?.destroy()
  sortableInstance = null
  for (const t of localPreviews.value) {
    URL.revokeObjectURL(t.dataUrl)
  }
})
</script>

<template>
  <div class="space-y-2">
    <label class="block text-sm font-semibold text-ink-primary">Photos</label>
    <p class="text-xs text-ink-secondary">
      First image is the primary (shown on cards). Drag to reorder, or tap the star to make any image primary.
    </p>

    <div ref="stripRef" class="flex flex-wrap gap-2">
      <div
        v-for="item in visible"
        :key="item.key"
        :data-sortable="item.isLocal || disabled ? 0 : 1"
        :class="[
          'relative w-24 h-24 rounded-lg overflow-hidden border-2 bg-surface-sunken',
          item.isPrimary ? 'border-accent-lavender-bold' : 'border-border-subtle',
          item.isLocal ? 'opacity-60' : '',
        ]"
      >
        <img
          v-if="item.src"
          :src="item.src"
          alt=""
          class="w-full h-full object-cover"
          draggable="false"
        />
        <span
          v-if="!item.isLocal && !disabled"
          class="absolute top-1 left-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-semibold pointer-events-none"
          :class="item.isPrimary
            ? 'bg-accent-lavender-bold text-white'
            : 'bg-black/40 text-white'"
        >
          <StarIconSolid v-if="item.isPrimary" class="w-2.5 h-2.5" />
          {{ item.isPrimary ? 'Primary' : 'Drag' }}
        </span>
        <div v-if="!item.isLocal && !disabled" class="absolute bottom-1 right-1 flex gap-1 no-drag">
          <button
            v-if="!item.isPrimary"
            type="button"
            class="p-1 rounded-full bg-surface-raised text-ink-primary shadow-sm hover:bg-accent-lavender-soft transition-colors"
            :aria-label="`Make primary`"
            title="Make primary"
            @click.stop="moveToFront(item.row.path)"
          >
            <StarIcon class="w-3.5 h-3.5" />
          </button>
          <button
            type="button"
            class="p-1 rounded-full bg-surface-raised text-status-failed shadow-sm hover:bg-status-failed/10 transition-colors"
            :aria-label="`Remove photo`"
            title="Remove"
            @click.stop="removeAt(item.row.path)"
          >
            <TrashIcon class="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      <button
        v-if="!disabled"
        type="button"
        class="w-24 h-24 flex flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-border-subtle text-ink-tertiary hover:border-accent-lavender-bold hover:text-accent-lavender-bold transition-colors"
        :disabled="uploading"
        @click="openPicker"
      >
        <CameraIcon class="w-5 h-5" />
        <span class="text-[11px] font-medium">{{ uploading ? 'Uploading…' : 'Add photo' }}</span>
      </button>
    </div>

    <p v-if="visible.length > 1" class="flex items-center gap-1 text-[11px] text-ink-tertiary">
      <ArrowsUpDownIcon class="w-3 h-3" />
      Drag thumbnails to reorder.
    </p>

    <input
      ref="fileInput"
      type="file"
      accept="image/jpeg,image/png,image/webp,image/heic"
      multiple
      class="hidden"
      @change="handleSelect"
    />
  </div>
</template>
