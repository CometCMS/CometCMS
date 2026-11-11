import { describe, expect, it, vi } from 'vitest'
import mount, { detectOS } from '../../../examples/extensions/comet-essentials/blocks/os-download/view.js'

describe('OS Download detection', () => {
  it.each([
    [{ userAgentData: { platform: 'Windows' }, platform: 'Linux' }, 'windows'],
    [{ platform: 'Win32' }, 'windows'],
    [{ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' }, 'windows'],
    [{ platform: 'MacIntel' }, 'macos'],
    [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' }, 'macos'],
    [{ platform: 'Linux x86_64' }, 'linux'],
    [{ platform: 'Linux armv8l', userAgent: 'Mozilla/5.0 (Linux; Android 14)' }, 'android'],
    [{ userAgentData: { platform: 'Android' } }, 'android'],
    [{ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' }, 'ios'],
    [{ platform: 'MacIntel', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', maxTouchPoints: 5 }, 'ios'],
    [{ platform: 'Linux x86_64', userAgent: 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0)' }, 'chromeos'],
    [{ userAgentData: { platform: 'Chrome OS' } }, 'chromeos'],
    [{}, 'other'],
    [{ platform: 'Unknown', userAgent: 'Unknown browser' }, 'other'],
  ])('classifies %j as %s', (device, os) => {
    expect(detectOS(device)).toBe(os)
  })
})

// Only the DOM surface used by mount; link content must remain untouched.
function download(os) {
  const attributes = new Set()
  const recommendation = { hidden: true }
  return {
    dataset: { os }, hidden: false, recommendation, attributes,
    querySelector: () => recommendation,
    toggleAttribute(name, enabled) { if (enabled) attributes.add(name); else attributes.delete(name) },
    removeAttribute(name) { attributes.delete(name) },
  }
}

describe('OS Download selection', () => {
  function setup(platform = 'Win32') {
    vi.stubGlobal('navigator', { platform })
    const items = [download('windows'), download('linux'), download('windows')]
    return { items, element: { querySelectorAll: () => items } }
  }

  it('highlights every matching package and keeps alternatives available', () => {
    const { items, element } = setup()
    try {
      mount(element, { display: 'all' })
      expect(items.map((item) => item.hidden)).toEqual([false, false, false])
      expect(items.map((item) => item.attributes.has('data-matched'))).toEqual([true, false, true])
      expect(items.map((item) => item.recommendation.hidden)).toEqual([false, true, false])
    } finally { vi.unstubAllGlobals() }
  })

  it('hides only nonmatching downloads and restores them on unmount', () => {
    const { items, element } = setup()
    try {
      const cleanup = mount(element, { display: 'matched' })
      expect(items.map((item) => item.hidden)).toEqual([false, true, false])
      cleanup()
      expect(items.every((item) => !item.hidden && !item.attributes.size && item.recommendation.hidden)).toBe(true)
    } finally { vi.unstubAllGlobals() }
  })

  it.each(['Unknown', 'MacIntel'])('keeps downloads accessible without a configured match for %s', (platform) => {
    const { items, element } = setup(platform)
    try {
      mount(element, { display: 'matched' })
      expect(items.every((item) => !item.hidden && item.recommendation.hidden)).toBe(true)
    } finally { vi.unstubAllGlobals() }
  })

  it('supports an explicit fallback download for unknown platforms', () => {
    const { items, element } = setup('Unknown')
    items.push(download('other'))
    try {
      mount(element, { display: 'matched' })
      expect(items.map((item) => item.hidden)).toEqual([true, true, true, false])
    } finally { vi.unstubAllGlobals() }
  })
})
