import axios from 'axios'

const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// Read the XSRF-TOKEN cookie Sanctum keeps in sync with the session. Unlike the
// <meta> tag (frozen at page load), this reflects the current session token, so
// long-lived mobile tabs don't send a stale token after the session rotates.
function xsrfTokenFromCookie() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

// Restore auth token from localStorage on init
const savedToken = localStorage.getItem('auth_token')
if (savedToken) {
  api.defaults.headers.common['Authorization'] = `Bearer ${savedToken}`
}

// Request interceptor to add CSRF token and fix Content-Type for file uploads
api.interceptors.request.use((config) => {
  const token = xsrfTokenFromCookie()
  if (token) {
    config.headers['X-XSRF-TOKEN'] = token
  }

  // Let axios set the correct Content-Type (with boundary) for FormData
  if (config.data instanceof FormData) {
    delete config.headers['Content-Type']
  }

  return config
})

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    // 419 = CSRF token mismatch (session rotated under a long-lived tab, common
    // on mobile). Refresh the XSRF-TOKEN cookie and replay the request once.
    if (error.response?.status === 419 && !error.config?._csrfRetried) {
      try {
        await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
        error.config._csrfRetried = true
        return api.request(error.config)
      } catch {
        // Fall through to normal rejection if the refresh itself fails.
      }
    }

    if (error.response?.status === 401) {
      // Clear stored token on 401
      localStorage.removeItem('auth_token')
      delete api.defaults.headers.common['Authorization']

      // Only redirect if not already on login page
      if (!window.location.pathname.startsWith('/login')) {
        window.location.href = '/login'
      }
    }

    // 402 = paywalled. Server-side gate (#264). Re-fetch the user payload so
    // the SPA's billing state matches what the server believes; useBillingGate
    // then mounts SubscriptionPaywall on the next tick.
    if (error.response?.status === 402) {
      const skip = error.config?.url?.includes('/user') || error.config?.url?.includes('/billing')
      if (!skip) {
        import('@/stores/auth')
          .then(({ useAuthStore }) => useAuthStore().fetchUser())
          .catch(() => {})
      }
    }

    // Return the error so it can be handled in the store/component
    return Promise.reject(error)
  }
)

export default api
