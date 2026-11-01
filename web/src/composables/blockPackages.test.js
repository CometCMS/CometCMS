import { describe, expect, it } from 'vitest'
import { MAX_PACKAGE_BYTES, packageUploadError } from './blockPackages.js'

const file = (name, size = 10) => ({ name, size })

describe('block package selection', () => {
  it('accepts exactly one ZIP within the size limit', () => {
    expect(packageUploadError([file('comet-essentials.zip')])).toBeNull()
    expect(packageUploadError([file('PACKAGE.ZIP')])).toBeNull()
  })

  it('rejects folders, loose files, several files and oversized archives before uploading', () => {
    expect(packageUploadError([file('block.json'), file('template.html')])).toBe('site.blocks.zipOnly')
    expect(packageUploadError([file('a.zip'), file('b.zip')])).toBe('site.blocks.zipOnly')
    expect(packageUploadError([file('template.html')])).toBe('site.blocks.zipOnly')
    expect(packageUploadError([file('big.zip', MAX_PACKAGE_BYTES + 1)])).toBe('site.blocks.tooLarge')
  })
})
