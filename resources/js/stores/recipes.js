import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useRecipesStore = defineStore('recipes', () => {
  // State
  const recipes = ref([])
  const currentRecipe = ref(null)
  const tags = ref([])
  const isLoading = ref(false)
  const error = ref(null)
  const pagination = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })

  // Filters
  const searchQuery = ref('')
  const sortBy = ref('recent')
  const selectedTagIds = ref([])
  const showFavoritesOnly = ref(false)
  const safeForMemberIds = ref([])

  // Computed
  const hasMore = computed(() => pagination.value.current_page < pagination.value.last_page)

  // ── Tag actions ──

  const fetchTags = async () => {
    try {
      const response = await api.get('/tags', { params: { scope: 'food' } })
      tags.value = response.data.tags
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to fetch tags' }
    }
  }

  const toggleTagFilter = (tagId) => {
    const idx = selectedTagIds.value.indexOf(tagId)
    if (idx === -1) {
      selectedTagIds.value.push(tagId)
    } else {
      selectedTagIds.value.splice(idx, 1)
    }
  }

  const clearTagFilter = () => {
    selectedTagIds.value = []
  }

  // ── Recipe CRUD ──

  const fetchRecipes = async (extraFilters = {}) => {
    isLoading.value = true
    error.value = null

    try {
      const params = { ...extraFilters }
      if (searchQuery.value) params.search = searchQuery.value
      if (sortBy.value && sortBy.value !== 'recent') params.sort = sortBy.value
      if (selectedTagIds.value.length === 1) params.tag = selectedTagIds.value[0]
      if (showFavoritesOnly.value) params.favorite = 1
      if (safeForMemberIds.value.length > 0) params['safe_for_members'] = safeForMemberIds.value

      const response = await api.get('/recipes', { params })
      recipes.value = response.data.data
      if (response.data.meta) {
        pagination.value = {
          current_page: response.data.meta.current_page,
          last_page: response.data.meta.last_page,
          per_page: response.data.meta.per_page,
          total: response.data.meta.total,
        }
      }
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch recipes'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  const fetchRecipe = async (id) => {
    isLoading.value = true
    error.value = null

    try {
      const response = await api.get(`/recipes/${id}`)
      currentRecipe.value = response.data.recipe
      return { success: true, recipe: response.data.recipe }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch recipe'
      return { success: false, error: error.value }
    } finally {
      isLoading.value = false
    }
  }

  const createRecipe = async (data) => {
    try {
      const response = await api.post('/recipes', data)
      recipes.value.unshift(response.data.recipe)
      return { success: true, recipe: response.data.recipe }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to create recipe' }
    }
  }

  const updateRecipe = async (id, data) => {
    try {
      const response = await api.put(`/recipes/${id}`, data)
      const updated = response.data.recipe
      const index = recipes.value.findIndex((r) => r.id === id)
      if (index !== -1) {
        recipes.value[index] = updated
      }
      if (currentRecipe.value?.id === id) {
        currentRecipe.value = updated
      }
      return { success: true, recipe: updated }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to update recipe' }
    }
  }

  const deleteRecipe = async (id) => {
    try {
      await api.delete(`/recipes/${id}`)
      recipes.value = recipes.value.filter((r) => r.id !== id)
      if (currentRecipe.value?.id === id) {
        currentRecipe.value = null
      }
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to delete recipe' }
    }
  }

  const restoreRecipe = async (id) => {
    try {
      const response = await api.post(`/recipes/${id}/restore`)
      recipes.value.unshift(response.data.recipe)
      return { success: true, recipe: response.data.recipe }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to restore recipe' }
    }
  }

  const toggleFavorite = async (id) => {
    // Optimistic update
    const index = recipes.value.findIndex((r) => r.id === id)
    if (index !== -1) {
      recipes.value[index] = { ...recipes.value[index], is_favorite: !recipes.value[index].is_favorite }
    }
    if (currentRecipe.value?.id === id) {
      currentRecipe.value = { ...currentRecipe.value, is_favorite: !currentRecipe.value.is_favorite }
    }

    try {
      const response = await api.post(`/recipes/${id}/favorite`)
      const updated = response.data.recipe
      if (index !== -1) {
        recipes.value[index] = updated
      }
      if (currentRecipe.value?.id === id) {
        currentRecipe.value = updated
      }
      return { success: true, recipe: updated }
    } catch (err) {
      // Revert optimistic update
      if (index !== -1) {
        recipes.value[index] = { ...recipes.value[index], is_favorite: !recipes.value[index].is_favorite }
      }
      if (currentRecipe.value?.id === id) {
        currentRecipe.value = { ...currentRecipe.value, is_favorite: !currentRecipe.value.is_favorite }
      }
      return { success: false, error: err.response?.data?.message || 'Failed to toggle favorite' }
    }
  }

  // ── Cook logs ──

  const fetchCookLogs = async (recipeId) => {
    try {
      const response = await api.get(`/recipes/${recipeId}/cook-logs`)
      return { success: true, cookLogs: response.data.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to fetch cook logs' }
    }
  }

  const addCookLog = async (recipeId, data) => {
    try {
      const response = await api.post(`/recipes/${recipeId}/cook-logs`, data)
      return { success: true, cookLog: response.data.cook_log }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to add cook log' }
    }
  }

  // ── Ratings ──

  const rateRecipe = async (recipeId, score) => {
    // Optimistic update on currentRecipe
    const previousUserRating = currentRecipe.value?.user_rating
    if (currentRecipe.value?.id === recipeId) {
      currentRecipe.value = { ...currentRecipe.value, user_rating: score }
    }

    try {
      const response = await api.post(`/recipes/${recipeId}/rate`, { score })
      return { success: true, rating: response.data.rating }
    } catch (err) {
      // Revert
      if (currentRecipe.value?.id === recipeId) {
        currentRecipe.value = { ...currentRecipe.value, user_rating: previousUserRating }
      }
      return { success: false, error: err.response?.data?.message || 'Failed to rate recipe' }
    }
  }

  const fetchRatings = async (recipeId) => {
    try {
      const response = await api.get(`/recipes/${recipeId}/ratings`)
      return { success: true, ratings: response.data.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to fetch ratings' }
    }
  }

  // ── Import ──

  const importFromUrl = async (url) => {
    try {
      const response = await api.post('/recipes/import/url?preview=1', { url })
      return { success: true, preview: response.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || "Couldn't extract a recipe from this URL" }
    }
  }

  const importFromPhoto = async (file) => {
    try {
      const formData = new FormData()
      formData.append('photo', file)
      const response = await api.post('/recipes/import/photo?preview=1', formData)
      return { success: true, preview: response.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || "Couldn't extract a recipe from this photo" }
    }
  }

  const uploadImage = async (file) => {
    try {
      const formData = new FormData()
      formData.append('image', file)
      const response = await api.post('/recipes/upload-image', formData)
      return { success: true, imagePath: response.data.image_path }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to upload image' }
    }
  }

  // ── Helpers ──

  const clearCurrentRecipe = () => {
    currentRecipe.value = null
  }

  const resetFilters = () => {
    searchQuery.value = ''
    sortBy.value = 'recent'
    selectedTagIds.value = []
    showFavoritesOnly.value = false
  }

  return {
    // State
    recipes,
    currentRecipe,
    tags,
    isLoading,
    error,
    pagination,

    // Filters
    searchQuery,
    sortBy,
    selectedTagIds,
    showFavoritesOnly,
    safeForMemberIds,

    // Computed
    hasMore,

    // Tag actions
    fetchTags,
    toggleTagFilter,
    clearTagFilter,

    // Recipe CRUD
    fetchRecipes,
    fetchRecipe,
    createRecipe,
    updateRecipe,
    deleteRecipe,
    restoreRecipe,
    toggleFavorite,

    // Cook logs
    fetchCookLogs,
    addCookLog,

    // Ratings
    rateRecipe,
    fetchRatings,

    // Import
    importFromUrl,
    importFromPhoto,
    uploadImage,

    // Helpers
    clearCurrentRecipe,
    resetFilters,
  }
})
