import { describe, expect, it } from "vitest";
import {
  categoryLabel,
  categoryMatchesPath,
  categoryParts,
  categoryTreeOptions,
  extractMediaFilename,
  fileExtension,
  formatBytes,
  getFileIcon,
  isImageFile,
  mediaThumbUrl,
  mediaUrl,
  normalizeChoiceValue,
  normalizeChoiceValues,
  normalizeMediaModel,
  uploadedMediaFilename,
} from "./mediaUtils.js";

describe("mediaUtils", () => {
  it("detects media file types and icons", () => {
    expect(fileExtension("Hero.JPG")).toBe("jpg");
    expect(isImageFile("Hero.JPG")).toBe(true);
    expect(isImageFile("guide.pdf")).toBe(false);
    expect(getFileIcon("guide.pdf")).toEqual({
      icon: "mdi:file-pdf-box",
      class: "text-red-500",
    });
  });

  it("normalizes category paths for labels, matching, and selects", () => {
    expect(categoryParts("Images / Heroes")).toEqual(["Images", "Heroes"]);
    expect(categoryLabel("Images / Heroes")).toBe("Heroes");
    expect(categoryMatchesPath("Images / Heroes / Home", "Images / Heroes")).toBe(true);
    expect(categoryTreeOptions(["Images", "Images / Heroes"])).toEqual([
      { path: "Images", optionLabel: "Images" },
      { path: "Images / Heroes", optionLabel: "  Heroes" },
    ]);
  });

  it("formats byte counts", () => {
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(2048)).toBe("2.0 KB");
    expect(formatBytes(2097152)).toBe("2.0 MB");
  });

  it("extracts and normalizes media model values", () => {
    expect(extractMediaFilename("/media/default/hero%20image.jpg?size=small")).toBe("hero image.jpg");
    expect(uploadedMediaFilename({ url: "/media/default/hero.jpg" })).toBe("hero.jpg");
    expect(normalizeMediaModel("hero.jpg, hero.jpg, logo.png", true)).toEqual(["hero.jpg", "logo.png"]);
    expect(normalizeMediaModel(["hero.jpg", "logo.png"], false)).toEqual(["hero.jpg"]);
  });

  it("normalizes select and relation choices", () => {
    expect(normalizeChoiceValue([{ value: "posts" }])).toBe("posts");
    expect(normalizeChoiceValues('["posts", "pages", "posts"]')).toEqual(["posts", "pages"]);
  });

  it("uses the media variant endpoint for thumbnails", () => {
    expect(mediaUrl("site a", "hero image.jpg")).toBe("/media/site%20a/hero%20image.jpg");
    expect(mediaThumbUrl("site a", "hero image.jpg")).toBe(
      "/media/site%20a/hero%20image.jpg?size=thumbnail",
    );
  });
});
