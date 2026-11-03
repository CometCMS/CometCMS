/**
 * Editor texts of extensions in the admin language. Extensions ship their own texts in
 * comet-extension.json and block.json, and translations in i18n/<locale>.json, which the
 * server compiles into an `l10n` object: { de: { label: "Titel", option_labels: {…} } }.
 */

/** A text of an extension, block, prop or setting in `locale`, falling back to the base language, then the manifest. */
export function extensionText(item, field, locale) {
  const l10n = item?.l10n;
  const base = String(locale ?? "").split("-")[0];
  return l10n?.[locale]?.[field] ?? l10n?.[base]?.[field] ?? item?.[field];
}

/** An enum option as editors read it: a translated or declared label, else the stored value. */
export function optionText(def, option, locale) {
  const base = String(locale ?? "").split("-")[0];
  return def?.l10n?.[locale]?.option_labels?.[option] ?? def?.l10n?.[base]?.option_labels?.[option] ?? def?.option_labels?.[option] ?? option;
}

/** A variation's title or description in `locale`. */
export function variationText(kindSpec, variation, field, locale) {
  const base = String(locale ?? "").split("-")[0];
  const l10n = kindSpec?.l10n;
  return l10n?.[locale]?.variations?.[variation.name]?.[field] ?? l10n?.[base]?.variations?.[variation.name]?.[field] ?? variation[field];
}

/** Whether a value counts as set, the way template sections test it. */
function filled(value) {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    if ("type" in value) return value.type !== "none" && value.type !== undefined;
    return Object.values(value).some(filled);
  }
  if (Array.isArray(value)) return value.length > 0;
  return !(value === "" || value === false || value === 0 || value === "0" || value === null || value === undefined);
}

/**
 * Whether a prop's visible_when conditions hold for its sibling values. Bool and
 * truthiness conditions are true/false; enum and int conditions list accepted values.
 * `resolve` turns a stored (possibly translated) value into the current one.
 */
export function conditionsHold(def, values, resolve = (value) => value) {
  for (const [key, accepted] of Object.entries(def?.visible_when ?? {})) {
    const value = resolve(values?.[key]);
    const matches = accepted.some((expected) => (typeof expected === "boolean" ? filled(value) === expected : value === expected));
    if (!matches) return false;
  }
  return true;
}
