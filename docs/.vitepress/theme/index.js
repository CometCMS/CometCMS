import DefaultTheme from 'vitepress/theme'
import { h } from 'vue'
import './style.css'
import ScreenshotGallery from './components/ScreenshotGallery.vue'
import DownloadSection from './components/DownloadSection.vue'

export default {
  extends: DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'home-features-after': () => [
        h(ScreenshotGallery),
        h(DownloadSection),
      ],
    })
  },
}
