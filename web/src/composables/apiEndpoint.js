const API_ROOT = "/api/v1";
const ADMIN_API_ROOT = "/admin/api";

export function apiBase(origin = defaultOrigin()) {
  return `${origin}${API_ROOT}`;
}

export function adminApiBase(origin = defaultOrigin()) {
  return `${origin}${ADMIN_API_ROOT}`;
}

export function buildApiUrl(path, params = {}, origin = defaultOrigin()) {
  return buildUrl(apiBase(origin), path, params);
}

export function buildAdminApiUrl(path, params = {}, origin = defaultOrigin()) {
  return buildUrl(adminApiBase(origin), path, params);
}

export function contentTypesAdminEndpoint(origin) {
  return buildAdminApiUrl("/content-types", {}, origin);
}

export function usersAdminEndpoint(origin) {
  return buildAdminApiUrl("/users", {}, origin);
}

function buildUrl(base, path, params = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== null && value !== undefined && value !== "")
    .map(
      ([key, value]) =>
        `${encodeQueryKey(key)}=${encodeURIComponent(String(value))}`,
    )
    .join("&");

  return `${base}${path}${query ? `?${query}` : ""}`;
}

export function contentTypeEndpoint(name, origin) {
  return buildApiUrl(`/content-types/${encodePathSegment(name)}`, {}, origin);
}

export function contentCollectionEndpoint(
  { collection, limit, offset, sortKey, sortDir, q, locale },
  origin,
) {
  return buildApiUrl(
    `/content/${encodePathSegment(collection)}`,
    {
      limit,
      offset,
      sort: signedSort(sortKey, sortDir),
      q,
      locale,
    },
    origin,
  );
}

export function contentEntryEndpoint(
  { collection, entryId, locale },
  origin,
) {
  return buildApiUrl(
    `/content/${encodePathSegment(collection)}/${encodePathSegment(entryId)}`,
    { locale },
    origin,
  );
}

export function mediaListEndpoint({ limit, offset, q, category }, origin) {
  return buildApiUrl(
    "/media",
    {
      limit,
      offset,
      q,
      category,
    },
    origin,
  );
}

export function mediaDetailEndpoint(filename, origin) {
  return buildApiUrl("/media", { q: filename }, origin);
}

export function signedSort(sortKey, sortDir) {
  const key = String(sortKey ?? "").trim();
  if (key === "") return "";
  return sortDir === "desc" ? `-${key.replace(/^-+/, "")}` : key.replace(/^-+/, "");
}

function encodePathSegment(value) {
  return encodeURIComponent(String(value ?? ""));
}

function encodeQueryKey(key) {
  return encodeURIComponent(key).replace(/%5B/g, "[").replace(/%5D/g, "]");
}

function defaultOrigin() {
  return typeof window === "undefined" ? "" : window.location.origin;
}
