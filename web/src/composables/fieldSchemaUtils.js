import { parseSelectOptions, rangeDefaults } from "./fieldBuilderUtils.js";
import {
  hasConfiguredDefault,
  supportsConfiguredDefault,
} from "./fieldDefaults.js";

export const layoutWidthOptions = [
  {
    value: "1/3",
    labelKey: "fieldBuilder.layoutOneThird",
    shortLabel: "1/3",
    percent: "33.333%",
    units: 4,
  },
  {
    value: "1/2",
    labelKey: "fieldBuilder.layoutHalf",
    shortLabel: "1/2",
    percent: "50%",
    units: 6,
  },
  {
    value: "2/3",
    labelKey: "fieldBuilder.layoutTwoThirds",
    shortLabel: "2/3",
    percent: "66.667%",
    units: 8,
  },
  {
    value: "full",
    labelKey: "fieldBuilder.layoutFull",
    shortLabelKey: "fieldBuilder.layoutFullShort",
    percent: "100%",
    units: 12,
  },
];

export function normalizeLayoutWidth(width) {
  const value = String(width ?? "full");
  return layoutWidthOptions.some((option) => option.value === value)
    ? value
    : "full";
}

export function normalizeExternalLayout(field) {
  if (!field.layout || typeof field.layout !== "object") {
    delete field.layout;
    return;
  }

  const width = normalizeLayoutWidth(field.layout.width);
  const layout = { ...field.layout };

  if (width === "full") {
    delete layout.width;
  } else {
    layout.width = width;
  }

  if (Object.keys(layout).length === 0) {
    delete field.layout;
  } else {
    field.layout = layout;
  }
}

export function setFieldLayoutWidth(field, width) {
  const normalized = normalizeLayoutWidth(width);

  if (normalized === "full") {
    if (field.layout && typeof field.layout === "object") {
      delete field.layout.width;
      if (Object.keys(field.layout).length === 0) delete field.layout;
    }
    return;
  }

  field.layout = {
    ...(field.layout && typeof field.layout === "object" ? field.layout : {}),
    width: normalized,
  };
}

export function normalizeFieldDefault(field) {
  if (!hasConfiguredDefault(field)) return;

  if (field.type === "range") {
    Object.assign(field, rangeDefaults(field));
    return;
  }

  if (field.type === "number") {
    const number = Number(field.default);
    field.default = Number.isFinite(number) ? number : field.default;
    return;
  }

  if (field.type === "boolean") {
    field.default = field.default === true;
    return;
  }

  if (
    field.type === "select" &&
    field.multiple &&
    !Array.isArray(field.default)
  ) {
    field.default = field.default ? [String(field.default)] : [];
  }
}

export function normalizeExternalDefault(field, defaultJsonText = "") {
  if (!supportsConfiguredDefault(field)) {
    delete field.default;
    return;
  }

  if (!("default" in field)) {
    return;
  }

  if (field.type === "json") {
    try {
      field.default = JSON.parse(defaultJsonText);
    } catch {
      field.default = defaultJsonText;
    }
    return;
  }

  if (field.type === "number" || field.type === "range") {
    const number = Number(field.default);
    if (Number.isFinite(number)) field.default = number;
    return;
  }

  if (field.type === "boolean") {
    field.default = field.default === true;
    return;
  }

  if (field.type === "select") {
    if (field.multiple) {
      field.default = Array.isArray(field.default)
        ? field.default
        : field.default
          ? [String(field.default)]
          : [];
    } else if (Array.isArray(field.default)) {
      field.default = field.default[0] ?? "";
    }
  }
}

export function setJsonFieldDefault(field, value) {
  field._defaultJsonText = value;

  try {
    field.default = JSON.parse(value);
  } catch {
    field.default = value;
  }
}

export function selectDefaultOptions(field) {
  return parseSelectOptions(field._optionsText).map(({ key, label }) => ({
    value: key,
    label,
  }));
}

export function selectDefaultValues(field) {
  if (Array.isArray(field.default)) return field.default;
  return field.default ? [String(field.default)] : [];
}

export function selectDefaultSingleValue(field) {
  if (Array.isArray(field.default)) return field.default[0] ?? "";
  return field.default ?? "";
}

export function validColorDefault(value) {
  const color = String(value ?? "");
  return /^#[0-9a-fA-F]{6}$/.test(color) ? color : "#000000";
}

export function formatJsonDefaultText(field) {
  if (field?.type !== "json" || !("default" in field)) {
    return "";
  }

  return typeof field.default === "string"
    ? field.default
    : JSON.stringify(field.default, null, 2);
}
