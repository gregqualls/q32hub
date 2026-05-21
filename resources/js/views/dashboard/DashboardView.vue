<template>
  <div class="p-4 md:p-6 max-w-6xl">
    <!-- Allergy profile review prompt (food module only, hidden when reviewed) -->
    <AllergyProfileReviewBanner class="mb-4" />

    <!-- Edit mode toolbar -->
    <DashboardToolbar
      v-if="dashboardStore.editMode"
      :saving="dashboardStore.saving"
      @add="showPicker = true"
      @cancel="dashboardStore.exitEditMode()"
      @save="saveDashboard"
    />

    <!-- Edit button (shown when not editing) -->
    <div v-else class="flex justify-end mb-2">
      <KinButton
        variant="ghost"
        size="sm"
        icon-only
        aria-label="Edit Dashboard"
        title="Edit Dashboard"
        @click="dashboardStore.enterEditMode()"
      >
        <PencilSquareIcon class="w-5 h-5" />
      </KinButton>
    </div>

    <!-- Loading skeleton -->
    <div v-if="dashboardStore.loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 items-start">
      <KinSkeleton v-for="n in 6" :key="n" shape="card" height="128px" />
    </div>

    <!-- Widget grid -->
    <div v-else ref="gridRef" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
      <DashboardWidget
        v-for="(widget, index) in dashboardStore.widgets"
        :key="widget.id"
        :data-id="widget.id"
        :config="widget"
        :edit-mode="dashboardStore.editMode"
        :index="index"
        @remove="dashboardStore.removeWidget(widget.id)"
        @resize="(size) => dashboardStore.resizeWidget(widget.id, size)"
      />
    </div>

    <!-- Empty state (no widgets) -->
    <KinEmptyState
      v-if="!dashboardStore.loading && dashboardStore.widgets.length === 0"
      :icon="Squares2X2Icon"
      title="No widgets yet"
      description="Add widgets to customize your dashboard, or ask the Assistant to build one for you."
      accent-color="lavender"
      size="md"
    >
      <template #cta>
        <KinButton
          variant="primary"
          @click="dashboardStore.enterEditMode(); showPicker = true"
        >
          <template #leading>
            <PlusIcon class="w-4 h-4" />
          </template>
          Add Widget
        </KinButton>
      </template>
    </KinEmptyState>

    <!-- Widget picker modal -->
    <WidgetPickerModal
      :show="showPicker"
      @close="showPicker = false"
      @add="onAddWidget"
    />
  </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import Sortable from 'sortablejs'
import { useDashboardStore } from '@/stores/dashboard'
import { useFeaturedEventsStore } from '@/stores/featuredEvents'
import DashboardWidget from '@/components/dashboard/DashboardWidget.vue'
import DashboardToolbar from '@/components/dashboard/DashboardToolbar.vue'
import WidgetPickerModal from '@/components/dashboard/WidgetPickerModal.vue'
import KinButton from '@/components/design-system/KinButton.vue'
import KinSkeleton from '@/components/design-system/KinSkeleton.vue'
import KinEmptyState from '@/components/design-system/KinEmptyState.vue'
import AllergyProfileReviewBanner from '@/components/allergens/AllergyProfileReviewBanner.vue'
import { useNotification } from '@/composables/useNotification'
import { PencilSquareIcon, PlusIcon, Squares2X2Icon } from '@heroicons/vue/24/outline'

const dashboardStore = useDashboardStore()
const featuredEventsStore = useFeaturedEventsStore()
const showPicker = ref(false)
const gridRef = ref(null)
let sortableInstance = null

async function onAddWidget(widgetConfig) {
  dashboardStore.addWidget(widgetConfig)
  // Scroll to the newly added widget after Vue renders it
  await nextTick()
  if (gridRef.value) {
    const lastChild = gridRef.value.lastElementChild
    if (lastChild) {
      lastChild.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  }
}

const { success: notifySuccess, error: notifyError } = useNotification()

async function saveDashboard() {
  try {
    await dashboardStore.saveConfig()
    notifySuccess('Dashboard saved')
  } catch {
    notifyError('Failed to save dashboard. Please try again.')
  }
}

function initSortable() {
  if (!gridRef.value) return
  sortableInstance = Sortable.create(gridRef.value, {
    animation: 250,
    handle: '.drag-handle',
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    dragClass: 'sortable-drag',
    forceFallback: true,
    fallbackClass: 'sortable-fallback',
    fallbackOnBody: true,
    onEnd(evt) {
      if (evt.oldIndex !== evt.newIndex) {
        dashboardStore.moveWidget(evt.oldIndex, evt.newIndex)
      }
    },
  })
}

function destroySortable() {
  if (sortableInstance) {
    sortableInstance.destroy()
    sortableInstance = null
  }
}

// Toggle sortable when edit mode changes
watch(() => dashboardStore.editMode, async (editing) => {
  destroySortable()
  if (editing) {
    await nextTick()
    initSortable()
  }
})

onBeforeUnmount(() => {
  destroySortable()
})

onMounted(async () => {
  await dashboardStore.fetchConfig()
  featuredEventsStore.fetchEvents()
  featuredEventsStore.fetchCountdown()
})
</script>

<style scoped>
.sortable-ghost {
  opacity: 0.2;
}

.sortable-chosen {
  opacity: 0.8;
}

.sortable-fallback {
  opacity: 0.9;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  border-radius: 12px;
}
</style>
