import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { DateTime } from 'luxon'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

export const useMealsStore = defineStore('meals', () => {
  // State
  const currentPlan = ref(null)
  const presets = ref([])
  const isLoading = ref(false)
  const error = ref(null)
  const weekStartDate = ref(null) // luxon DateTime

  // ── Helpers ──

  const getWeekStartDay = () => {
    const authStore = useAuthStore()
    return authStore.family?.settings?.week_start_day || 'monday'
  }

  const computeWeekStart = (dt) => {
    const day = getWeekStartDay()
    if (day === 'sunday') {
      // luxon startOf('week') uses locale; manually find Sunday
      const weekday = dt.weekday // 1=Mon, 7=Sun
      return dt.minus({ days: weekday % 7 }).startOf('day')
    }
    // Monday
    return dt.startOf('week')
  }

  // ── Computed ──

  const weekDates = computed(() => {
    if (!weekStartDate.value) return []
    return Array.from({ length: 7 }, (_, i) =>
      weekStartDate.value.plus({ days: i }).toISODate()
    )
  })

  const entriesByDayAndSlot = computed(() => {
    if (!currentPlan.value?.entries) return {}
    const map = {}
    for (const entry of currentPlan.value.entries) {
      if (!map[entry.date]) {
        map[entry.date] = { breakfast: [], lunch: [], dinner: [], snack: [] }
      }
      const slot = entry.meal_slot
      if (!map[entry.date][slot]) map[entry.date][slot] = []
      map[entry.date][slot].push(entry)
    }
    return map
  })

  const enabledMealSlots = computed(() => {
    const authStore = useAuthStore()
    return authStore.family?.settings?.meal_slots || ['breakfast', 'lunch', 'dinner', 'snack']
  })

  const isCurrentWeek = computed(() => {
    if (!weekStartDate.value) return false
    const thisWeekStart = computeWeekStart(DateTime.now())
    return weekStartDate.value.toISODate() === thisWeekStart.toISODate()
  })

  // ── Week navigation ──

  const fetchCurrentWeekPlan = async () => {
    isLoading.value = true
    error.value = null
    try {
      const response = await api.get('/meal-plans/current')
      const plan = response.data.meal_plan
      // Laravel ResourceCollection wraps entries in { data: [...] } — unwrap it
      if (plan?.entries?.data) plan.entries = plan.entries.data
      currentPlan.value = plan
      weekStartDate.value = DateTime.fromISO(plan.week_start)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load meal plan'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  const fetchWeekPlan = async (weekStartStr) => {
    isLoading.value = true
    error.value = null
    try {
      const response = await api.post('/meal-plans', { week_start: weekStartStr })
      const plan = response.data.meal_plan
      if (plan?.entries?.data) plan.entries = plan.entries.data
      currentPlan.value = plan
      weekStartDate.value = DateTime.fromISO(plan.week_start)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load meal plan'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  const goToPreviousWeek = async () => {
    if (!weekStartDate.value) return
    const newStart = weekStartDate.value.minus({ weeks: 1 })
    return fetchWeekPlan(newStart.toISODate())
  }

  const goToNextWeek = async () => {
    if (!weekStartDate.value) return
    const newStart = weekStartDate.value.plus({ weeks: 1 })
    return fetchWeekPlan(newStart.toISODate())
  }

  const goToCurrentWeek = async () => {
    return fetchCurrentWeekPlan()
  }

  /**
   * Fetch a week plan without overwriting currentPlan state.
   * Used by mobile infinite scroll to load additional weeks.
   */
  const fetchWeekPlanRaw = async (weekStartStr) => {
    try {
      const response = await api.post('/meal-plans', { week_start: weekStartStr })
      const plan = response.data.meal_plan
      if (plan?.entries?.data) plan.entries = plan.entries.data
      return { success: true, plan }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to load meal plan' }
    }
  }

  // ── Presets ──

  const fetchPresets = async () => {
    try {
      const response = await api.get('/meal-plans/presets')
      presets.value = response.data.presets
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to fetch presets' }
    }
  }

  // ── Entry CRUD ──

  const addEntry = async (planId, data) => {
    // Optimistic add with temp id
    const tempEntry = { id: `temp-${Date.now()}`, ...data, display_title: data.custom_title || '...', type: 'custom' }
    if (!currentPlan.value) return { success: false }
    currentPlan.value.entries.push(tempEntry)

    try {
      const response = await api.post(`/meal-plans/${planId}/entries`, data)
      const newEntry = response.data.entry
      const idx = currentPlan.value.entries.findIndex(e => e.id === tempEntry.id)
      if (idx !== -1) currentPlan.value.entries.splice(idx, 1, newEntry)
      return { success: true, data: newEntry }
    } catch (err) {
      // Rollback optimistic add
      currentPlan.value.entries = currentPlan.value.entries.filter(e => e.id !== tempEntry.id)

      // Allergen acknowledgement required — surface so caller can prompt user.
      if (err.response?.status === 409 && err.response?.data?.requires_acknowledgement) {
        return {
          success: false,
          requiresAllergenAck: true,
          hits: err.response.data.hits || [],
          retry: (acknowledged) => addEntry(planId, { ...data, acknowledge_allergens: !!acknowledged }),
        }
      }

      return { success: false, error: err.response?.data?.message || 'Failed to add entry' }
    }
  }

  const updateEntry = async (entryId, data) => {
    const snapshot = currentPlan.value?.entries ? [...currentPlan.value.entries] : []
    const idx = currentPlan.value?.entries?.findIndex(e => e.id === entryId) ?? -1
    if (idx !== -1) {
      currentPlan.value.entries[idx] = { ...currentPlan.value.entries[idx], ...data }
    }

    try {
      const response = await api.put(`/meal-plan-entries/${entryId}`, data)
      if (idx !== -1 && currentPlan.value) {
        currentPlan.value.entries[idx] = response.data.entry
      }
      return { success: true, data: response.data.entry }
    } catch (err) {
      if (currentPlan.value) currentPlan.value.entries = snapshot
      return { success: false, error: err.response?.data?.message || 'Failed to update entry' }
    }
  }

  const removeEntry = async (entryId) => {
    const snapshot = currentPlan.value?.entries ? [...currentPlan.value.entries] : []
    if (currentPlan.value) {
      currentPlan.value.entries = currentPlan.value.entries.filter(e => e.id !== entryId)
    }

    try {
      await api.delete(`/meal-plan-entries/${entryId}`)
      return { success: true }
    } catch (err) {
      if (currentPlan.value) currentPlan.value.entries = snapshot
      return { success: false, error: err.response?.data?.message || 'Failed to remove entry' }
    }
  }

  const moveEntry = async (entryId, date, mealSlot) => {
    const snapshot = currentPlan.value?.entries ? [...currentPlan.value.entries] : []
    const idx = currentPlan.value?.entries?.findIndex(e => e.id === entryId) ?? -1
    if (idx !== -1 && currentPlan.value) {
      currentPlan.value.entries[idx] = { ...currentPlan.value.entries[idx], date, meal_slot: mealSlot }
    }

    try {
      const response = await api.post(`/meal-plan-entries/${entryId}/move`, { date, meal_slot: mealSlot })
      if (idx !== -1 && currentPlan.value) {
        currentPlan.value.entries[idx] = response.data.entry
      }
      return { success: true }
    } catch (err) {
      if (currentPlan.value) currentPlan.value.entries = snapshot
      return { success: false, error: err.response?.data?.message || 'Failed to move entry' }
    }
  }

  const generateShoppingList = async (planId) => {
    try {
      await api.post(`/meal-plans/${planId}/generate-shopping-list`)
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to generate shopping list' }
    }
  }

  const previewShoppingList = async (planId, { days = 7, start = null, shoppingListId = null } = {}) => {
    try {
      const params = { days }
      if (start) params.start = start
      if (shoppingListId) params.shopping_list_id = shoppingListId
      const response = await api.get(`/meal-plans/${planId}/shopping-preview`, { params })
      return {
        success: true,
        entries: response.data.entries ?? [],
        range: response.data.range ?? null,
      }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to preview shopping list' }
    }
  }

  const addSelectionsToShoppingList = async (planId, selections, shoppingListId = null) => {
    try {
      const body = { selections }
      if (shoppingListId) body.shopping_list_id = shoppingListId
      const response = await api.post(`/meal-plans/${planId}/add-to-shopping-list`, body)
      return {
        success: true,
        added_count: response.data.added_count ?? 0,
        shopping_list: response.data.shopping_list,
      }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to add to shopping list' }
    }
  }

  return {
    // State
    currentPlan,
    presets,
    isLoading,
    error,
    weekStartDate,
    // Computed
    weekDates,
    entriesByDayAndSlot,
    enabledMealSlots,
    isCurrentWeek,
    // Actions
    fetchCurrentWeekPlan,
    fetchWeekPlan,
    fetchWeekPlanRaw,
    goToPreviousWeek,
    goToNextWeek,
    goToCurrentWeek,
    fetchPresets,
    addEntry,
    updateEntry,
    removeEntry,
    moveEntry,
    generateShoppingList,
    previewShoppingList,
    addSelectionsToShoppingList,
  }
})
