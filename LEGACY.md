# Legacy handling for 2.0.0

Reviewed on 2026-10-22, with the current-code cleanup below on 2026-10-27. Version 2.0.0 starts a new storage contract: existing installations must start fresh and recreate their content/designs as needed. Pre-2.0 workspaces, revisions, trash and backups are retired; this release does not migrate them. Local storage is never wiped automatically. Backup inspection and restore require an explicit version of at least 2.0.0 and reject older or unversioned archives before extracting files. Current backups carry the release version.

The checklist below records a decision for every item in the previous debt inventory. Resolved entries remain here as a release audit rather than being silently deleted.

## Website and theme

- [x] **Website files outside the theme folder:** removed `ThemeMigration`, constructor/restore migration calls and restore path remapping. Designs live in `site/theme/`.
- [x] **Generated component ids:** removed renaming, revision/reference conversion, alias reads and restore protection. Component ids are name slugs, uniquified only on collision; generated-looking names no longer need special handling.
- [x] **Stored `updated_at` in theme files:** removed stored timestamp fallbacks and placement cleanup. Theme/component modification times come from the filesystem.
- [x] **Combined `site/theme.json`:** removed old reads, save-time deletion and dual-format backup behavior. Tokens and CSS use separate files in `site/theme/`.
- [x] **Per-type card layouts:** removed stored-card reads, conversion and extra card-variant scans. Collections use their own card or the generated default. Old card files and their revisions are retired.
- [x] **Layout format versions — retained as current behavior:** keep the generic format gate, revision filtering and upgrade/replacement notice. They protect future format changes too; no old-format conversion is provided.
- [x] **String borders:** removed aliases; sides must be lists.
- [x] **Background images in node props:** removed lifting into style; background options remain current style properties.
- [x] **Boolean section stickiness:** removed conversion; the current enum accepts `none`, `always`, `reveal`.
- [x] **Theme density and `radius: 'xl'`:** removed density multiplication and radius alias. Explicit spacing/radius tokens remain; the editor's preset control continues to write explicit values.
- [x] **Standalone forms:** removed file storage, legacy form validation/CRUD, schema scans, editor setting recovery and POST/PUT/DELETE admin endpoints/client methods. Submission-enabled types provide forms with stable type-derived ids; settings live exclusively in schemas. Old placements and differing standalone-form settings must be recreated. GET form discovery and public submission remain supported.

## Content

- [x] **API exposure inferred from visibility:** removed inference. Schemas must explicitly set `api_enabled`; missing flags default to false, including configuration schemas. Private/public protection remains separate.
- [x] **Pre-release `data` bag/root `body`:** verified already retired by the current `values` storage contract and field allowlist. Revision editing uses `entryForLocale`, with no old `data` fallback. Schema fields named `data` or `body` remain ordinary fields.
- [x] **Entries without immutable `uid`:** removed derived hash ids and the website picker's slug/id fallback. Website references use immutable ids. Slug/id lookup remains supported for routes and API operations. Creation still issues random ids; restoring revisions preserves the current immutable id.
- [x] **Singleton content types:** removed `SingletonMigration` and both lazy/restore call sites. Types are collections; old fixed URLs, `single` references and singleton backups are retired.

## Accounts, media and workspaces

