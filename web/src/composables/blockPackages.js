/** Matches BlockPackage::MAX_BYTES on the server. */
export const MAX_PACKAGE_BYTES = 25 * 1024 * 1024

/** An i18n key when the selection cannot be a block package, otherwise null. The server validates the contents. */
export function packageUploadError(files) {
  if (files.length !== 1 || !/\.zip$/i.test(files[0].name)) return 'site.blocks.zipOnly'
  if (files[0].size > MAX_PACKAGE_BYTES) return 'site.blocks.tooLarge'
  return null
}
