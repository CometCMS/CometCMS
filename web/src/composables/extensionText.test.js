import { describe, expect, it } from 'vitest'
import { conditionsHold, extensionText, optionText, variationText } from './extensionText.js'

describe('extension editor texts', () => {
  const def = { label: 'Tone', options: ['info', 'warning'], option_labels: { info: 'Information' }, l10n: { de: { label: 'Ton', option_labels: { warning: 'Warnung' } } } }

  it('uses the admin language, then its base language, then the manifest', () => {
    expect(extensionText(def, 'label', 'de')).toBe('Ton')
    expect(extensionText(def, 'label', 'de-at')).toBe('Ton')
    expect(extensionText(def, 'label', 'fr')).toBe('Tone')
    expect(extensionText(def, 'help', 'de')).toBeUndefined()
  })

  it('labels options and variations, falling back to the stored value', () => {
    expect(optionText(def, 'warning', 'de')).toBe('Warnung')
    expect(optionText(def, 'info', 'de')).toBe('Information')
    expect(optionText(def, 'info', 'en')).toBe('Information')
    expect(optionText(def, 'other', 'en')).toBe('other')
    const kind = { l10n: { de: { variations: { loud: { title: 'Laut' } } } } }
    expect(variationText(kind, { name: 'loud', title: 'Loud' }, 'title', 'de')).toBe('Laut')
    expect(variationText(kind, { name: 'loud', title: 'Loud' }, 'title', 'en')).toBe('Loud')
  })
})

describe('visible_when conditions', () => {
  it('compare enums and numbers exactly and other values by whether they are set', () => {
    expect(conditionsHold({ visible_when: { tone: ['warning', 'info'] } }, { tone: 'info' })).toBe(true)
    expect(conditionsHold({ visible_when: { tone: ['warning'] } }, { tone: 'info' })).toBe(false)
    expect(conditionsHold({ visible_when: { show: [true] } }, { show: true })).toBe(true)
    expect(conditionsHold({ visible_when: { photo: [false] } }, { photo: '' })).toBe(true)
    expect(conditionsHold({ visible_when: { link: [true] } }, { link: { type: 'none' } })).toBe(false)
    expect(conditionsHold({ visible_when: { link: [true] } }, { link: { type: 'url', url: '/x' } })).toBe(true)
    expect(conditionsHold({ visible_when: { items: [true] } }, { items: [] })).toBe(false)
    expect(conditionsHold({ visible_when: { size: [2] } }, { size: 2 })).toBe(true)
    expect(conditionsHold({}, {})).toBe(true)
  })

  it('reads translated values through the resolver', () => {
    expect(conditionsHold({ visible_when: { title: [true] } }, { title: { en: '', de: 'Hallo' } }, (value) => value.de)).toBe(true)
  })
})