- [x] **API tokens inside users:** removed user scans, migration and token repository dependency on `UserRepository`. Only the dedicated token store authenticates tokens; embedded tokens are ignored. Fresh tokens have no `legacy_user_id`.
- [x] **Singular/encoded permission inputs:** removed singular action/resource keys, scalar action/resource coercion and JSON-string token decoding. Grants use plural lists; restored roles require explicit ids. Deny precedence, field restrictions, conditions and delegation remain supported.
- [ ] **Unscoped workspace grants — explicitly deferred:** global grants remain a current contract used by built-in roles and tokens, including access to future workspaces. Removing them requires a separate authorization and editor redesign covering allow, deny and delegation. Retain the existing semantics and tests for 2.0.0.
- [ ] **Discarded per-user permissions — explicitly deferred:** retain `UserRepository::save()` stripping the property as an input boundary defense, alongside role-only user authorization. This prevents arbitrary user payloads/backups from persisting misleading grants. Do not migrate them into roles. Restore also continues to discard embedded user tokens.
- [x] **Old thumbnail cache:** removed constructor cleanup and its filesystem scan. No old cache is migrated or served. The `/media-thumbs` endpoint and reserved prefix remain current behavior: they serve thumbnails from the current variants storage.
- [x] **Workspace icons in the shared directory:** removed read-time migration/re-encoding. Icons are read only from each workspace's `icon.png`; old filesystem backups are retired.

## Streamlining decisions

- [x] **Migration command/dry run:** no migration command for 2.0.0; the declared fresh-start boundary replaces migration prerequisites, including archived workspaces and historical snapshots.
- [x] **Batch small design conversions:** removed the compatibility branches outright; no conversion pass is needed for fresh data.
- [x] **Theme folders/component ids:** retired together, including aliases, locks/scans and restore remapping.
- [x] **Forms/collection cards:** consolidated on the current storage models; recreation is the explicit resolution for old differences.
- [x] **Schema flags/entry ids:** use explicit flags and immutable website ids; no backfill is offered.
- [x] **Permission aliases:** retired; global workspace grants are deferred above.
- [x] **Disposable caches/icons:** removed filesystem migration work; old caches/icons are not supported inputs.
- [x] **Minimum backup version:** enforced during both inspection and restore, before extraction.

## Current-code cleanup, 2026-10-27

Earlier development storage shapes are unsupported too. Current writers and generated defaults use the canonical shapes directly; no conversion pass is provided for older workspaces, revisions or backups.

- [x] Removed constructor-time layouts/frames migration, shared content-area migration and template-directory detection scans.
- [x] Removed flat-entry and `translations` conversion. Entries store `values` and per-locale `publication`; workspace languages live in `languages.json`, without recovery from site settings or schemas.
- [x] Removed hover-trigger conversion, single-entry accordion conversion and old accordion component override handling. Hover effects use `states.hover`; accordions use `items`.
- [x] Removed saved field-gallery promotion and collection-list aliases. Current gallery defaults still support media bindings and repeater items.
- [x] Removed synthetic content-query IDs and unreachable revision-history moves. Immutable entry UIDs and storage IDs survive edits and restores.
- [x] Standardized padding on four edges, links on structured destinations, and field factories on `source` references. Updated defaults, previews, sticky-section CSS and tests together.
- [x] Removed obsolete admin bookmark redirects and updated internal links. Thumbnail producers use `?size=thumbnail`; the old `variant` alias is retired. The `/media-thumbs` endpoint remains supported.
- [x] Content reads reuse request-local snapshots and identifier maps, invalidated on JSON writes; writers refresh after acquiring their lock. Queries resolve one locale and perform one final sort.
- [x] Admin media listings share inventory, metadata and permission filtering with their statistics. Admin and REST uploads use the same validation/storage workflow and register upload metadata once.
- [x] Builder history and dirty checking share a cached tree serialization. Recovery writes are debounced, flushed on save/tab close, and canceled when a document is discarded.

## Keep as current behavior

- [x] Generic layout format checks remain.
- [x] Content field rename and localization helpers (`migrateFields`, `seedDefaultLocaleFromRoot`, `syncLocalization`) support edits users can make today.
- [x] Configuration schemas, schema-less directory recovery and workspace folder reconciliation remain supported.
- [x] Role hierarchy checks for custom CSS/theme revisions and locked-admin built-in permission updates remain authorization requirements.
- [x] Defaults, locale fallbacks, responsive inheritance and unavailable-reference handling remain current behavior. Vendor Parsedown maintenance remains dependency work.
