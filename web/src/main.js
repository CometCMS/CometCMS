import './style.css'
import './flag-icons.scss'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router/index.js'

// After an update or a dropped connection, a lazily loaded view can fail to
// load. Reload once to pick up the current bundles instead of leaving a blank
// area; the stored timestamp keeps a persistent failure from looping.
function reloadForStaleBundle(event, href = null) {
  const last = Number(sessionStorage.getItem('comet-bundle-reload') || 0)
  if (Date.now() - last < 10000) return
  event?.preventDefault?.()
  sessionStorage.setItem('comet-bundle-reload', String(Date.now()))
  if (href) window.location.assign(href)
  else window.location.reload()
}
window.addEventListener('vite:preloadError', reloadForStaleBundle)
router.onError((error, to) => {
  if (/dynamically imported module|Importing a module script failed|error loading dynamically/i.test(error?.message || '')) {
    // Land on the page that failed to open, not the one before it.
    reloadForStaleBundle(null, to ? router.resolve(to).href : null)
  }
})

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
