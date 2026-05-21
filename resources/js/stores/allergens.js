import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useAllergensStore = defineStore('allergens', () => {
  const allergens = ref([])
  const isLoading = ref(false)
  const profiles = ref({})

  const bigNine = computed(() => allergens.value.filter((a) => a.is_big_nine))
  const customs = computed(() => allergens.value.filter((a) => !a.is_big_nine))

  const fetchAllergens = async () => {
    isLoading.value = true
    try {
      const response = await api.get('/allergens')
      allergens.value = response.data.allergens
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to load allergens' }
    } finally {
      isLoading.value = false
    }
  }

  const createCustom = async (name) => {
    try {
      const response = await api.post('/allergens', { name })
      allergens.value.push(response.data.allergen)
      return { success: true, allergen: response.data.allergen }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to add allergen' }
    }
  }

  const renameCustom = async (id, name) => {
    try {
      const response = await api.patch(`/allergens/${id}`, { name })
      const idx = allergens.value.findIndex((a) => a.id === id)
      if (idx !== -1) allergens.value[idx] = response.data.allergen
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to rename allergen' }
    }
  }

  const deleteCustom = async (id) => {
    try {
      await api.delete(`/allergens/${id}`)
      allergens.value = allergens.value.filter((a) => a.id !== id)
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to delete allergen' }
    }
  }

  const fetchMemberProfile = async (userId) => {
    try {
      const response = await api.get(`/users/${userId}/allergens`)
      profiles.value[userId] = response.data
      return { success: true, profile: response.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to load profile' }
    }
  }

  const saveMemberProfile = async (userId, allergenIds) => {
    try {
      const response = await api.put(`/users/${userId}/allergens`, { allergen_ids: allergenIds })
      profiles.value[userId] = response.data
      return { success: true, profile: response.data }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to save profile' }
    }
  }

  const markReviewed = async (userId) => {
    try {
      const response = await api.post(`/users/${userId}/allergens/mark-reviewed`)
      if (profiles.value[userId]) {
        profiles.value[userId].reviewed_at = response.data.reviewed_at
      } else {
        profiles.value[userId] = { allergen_ids: [], reviewed_at: response.data.reviewed_at }
      }
      return { success: true }
    } catch (err) {
      return { success: false, error: err.response?.data?.message || 'Failed to mark reviewed' }
    }
  }

  const profileFor = (userId) => profiles.value[userId] || null
  const isReviewed = (userId) => Boolean(profiles.value[userId]?.reviewed_at)

  return {
    allergens,
    isLoading,
    profiles,
    bigNine,
    customs,
    fetchAllergens,
    createCustom,
    renameCustom,
    deleteCustom,
    fetchMemberProfile,
    saveMemberProfile,
    markReviewed,
    profileFor,
    isReviewed,
  }
})
