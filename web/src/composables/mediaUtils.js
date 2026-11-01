import { APP_BASE } from '../basePath.js'
const IMAGE_EXTENSIONS = new Set(["jpg", "jpeg", "png", "gif", "webp", "svg", "avif"]);

export function fileExtension(name) {
  return String(name).split(".").pop()?.toLowerCase() ?? "";
}

export function isImageFile(name) {
  return IMAGE_EXTENSIONS.has(fileExtension(name));
}

export function getFileIcon(name) {
  const ext = fileExtension(name);
  if (ext === "pdf") return { icon: "mdi:file-pdf-box", class: "text-red-500" };
  if (["doc", "docx", "odt"].includes(ext))
    return { icon: "mdi:file-word-box", class: "text-blue-600" };
  if (["xls", "xlsx", "ods", "csv"].includes(ext))
    return { icon: "mdi:file-excel-box", class: "text-green-600" };
  if (["ppt", "pptx", "odp"].includes(ext))
    return { icon: "mdi:file-powerpoint-box", class: "text-orange-500" };
  if (["zip", "rar", "7z", "tar", "gz", "bz2"].includes(ext))
    return { icon: "mdi:zip-box", class: "text-yellow-600" };
  if (
    [
      "mp4",
      "webm",
      "mov",
      "m4v",
      "avi",
      "mkv",
      "mpeg",
      "mpg",
      "ogv",
      "3gp",
      "3g2",
    ].includes(ext)
  )
    return { icon: "mdi:file-video-outline", class: "text-pink-500" };
  if (["mp3", "wav", "ogg", "m4a", "aac", "flac"].includes(ext))
    return { icon: "mdi:file-music-outline", class: "text-purple-500" };
  if (["txt", "md", "rtf"].includes(ext))
    return { icon: "mdi:file-document-outline", class: "text-slate-500" };
  return { icon: "mdi:file-outline", class: "text-slate-400" };
}

export function fileTypeLabel(file, t = null) {
  const ext = fileExtension(file?.name ?? file);
  if (ext === "") return typeof t === "function" ? t("media.genericFile") : "File";
  return ext.toUpperCase();
}

export function categoryParts(category) {
  return String(category)
    .split("/")
    .map((part) => part.trim())
    .filter(Boolean);
}

export function categoryLabel(category) {
  const parts = categoryParts(category);
  return parts[parts.length - 1] ?? String(category);
}

export function categoryMatchesPath(category, categoryPath) {
  return (
    category === categoryPath ||
    String(category).startsWith(`${categoryPath} / `)
  );
}

export function categoryTreeOptions(categories) {
  return categories.map((category) => {
    const parts = categoryParts(category);
    const label = parts[parts.length - 1] ?? category;
    const depth = Math.max(0, parts.length - 1);
    return { path: category, optionLabel: `${"  ".repeat(depth)}${label}` };
  });
}

export function formatBytes(bytes) {
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
  return (bytes / 1048576).toFixed(1) + " MB";
}

export function extractMediaFilename(value) {
  let raw =
    value && typeof value === "object"
      ? String(value.name ?? value.filename ?? value.url ?? "")
      : String(value ?? "");

  raw = raw.trim();

  if (!raw) {
    return "";
  }

  try {
    raw = decodeURIComponent(raw);
  } catch {
    // Keep raw value if it is not URI encoded.
  }

  const withoutQuery = raw.split(/[?#]/, 1)[0];
  const normalizedPath = withoutQuery.replace(/\\+/g, "/");
  const parts = normalizedPath.split("/").filter(Boolean);

  return parts.length > 0 ? parts[parts.length - 1] : "";
}

export function uploadedMediaFilename(item) {
  if (item && typeof item === "object") {
    if (item.url) {
      const fromUrl = extractMediaFilename(item.url);
      if (fromUrl !== "") {
        return fromUrl;
      }
    }

    if (item.filename) {
      const fromFilename = extractMediaFilename(item.filename);
      if (fromFilename !== "") {
        return fromFilename;
      }
    }

    const rawName = String(item.name ?? item.filename ?? "").trim();
    if (rawName !== "") {
      return rawName;
    }
  }

  return extractMediaFilename(item);
}

export function splitListLikeValue(value) {
  if (value === null || value === undefined) {
    return [];
  }

  if (typeof value === "object" && !Array.isArray(value)) {
    const choice = String(value.value ?? value.id ?? "").trim();
    if (choice !== "") {
      return [choice];
    }

    const media = extractMediaFilename(value);
    if (media !== "") {
      return [media];
    }

    return [];
  }

  const raw = String(value).trim();

  if (!raw) {
    return [];
  }

  if (raw.startsWith("[") && raw.endsWith("]")) {
    try {
      const parsed = JSON.parse(raw);

      if (Array.isArray(parsed)) {
        return parsed.map((item) => String(item ?? "").trim()).filter(Boolean);
      }
    } catch {
      // Fall through to comma-separated parsing.
    }
  }

  return raw
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

export function toStringList(value) {
  if (Array.isArray(value)) {
    return value.flatMap((item) => splitListLikeValue(item));
  }

  return splitListLikeValue(value);
}

export function normalizeMediaModel(value, multiple = false) {
  const source = Array.isArray(value) ? value : toStringList(value);
  const normalized = Array.from(
    new Set(source.map(extractMediaFilename).filter(Boolean)),
  );

  return multiple ? normalized : normalized.slice(0, 1);
}

export function normalizeChoiceValue(value) {
  if (value === null || value === undefined || value === "") return null;
  if (Array.isArray(value)) {
    const first = normalizeChoiceValues(value)[0];
    return first ?? null;
  }

  if (typeof value === "object") {
    const objectValue = String(value.value ?? value.id ?? "").trim();
    return objectValue === "" ? null : objectValue;
  }

  return String(value);
}

export function normalizeChoiceValues(value) {
  return Array.from(new Set(toStringList(value)));
}

export function mediaUrl(workspace, value) {
  return `${APP_BASE}/media/${encodeURIComponent(workspace)}/${encodeURIComponent(String(value))}`;
}

export function mediaThumbUrl(workspace, value) {
  return `${mediaUrl(workspace, value)}?size=thumbnail`;
}
