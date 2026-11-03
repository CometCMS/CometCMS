/** Matches Extension::MAX_BYTES on the server. */
export const MAX_EXTENSION_BYTES = 25 * 1024 * 1024

/** An i18n key when the selection cannot be an extension, otherwise null. The server validates the contents. */
export function extensionUploadError(files) {
  if (files.length !== 1 || !/\.zip$/i.test(files[0].name)) return 'site.extensions.zipOnly'
  if (files[0].size > MAX_EXTENSION_BYTES) return 'site.extensions.tooLarge'
  return null
}
