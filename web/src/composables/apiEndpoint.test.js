import { describe, expect, it } from "vitest";
import {
  contentCollectionEndpoint,
  contentEntryEndpoint,
  contentTypeEndpoint,
  contentTypesEndpoint,
  mediaDetailEndpoint,
  mediaListEndpoint,
  usersAdminEndpoint,
} from "./apiEndpoint.js";

const origin = "https://example.test";

describe("api endpoint helpers", () => {
  it("builds a collection list endpoint with default query parameters", () => {
    expect(
      contentCollectionEndpoint(
        {
          collection: "portfolio",
          limit: 20,
          offset: 0,
          sortKey: "created_at",
          sortDir: "desc",
        },
        origin,
      ),
    ).toBe(
      "https://example.test/api/v1/workspaces/default/content/portfolio?limit=20&offset=0&sort=-created_at",
    );
  });

  it("converts ascending and descending collection sorts", () => {
    expect(
      contentCollectionEndpoint(
        {
          collection: "portfolio",
          limit: 20,
          offset: 0,
          sortKey: "title",
          sortDir: "asc",
        },
        origin,
      ),
    ).toContain("sort=title");

    expect(
      contentCollectionEndpoint(
        {
          collection: "portfolio",
          limit: 20,
          offset: 0,
          sortKey: "title",
          sortDir: "desc",
        },
        origin,
      ),
    ).toContain("sort=-title");
  });

  it("encodes search, category, and locale query values", () => {
    expect(
      contentCollectionEndpoint(
        {
          collection: "portfolio",
          limit: 20,
          offset: 0,
          sortKey: "created_at",
          sortDir: "desc",
          q: "hello world",
          locale: "de-AT",
        },
        origin,
      ),
    ).toBe(
      "https://example.test/api/v1/workspaces/default/content/portfolio?limit=20&offset=0&sort=-created_at&q=hello%20world&locale=de-AT",
    );

    expect(
      mediaListEndpoint(
        {
          limit: 20,
          offset: 40,
          q: "hero image",
          category: "Case Studies / 2026",
        },
        origin,
      ),
    ).toBe(
      "https://example.test/api/v1/workspaces/default/media?limit=20&offset=40&q=hero%20image&category=Case%20Studies%20%2F%202026",
    );
  });

  it("encodes content type and entry path segments", () => {
    expect(contentTypeEndpoint("case studies", origin)).toBe(
      "https://example.test/api/v1/workspaces/default/content-types/case%20studies",
    );
    expect(
      contentEntryEndpoint(
        { collection: "portfolio", entryId: "entry/one" },
        origin,
      ),
    ).toBe(
      "https://example.test/api/v1/workspaces/default/content/portfolio/entry%2Fone",
    );
  });

  it("builds singleton and media detail endpoints", () => {
    expect(
      contentEntryEndpoint(
        { collection: "homepage", entryId: "homepage", locale: "en" },
        origin,
      ),
    ).toBe(
      "https://example.test/api/v1/workspaces/default/content/homepage/homepage?locale=en",
    );

    expect(mediaDetailEndpoint("hero image.png", origin)).toBe(
      "https://example.test/api/v1/workspaces/default/media?q=hero%20image.png",
    );
  });

  it("prefers the public API for content type discovery", () => {
    expect(contentTypesEndpoint(origin)).toBe(
      "https://example.test/api/v1/workspaces/default/content-types",
    );
  });

  it("builds auth-only admin endpoints", () => {
    expect(usersAdminEndpoint(origin)).toBe(
      "https://example.test/admin/api/users",
    );
  });
});
