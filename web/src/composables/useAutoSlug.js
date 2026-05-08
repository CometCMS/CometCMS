import { ref, watch } from 'vue'
import { toSlug } from './fieldBuilderUtils.js'

/**
 * Keeps a slug/key field in sync with a label as the user types.
 * Auto-updating stops once the user manually edits the slug.
 *
 * @param {import('vue').Ref<string>} labelRef - reactive source label
 * @param {Function} [normalize]               - normalization fn (default: toSlug)
 * @returns {{ slug, touched, onSlugInput, reset }}
 */
export function useAutoSlug(labelRef, normalize = toSlug) {
  const slug    = ref(normalize(labelRef.value))
  const touched = ref(false)

  watch(labelRef, (val) => {
    if (!touched.value) {
      slug.value = normalize(val)
    }
  })

  /** Call from the slug input's @input event to lock auto-derivation. */
  function onSlugInput() {
    touched.value = true
  }

  /** Unlock and re-derive from the current label value. */
  function reset() {
    touched.value = false
    slug.value    = normalize(labelRef.value)
  }

  return { slug, touched, onSlugInput, reset }
}
