import { describe, expect, it } from 'vitest'
import { MAX_EXTENSION_BYTES, extensionUploadError } from './extensions.js'

const file = (name, size = 10) => ({ name, size })

describe('extension selection', () => {
  it('accepts exactly one ZIP within the size limit', () => {
    expect(extensionUploadError([file('comet-essentials.zip')])).toBeNull()
    expect(extensionUploadError([file('PACKAGE.ZIP')])).toBeNull()
  })

  it('rejects folders, loose files, several files and oversized archives before uploading', () => {
    expect(extensionUploadError([file('block.json'), file('template.html')])).toBe('site.extensions.zipOnly')
    expect(extensionUploadError([file('a.zip'), file('b.zip')])).toBe('site.extensions.zipOnly')
    expect(extensionUploadError([file('template.html')])).toBe('site.extensions.zipOnly')
    expect(extensionUploadError([file('big.zip', MAX_EXTENSION_BYTES + 1)])).toBe('site.extensions.tooLarge')
  })
})
