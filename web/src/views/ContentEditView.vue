<template>
  <div>
    <div class="editor-sticky-header flex items-center gap-3">
      <router-link
        :to="backLink"
        class="icon-btn"
        :aria-label="t('app.back')"
      >
        <Icon icon="mdi:arrow-left" class="w-[18px] h-[18px]" />
      </router-link>
      <div class="min-w-0">
        <div v-if="contentTypeSchema" class="truncate text-xs font-medium text-slate-500">
          {{ contentTypeSchema.label }}
        </div>
        <h1 class="text-xl font-semibold tracking-tight text-slate-900 truncate">
          {{ editorTitle }}
        </h1>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <a
          v-if="!isNew && siteLinks?.url"
          :href="siteLinks.url"
          target="_blank"
          rel="noopener"
          class="btn-secondary"
          :title="siteLinks.url"
        >
          <Icon icon="mdi:open-in-new" class="h-4 w-4" />
          <span class="hidden sm:inline">{{ t("site.entry.view") }}</span>
        </a>
        <button
          v-if="siteLinks?.previewable"
          type="button"
          class="btn-secondary"
          :disabled="fullPreview.opening.value"
          :title="t('site.entry.previewHint')"
          @click="openWebsitePreview"
        >
          <Icon icon="mdi:eye-outline" class="h-4 w-4" />
          <span class="hidden sm:inline">{{ t("site.entry.preview") }}</span>
        </button>
        <button
          v-if="!isNew"
          @click="openRevisions"
          class="btn-secondary"
          type="button"
        >
          {{ t("contentEdit.history") }}
        </button>
        <span
          v-if="isReadOnly"
          class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200"
        >
          {{ t("contentEdit.preview") }}
        </span>
        <SplitButton
          v-if="canSaveEntry"
          type="submit"
          :disabled="saving"
          form="entry-form"
          :menu-label="t('common.moreActions')"
          :items="saveMenuItems"
          @select="saveDraft"
        >
          {{ saving ? t("common.saving") : saveLabel }}
          <template #badge>
            <span
              v-if="isDirty && !saving"
              class="pointer-events-none absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white"
            ></span>
          </template>
        </SplitButton>
      </div>
    </div>

    <!-- Restore revision banner -->
    <div
      v-if="previewingRevision"
      class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between gap-4"
    >
      <p class="text-sm text-amber-800">
        {{
          t("contentEdit.restoreBanner", {
            date: formatDateTime(previewingRevision.created_at),
          })
        }}
      </p>
      <button
        type="button"
        @click="cancelRestore"
        class="text-xs text-amber-700 hover:text-amber-900 underline shrink-0"
      >
        {{ t("contentEdit.cancelRestore") }}
      </button>
    </div>

    <div
      v-if="loadError"
      class="p-3 bg-red-50 text-red-700 rounded-lg text-sm mb-4"
    >
      {{ loadError }}
    </div>
    <div
      v-else-if="isReadOnly"
      class="mb-4 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600"
    >
      {{ t("contentEdit.readOnlyDescription") }}
    </div>

    <!-- Locale switcher (only for localized content types) -->
    <div v-if="contentTypeLocales.length > 0 && !isNew" class="mb-4 space-y-2">
      <TabNavigation
        :items="localeTabs"
        :model-value="currentLocale"
        :aria-label="t('contentEdit.locales')"
        @select="selectLocaleTab"
      >
          <template #actions="{ item: localeTab, active }">
            <button
              type="button"
              :class="[
                'mr-2 flex h-7 w-7 items-center justify-center rounded-md opacity-0 transition hover:bg-slate-100 hover:opacity-100 focus:opacity-100 group-hover:opacity-100',
                active ? 'text-theme-600' : 'text-slate-400',
              ]"
              :title="t('contentEdit.localeActions')"
              @click.stop="toggleLocaleMenu(localeTab.value)"
            >
              <Icon icon="mdi:dots-horizontal" class="w-4 h-4" />
            </button>
            <div
              v-if="localeMenu === localeTab.value"
              class="absolute left-0 top-full z-20 mt-2 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg"
            >
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-white"
                :disabled="isReadOnly || localeTab.value === defaultLocale"
                @click.stop="syncLocaleFromDefault(localeTab.value)"
              >
                <Icon icon="mdi:sync" class="h-4 w-4" />
                {{ t("contentEdit.syncContentDefault") }}
              </button>
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-red-300 disabled:hover:bg-white"
                :disabled="
                  isReadOnly ||
                  localeTab.value === defaultLocale ||
                  deletingTranslation ||
                  localeTab.muted
                "
                @click.stop="handleDeleteTranslation(localeTab.value)"
              >
                <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
                {{ t("contentEdit.remove") }}
              </button>
            </div>
          </template>
      </TabNavigation>
      <p
        v-if="unsupportedTranslationLocales.length > 0"
        class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"
      >
        {{
          t("contentEdit.unsupportedLocales", {
            locales: unsupportedTranslationLocales.join(", "),
          })
        }}
      </p>
    </div>

    <form
      v-if="!loadError"
      id="entry-form"
      ref="entryForm"
      @submit.prevent="handleSave(form.status === 'draft')"
      class="space-y-6"
    >
      <!-- Core fields -->
      <div class="card p-6 space-y-4">
        <h2 class="card-title">
          {{ t("contentEdit.core") }}
        </h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

          <div class="col-span-1">
            <label for="content-title" class="form-label">{{ t("contentEdit.title") }}</label>
            <TranslationInfo v-if="fullEntry" :value="fullEntry.values?.title" :locale="currentLocale" :default-locale="defaultLocale" :readonly="isReadOnly" @reset="resetTranslation('title')" />
            <input
              id="content-title"
              v-model="form.title"
              type="text"
              required
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              :class="{ 'border-red-400': fieldErrors.title }"
              :aria-invalid="!!fieldErrors.title || undefined"
            />
            <p v-if="fieldErrors.title" class="mt-1 text-xs text-red-600">{{ fieldErrors.title }}</p>
          </div>

          <div>
            <label for="content-slug" class="form-label">
              {{ t("contentEdit.slug") }}

            </label>
            <input
              id="content-slug"
              v-model="form.slug"
              type="text"
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              :class="{ 'border-red-400': fieldErrors.slug }"
              :aria-invalid="!!fieldErrors.slug || undefined"
            />
            <p v-if="fieldErrors.slug" class="mt-1 text-xs text-red-600">{{ fieldErrors.slug }}</p>
          </div>

          <div v-if="contentTypeSchema?.hierarchical">
            <label for="content-parent" class="form-label">{{ t("contentEdit.parent") }}</label>
            <select
              id="content-parent"
              v-model="parentUid"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
              :class="{ 'border-red-400': fieldErrors.parent }"
              :aria-invalid="!!fieldErrors.parent || undefined"
            >
              <option value="">{{ t("contentEdit.noParent") }}</option>
              <option v-for="option in parentOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <p v-if="fieldErrors.parent" class="mt-1 text-xs text-red-600">{{ fieldErrors.parent }}</p>
          </div>

          <!-- The website template of this entry's page: automatic, or one chosen here. -->
          <div v-if="templateOptions.length > 1">
            <label for="content-template" class="form-label">{{ t("contentEdit.template") }}</label>
            <select
              id="content-template"
              v-model="pageTemplate"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
              :class="{ 'border-red-400': fieldErrors.page_template }"
              aria-describedby="content-template-hint"
            >
              <option value="">{{ t("contentEdit.templateAutomatic") }}</option>
              <option v-for="option in templateOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
            </select>
            <p id="content-template-hint" class="mt-1 text-xs text-slate-500">{{ templateHint }}</p>
            <p v-if="fieldErrors.page_template" class="mt-1 text-xs text-red-600">{{ fieldErrors.page_template }}</p>
          </div>


          <div>
            <label for="content-status" class="form-label">{{ t("contentEdit.status") }}</label>
            <select
              id="content-status"
              v-model="form.status"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option v-for="s in statuses" :key="s.value" :value="s.value">
                {{ s.label }}
              </option>
            </select>
            <p v-if="fieldErrors.status" class="mt-1 text-xs text-red-600">{{ fieldErrors.status }}</p>
          </div>

          <div v-if="isNew && contentTypeLocales.length > 0">
            <label for="content-locale" class="form-label">{{ t("contentEdit.locale") }}</label>
            <select
              id="content-locale"
              v-model="form.locale"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option
                v-for="loc in orderedContentTypeLocales"
                :key="loc"
                :value="loc"
              >
                {{ loc }}
              </option>
            </select>
          </div>
          <div v-else>
            <label for="content-author" class="form-label">{{ t("contentEdit.author") }}</label>
            <!-- Without users.read the user list is unavailable; show the account id instead. -->
            <input
              v-if="users.length === 0"
              id="content-author"
              :value="form.author_id ?? auth.user?.username ?? ''"
              type="text"
              readonly
              class="form-input w-full rounded-lg border-slate-300 bg-slate-50 text-sm text-slate-600"
            />
            <select
              v-else
              id="content-author"
              v-model="form.author_id"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option :value="null" disabled>
                {{ t("contentEdit.select") }}
              </option>
              <option v-for="u in users" :key="u.id" :value="u.id">
                {{ u.username }}
              </option>
            </select>
          </div>


          <div>
            <label for="content-published-at" class="form-label">
              <template v-if="form.status === 'published' && isScheduled"
                >{{ t("contentEdit.scheduledFor") }}
                <span class="text-slate-400 font-normal ml-1">{{
                  t("contentEdit.autoPublishes")
                }}</span></template
              >
              <template v-else>{{ t("contentEdit.publishedAt") }}</template>
            </label>
            <input
              id="content-published-at"
              v-model="form.published_at"
              type="datetime-local"
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
            <p v-if="fieldErrors.published_at" class="mt-1 text-xs text-red-600">{{ fieldErrors.published_at }}</p>
          </div>
        </div>

        <dl v-if="!isNew && (siteLinks?.url || contentTypeSchema?.api_enabled)" class="space-y-3 border-t border-slate-100 pt-4 text-xs">
          <div v-if="siteLinks?.url" class="space-y-1">
            <dt class="font-medium text-slate-500">{{ t("contentEdit.websiteUrl") }}</dt>
            <dd>
              <a :href="siteLinks.url" target="_blank" rel="noopener" class="inline-flex max-w-full items-start gap-1.5 text-theme-700 hover:underline">
                <span class="min-w-0 break-all">{{ siteLinks.url }}</span>
                <Icon icon="mdi:open-in-new" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              </a>
              <div v-if="siteLinks.url_conflict" role="status" class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-amber-900">
                <Icon icon="mdi:alert-outline" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <div class="space-y-1">
                  <p>{{ t("contentEdit.websiteUrlConflict") }}</p>
                  <router-link v-if="siteLinks.can_manage" to="/website/general" class="inline-block font-medium underline hover:no-underline">{{ t("contentEdit.websiteUrlDomains") }}</router-link>
                  <p v-else>{{ t("contentEdit.websiteUrlAskAdmin") }}</p>
                </div>
              </div>
            </dd>
          </div>
          <div v-if="contentTypeSchema?.api_enabled" class="space-y-1">
            <dt class="font-medium text-slate-500">{{ t("contentEdit.apiUrl") }}</dt>
            <dd>
              <a :href="apiEndpointUrl" target="_blank" rel="noopener" class="inline-flex max-w-full items-start gap-1.5 text-theme-700 hover:underline">
                <span class="min-w-0 break-all">{{ apiEndpointUrl }}</span>
                <Icon icon="mdi:open-in-new" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              </a>
            </dd>
          </div>
        </dl>

        <!-- Entry metadata (read-only) -->
        <div
          v-if="!isNew"
          class="pt-2 border-t border-slate-100 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-slate-500"
        >
          <div class="flex gap-1.5">
            <span class="font-medium text-slate-400 shrink-0">{{
              t("contentEdit.created")
            }}</span>
            <span>{{ formatDateTime(entryMeta.created_at) }}</span>
          </div>
          <div class="flex gap-1.5">
            <span class="font-medium text-slate-400 shrink-0">{{
              t("contentEdit.updated")
            }}</span>
            <span>{{ formatDateTime(entryMeta.updated_at) }}</span>
          </div>
        </div>
      </div>

      <!-- Entries with page content design their own page; the layout around it is shared. -->
      <section
        v-if="!isNew && siteLinks?.can_manage && siteLinks.routed && pageAreas.length"
        class="card page-builder-cta page-builder-overview"
        :aria-label="t('site.page.layoutAndContent')"
      >
        <div class="page-builder-overview-main">
          <div class="page-builder-overview-content">
            <h2 class="page-builder-overview-heading">{{ t('site.page.layoutAndContent') }}</h2>
            <div class="page-builder-overview-areas">
              <button
                v-for="field in pageAreas"
                :key="field.key"
                type="button"
                class="page-builder-overview-area"
                :aria-pressed="activePageArea?.key === field.key"
                @click="selectedPageArea = field.key"
              >
                <span class="page-builder-overview-icon" aria-hidden="true">
                  <Icon icon="mdi:file-document-outline" class="h-7 w-7" />
                </span>
                <span class="min-w-0">
                  <span class="block text-base font-semibold text-slate-900">{{ field.label }}</span>
                  <span class="page-builder-cta-description mt-1 block text-sm text-slate-500">
                    {{ t(field.customized ? 'site.page.ownHint' : 'site.page.defaultHint') }}
                  </span>
                </span>
              </button>
            </div>
          </div>
          <div class="page-builder-overview-actions">
            <div class="page-builder-overview-illustration" aria-hidden="true">
              <span class="page-builder-overview-pencil"><Icon icon="mdi:pencil-outline" class="h-7 w-7" /></span>
            </div>
            <template v-if="!isReadOnly && activePageArea">
              <router-link
                :to="pageLink(activePageArea.key)"
                class="btn-primary w-full !whitespace-normal !py-2.5"
                :title="t('site.page.editInBuilder', { label: activePageArea.label })"
              >
                <Icon icon="mdi:square-edit-outline" class="h-5 w-5 shrink-0" aria-hidden="true" />
                {{ t('site.page.design') }}
                <Icon icon="mdi:arrow-right" class="ml-auto h-5 w-5 shrink-0" aria-hidden="true" />
              </router-link>
              <button
                v-if="activePageArea.customized"
                type="button"
                class="btn-secondary w-full !whitespace-normal !py-2.5"
                :title="t('site.page.resetArea', { label: activePageArea.label })"
                :disabled="resettingPage"
                @click="pendingPageReset = activePageArea.key"
              >
                <Icon icon="mdi:restore" class="h-5 w-5 shrink-0" aria-hidden="true" />
                {{ t('site.page.reset') }}
              </button>
            </template>
          </div>
        </div>
        <div class="page-builder-overview-footer">
          <span class="page-builder-overview-icon" aria-hidden="true"><Icon icon="mdi:layers-triple-outline" class="h-6 w-6" /></span>
          <p class="page-builder-cta-description text-sm text-slate-500">
            {{ t('site.page.layoutHint') }}
            <router-link :to="designLink" class="inline-flex items-center gap-1.5 font-semibold text-theme-700 hover:underline">
              {{ t('site.page.editLayout') }}
              <Icon icon="mdi:open-in-new" class="h-4 w-4 shrink-0" aria-hidden="true" />
            </router-link>
          </p>
        </div>
      </section>
      <!-- Pages and collection entries keep their design action alongside their content. -->
      <PageBuilderCta
        v-else-if="!isNew && siteLinks?.can_manage && siteLinks.routed"
        :title="t('site.builderCta.title')"
        :description="t('site.entry.editDesignHint')"
        :to="designLink"
        :action-label="t('site.entry.editDesign')"
      />

      <!-- Custom fields -->
      <div v-if="hasCustomFields" class="card p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
          <h2 class="card-title">
            {{ t("contentEdit.fields") }}
          </h2>
          <router-link
            v-if="canEditContentType"
            :to="contentTypeEditLink"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
            :title="t('contentEdit.editContentType')"
            :aria-label="t('contentEdit.editContentType')"
          >
            <Icon icon="mdi:cog-outline" class="h-5 w-5" />
          </router-link>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-12">
          <div
            v-for="(config, fieldName) in customSchema"
            :key="fieldName"
            :class="fieldLayoutClass(config)"
          >
            <BaseField
              :name="fieldName"
              :config="config"
              :locale="currentLocale"
              :error="fieldErrors[fieldName]"
              :readonly="isReadOnly || isReadonlyUniversalField(config)"
            >
              <TranslationInfo v-if="fullEntry && config.localized !== false" :value="fullEntry.values?.[fieldName]" :locale="currentLocale" :default-locale="defaultLocale" :readonly="isReadOnly" @reset="resetTranslation(fieldName)" />
              <FieldInput
                :name="fieldName"
                :config="config"
              :locale="currentLocale"
                v-model="form[fieldName]"
                :readonly="isReadOnly || isReadonlyUniversalField(config)"
              />
            </BaseField>
          </div>
        </div>
      </div>

      <div
        v-if="saveError"
        class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
      >
        {{ saveError }}
      </div>

      <div v-if="!isNew && canDeleteEntry" class="flex justify-end">
        <button
          type="button"
          @click="showDeleteModal = true"
          class="btn-danger"
        >
          {{ deleteLabel }}
        </button>
      </div>
    </form>

    <ConfirmModal
      v-model="leavePromptOpen"
      :title="t('unsaved.title')"
      :message="t('unsaved.message')"
      :confirm-label="t('unsaved.leave')"
      :cancel-label="t('unsaved.stay')"
      variant="warning"
      @confirm="confirmLeave"
    />
    <ConfirmModal
      :model-value="pendingPageReset !== ''"
      :title="t('site.page.resetTitle')"
      :message="t('site.page.resetEntryMessage')"
      :confirm-label="t('site.page.reset')"
      :loading="resettingPage"
      variant="warning"
      @update:model-value="(open) => { if (!open) pendingPageReset = '' }"
      @confirm="resetPage"
    />
    <ConfirmModal
      v-model="showDeleteModal"
      :title="t('contentEdit.moveTrashTitle')"
      :message="deleteMessage"
      :confirm-label="t('contentEdit.moveTrashConfirm')"
      :loading="deleting"
      @confirm="handleDelete"
    />

    <ConfirmModal
      v-model="showDeleteTranslationModal"
      :title="
        t('contentEdit.deleteLocaleTitle', { locale: pendingDeleteLocale })
      "
      :message="t('contentEdit.deleteLocaleMessage')"
      :confirm-label="t('contentEdit.deleteLocaleConfirm')"
      :loading="deletingTranslation"
      @confirm="confirmDeleteTranslation"
    />

    <SlidePanel
      v-model="showRevisions"
      :title="t('contentEdit.entryHistory')"
      :subtitle="`${revisions.length} ${t(revisions.length === 1 ? 'contentEdit.savedRevision' : 'contentEdit.savedRevisions')}`"
    >
      <div
        v-if="revisionError"
        class="m-4 p-3 bg-red-50 text-red-700 rounded-lg text-sm"
      >
        {{ revisionError }}
      </div>

      <div v-if="loadingRevisions" class="p-6 text-sm text-slate-500">
        {{ t("contentEdit.loading") }}
      </div>
      <div
        v-else-if="revisions.length === 0"
        class="p-6 text-sm text-slate-500"
      >
        {{ t("contentEdit.noRevisions") }}
      </div>

      <div v-else class="divide-y divide-slate-100">
        <!-- Current state entry -->
        <article class="p-5 space-y-3">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3
                class="text-sm font-semibold text-slate-900 flex items-center gap-2"
              >
                {{ form.title || form.slug }}
                <span
                  class="text-xs font-medium bg-theme-100 text-theme-700 px-1.5 py-0.5 rounded"
                  >{{ t("contentEdit.current") }}</span
                >
              </h3>
              <p class="text-xs text-slate-500">{{ formatStatus(form.status) }}</p>
            </div>
          </div>
          <div v-if="revisions.length > 0">
            <template v-if="currentVsFirstDiff.length > 0">
              <ul class="text-xs space-y-1">
                <li
                  v-for="change in currentVsFirstDiff"
                  :key="change.field"
                  class="flex items-baseline gap-1 min-w-0"
                >
                  <span class="text-slate-400 shrink-0 font-medium"
                    >{{ change.field }}:</span
                  >
                  <span
                    class="text-slate-400 line-through truncate max-w-[100px]"
                    >{{ formatRevVal(change.from) }}</span
                  >
                  <span class="text-slate-400 shrink-0">→</span>
                  <span class="text-slate-700 truncate max-w-[100px]">{{
                    formatRevVal(change.to)
                  }}</span>
                </li>
              </ul>
            </template>
            <p v-else class="text-xs text-slate-400">
              {{ t("contentEdit.noChanges") }}
            </p>
          </div>
        </article>

        <article
          v-for="(revision, index) in revisions"
          :key="revision.id"
          class="p-5 space-y-3"
        >
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3 class="text-sm font-semibold text-slate-900">
                {{ revision.title || revision.slug }}
              </h3>
              <p class="text-xs text-slate-500">
                {{ formatDateTime(revision.created_at) }} ·
                {{ formatStatus(revision.status) }}
              </p>
              <!-- Author -->
              <div
                v-if="userMap[revision.created_by]"
                class="flex items-center gap-1.5 mt-1"
              >
                <div
                  class="w-4 h-4 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none shrink-0"
                >
                  <img
                    v-if="userMap[revision.created_by].has_avatar"
                    :src="`${ADMIN_API_BASE}/users/${revision.created_by}/avatar`"
                    class="w-full h-full object-cover"
                    :alt="userMap[revision.created_by].username"
                  />
                  <span v-else style="font-size: 9px">{{
                    userMap[revision.created_by].username?.[0]?.toUpperCase()
                  }}</span>
                </div>
                <span class="text-xs text-slate-500">{{
                  userMap[revision.created_by].username
                }}</span>
              </div>
              <div
                v-else-if="revision.created_by"
                class="flex items-center gap-1.5 mt-1"
              >
                <div
                  class="w-4 h-4 rounded-full bg-slate-300 flex items-center justify-center shrink-0"
                >
                  <span style="font-size: 9px" class="text-slate-500">?</span>
                </div>
                <span class="text-xs text-slate-400"
                  >{{ revision.created_by }}
                  <span class="italic">{{
                    t("contentEdit.deletedUser")
                  }}</span></span
                >
              </div>
            </div>
            <button
              v-if="canSaveEntry"
              type="button"
              class="btn-secondary text-xs py-1.5 px-3 shrink-0"
              @click="restoreRevision(revision)"
            >
              {{ t("contentEdit.restore") }}
            </button>
          </div>

          <!-- Changes vs previous revision -->
          <div v-if="index < revisions.length - 1">
            <template v-if="revisionDiff(index).length > 0">
              <ul class="text-xs space-y-1">
                <li
                  v-for="change in revisionDiff(index)"
                  :key="change.field"
                  class="flex items-baseline gap-1 min-w-0"
                >
                  <span class="text-slate-400 shrink-0 font-medium"
                    >{{ change.field }}:</span
                  >
                  <span
                    class="text-slate-400 line-through truncate max-w-[100px]"
                    >{{ formatRevVal(change.from) }}</span
                  >
                  <span class="text-slate-400 shrink-0">→</span>
                  <span class="text-slate-700 truncate max-w-[100px]">{{
                    formatRevVal(change.to)
                  }}</span>
                </li>
              </ul>
            </template>
            <p v-else class="text-xs text-slate-400">
              {{ t("contentEdit.noFieldChanges") }}
            </p>
          </div>
          <p v-else class="text-xs text-slate-400 italic">
            {{ t("contentEdit.oldestRevision") }}
          </p>
        </article>
      </div>
    </SlidePanel>
  </div>
</template>

<script setup>
import { useSitePreview } from "../composables/useSitePreview.js";
import { ADMIN_API_BASE } from "../basePath.js";
import {
  ref,
  computed,
  onMounted,
  onBeforeUnmount,
  watch,
  nextTick,
} from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import FieldInput from "../components/FieldInput.vue";
import BaseField from "../components/BaseField.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { useUnsavedChangesGuard } from "../composables/useUnsavedChangesGuard.js";
import { usePermissions } from "../composables/usePermissions.js";
import PageBuilderCta from "../components/site/PageBuilderCta.vue";
import SlidePanel from "../components/SlidePanel.vue";
import SplitButton from "../components/SplitButton.vue";
import TabNavigation from "../components/TabNavigation.vue";
import { api } from "../api/index.js";
import { fieldDefaultValue } from "../composables/fieldDefaults.js";
import { hasEntryDraftChanges } from "../composables/entryDraftChanges.js";
import { languageTabItem } from "../composables/localeOptions.js";
import { useToastStore } from "../stores/toast.js";
import TranslationInfo from "../components/TranslationInfo.vue";
import { entryForLocale, resolveLocalized } from "../composables/localizedValues.js";
import { useLanguages } from "../composables/useLanguages.js";
import { useI18n } from "../i18n/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import {
  contentCollectionEndpoint,
  contentEntryEndpoint,
} from "../composables/apiEndpoint.js";
import { orderLocales } from "../composables/contentDisplay.js";
import { entryKey, subtreeKeys, treeRows } from "../composables/entryTree.js";
import { reasonLabel, templateName } from "../composables/templates.js";

const users = ref([]);
const userMap = computed(() =>
  Object.fromEntries(users.value.map((u) => [u.id, u])),
);

async function loadUsers() {
  // Names are a nicety; users without users.read see account ids instead.
  if (!auth.can("users.read")) return;
  try {
    users.value = (await api.users.list()).data;
  } catch {
    users.value = [];
  }
}

// ── Page hierarchy ────────────────────────────────────────────────────────────
// The parent is shared by all languages, so it lives outside the per-language form.
const parentUid = ref("");
/** The website template the entry chose ("" = automatic, see SiteRepository::resolveTemplate()). */
const pageTemplate = ref("");
const pageTemplateBaseline = ref("");
const parentBaseline = ref("");
const siblingPages = ref([]);
const parentOptions = computed(() => {
  // A page cannot move below itself or its own children.
  const self = fullEntry.value ? entryKey(fullEntry.value) : "";
  const excluded = self ? subtreeKeys(siblingPages.value, self) : new Set();
  return treeRows(siblingPages.value)
    .filter(({ entry }) => !excluded.has(entryKey(entry)))
    .map(({ entry, depth }) => ({
      value: entryKey(entry),
      label: `${"\u00a0\u00a0\u00a0".repeat(depth)}${entry.title || entry.slug}`,
    }));
});

async function loadSiblingPages() {
  try {
    siblingPages.value = (await api.content.list(collection, { limit: 10000, locale: defaultLocale.value || undefined })).data ?? [];
  } catch {
    siblingPages.value = [];
  }
}

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const { t } = useI18n();
const auth = useAuthStore();
const { canContent } = usePermissions();
const apiEndpointStore = useApiEndpointStore();
const collection = route.params.collection;
const apiEndpointOwner = "content-edit";
const entryId = computed(() => route.params.id || "");
const isNew = computed(() => !entryId.value);
const backLink = computed(() => `/content/${collection}`);
const editorTitle = computed(() => {
  return isNew.value
    ? t("contentEdit.newEntryTitle")
    : t("contentEdit.editEntryTitle", { title: form.value.title });
});
const entryForm = ref(null);
const saveLabel = computed(() => t(form.value.status === "draft" ? "contentEdit.publishEntry" : "contentEdit.saveEntry"));
const saveMenuItems = computed(() => form.value.status === "draft"
  ? [{ key: "save", label: t("contentEdit.saveEntry") }]
  : []);
const deleteLabel = computed(() => t("contentEdit.deleteEntry"));
const contentTypeEditLink = computed(() => `/content-types/${collection}/edit`);
const canEditContentType = computed(() => {
  const resource = `schema:${collection}`;
  return (
    auth.can("schema.read", resource) && auth.can("schema.update", resource)
  );
});
const permissionEntry = computed(() => ({ id: entryId.value, slug: form.value.slug }));
const canCreateEntry = computed(() => canContent("content.create", collection));
const canUpdateEntry = computed(() =>
  canContent("content.update", collection, permissionEntry.value),
);
const canDeleteEntry = computed(() =>
  canContent("content.delete", collection, permissionEntry.value),
);
const canSaveEntry = computed(() =>
  isNew.value ? canCreateEntry.value : canUpdateEntry.value,
);
const isReadOnly = computed(() => !isNew.value && !canUpdateEntry.value);
const deleteMessage = computed(() => t("contentEdit.entryTrashMessage"));
const statuses = computed(() =>
  ["draft", "published", "protected", "archived"].map((value) => ({
    value,
    label: t(`status.${value}`),
  })),
);
function formatStatus(status) {
  if (!status) return t("contentList.unknownStatus");
  const key = `status.${status}`;
  const label = t(key);
  return label === key ? status : label;
}
const isScheduled = computed(() => {
  if (form.value.status !== "published" || !form.value.published_at)
    return false;
  return new Date(form.value.published_at) > new Date();
});

const form = ref({
  // New entries are attributed to their creator unless another author is picked.
  author_id: auth.user?.id ?? null,
  status: "draft",
  title: "",
  slug: "",
  published_at: "",
  locale: "",
});
const contentTypeLocales = ref([]);
const fullEntry = ref(null);
const localeDrafts = ref({});
const resetFields = ref([]);
const liveEntryBeforeRestore = ref(null);
const editorLanguages = useLanguages();
const currentLocale = ref("");
watch(currentLocale, (value) => { if (value) editorLanguages.activeLocale.value = value; }); // active locale tab
const defaultLocale = computed(
  () =>
    contentTypeSchema.value?.default_locale ||
    contentTypeLocales.value[0] ||
    "",
);
const orderedContentTypeLocales = computed(() =>
  orderLocales(contentTypeLocales.value, defaultLocale.value),
);
const apiEndpointUrl = computed(() => {
  if (!isNew.value) {
    return contentEntryEndpoint({
      collection,
      entryId: entryId.value,
      locale: currentLocale.value,
    });
  }

  return contentCollectionEndpoint({
    collection,
    limit: 20,
    offset: 0,
    sortKey: "created_at",
    sortDir: "desc",
  });
});
const unsupportedTranslationLocales = computed(() => {
  const translations = fullEntry.value?.publication;
  if (!translations || contentTypeLocales.value.length === 0) return [];
  return Object.keys(translations).filter(
    (loc) => !contentTypeLocales.value.includes(loc),
  );
});
function hasTranslation(loc) {
  return (
    fullEntry.value?.publication != null && loc in fullEntry.value.publication
  );
}
const localeTabs = computed(() =>
  orderedContentTypeLocales.value.map((loc) => {
    const available = hasTranslation(loc);
    return {
      ...languageTabItem(loc, defaultLocale.value, { defaultLabel: t("contentEdit.default") }),
      icon: available ? "" : "mdi:plus",
      muted: !available,
      disabled: !available && isReadOnly.value,
      hasActions: true,
    };
  }),
);

function selectLocaleTab(tab) {
  localeMenu.value = null;
  if (hasTranslation(tab.value)) {
    switchLocale(tab.value);
  } else {
    createTranslation(tab.value);
  }
}

function formFromEntry(entry, loc = "") {
  const source = contentTypeLocales.value.length > 0 && loc
    ? entryForLocale(entry, loc, defaultLocale.value)
    : { ...revisionFieldSource(entry), status: entry.status, slug: entry.slug, published_at: entry.published_at };
  const values = {
    status: source.status ?? "draft",
    slug: source.slug ?? "",
    published_at: source.published_at ? toDatetimeLocal(source.published_at) : "",
    author_id: entry.author_id ?? null,
    locale: loc,
    title: source.title ?? "",
  };
  for (const [name, config] of Object.entries(customSchema.value)) {
    values[name] = fieldValueForForm(source[name] ?? null, config);
  }
  return applyFieldDefaults(values);
}

function populateFormFromLocale(entry, loc) {
  form.value = formFromEntry(entry, loc);
  resetFields.value = [];
}

function populateFormFromEntry(entry, loc = "") {
  populateFormFromLocale(entry, loc);
}

function switchLocale(loc) {
  if (!fullEntry.value) return;
  if (currentLocale.value) localeDrafts.value[currentLocale.value] = { form: cloneLocaleValue(form.value), resets: [...resetFields.value] };
  currentLocale.value = loc;
  if (localeDrafts.value[loc]) {
    form.value = cloneLocaleValue(localeDrafts.value[loc].form);
    resetFields.value = [...localeDrafts.value[loc].resets];
  } else populateFormFromLocale(fullEntry.value, loc);
}

function createTranslation(loc) {
  if (isReadOnly.value) return;
  localeMenu.value = null;
  switchLocale(loc);
  if (!hasTranslation(loc)) { form.value.slug = ''; form.value.status = 'draft'; }
}

function resetTranslation(name) {
  if (!resetFields.value.includes(name)) resetFields.value.push(name);
  const source = entryForLocale(fullEntry.value, defaultLocale.value, defaultLocale.value);
  form.value[name] = cloneLocaleValue(source[name]);
}

async function handleDeleteTranslation(loc) {
  if (isReadOnly.value || deletingTranslation.value) return;
  localeMenu.value = null;
  pendingDeleteLocale.value = loc;
  showDeleteTranslationModal.value = true;
}

function toggleLocaleMenu(loc) {
  localeMenu.value = localeMenu.value === loc ? null : loc;
}

function closeLocaleMenu(event) {
  if (localeMenu.value === null) return;
  const target = event.target;
  if (target instanceof Element && target.closest("[data-tab-value]"))
    return;
  localeMenu.value = null;
}

function onLocaleMenuKeydown(event) {
  if (event.key === "Escape") localeMenu.value = null;
}

function cloneLocaleValue(value) {
  if (value === undefined) return null;
  if (Array.isArray(value)) return value.map((item) => cloneLocaleValue(item));
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value).map(([key, item]) => [key, cloneLocaleValue(item)]),
    );
  }
  return value;
}

function isUniversalField(config) {
  return contentTypeLocales.value.length > 0 && config?.localized === false;
}

function isReadonlyUniversalField(config) {
  return (
    isUniversalField(config) &&
    currentLocale.value !== "" &&
    currentLocale.value !== defaultLocale.value
  );
}

function fieldLayoutClass(config) {
  const width = String(config?.layout?.width ?? "full");

  return {
    "md:col-span-4": width === "1/3",
    "md:col-span-6": width === "1/2",
    "md:col-span-8": width === "2/3",
    "md:col-span-12": !["1/3", "1/2", "2/3"].includes(width),
  };
}

function localeContentSource(loc) {
  const entry = fullEntry.value;
  if (!entry) return null;
  return entryForLocale(entry, loc, defaultLocale.value);
}

function syncLocaleFromDefault(loc) {
  if (isReadOnly.value) return;
  localeMenu.value = null;
  if (!fullEntry.value || loc === defaultLocale.value) return;

  const source = localeContentSource(defaultLocale.value);
  if (!source) {
    toast.error(t("contentEdit.defaultUnavailable"));
    return;
  }

  const next = {
    ...form.value,
    title: source.title ?? "",
    locale: loc,
  };

  for (const name of Object.keys(customSchema.value)) {
    next[name] = Object.prototype.hasOwnProperty.call(source, name)
      ? fieldValueForForm(source[name], customSchema.value[name])
      : fieldDefaultValue(customSchema.value[name]);
  }

  currentLocale.value = loc;
  form.value = next;
  toast.success(t("contentEdit.contentSynced"));
}

async function confirmDeleteTranslation() {
  const loc = pendingDeleteLocale.value;
  if (!loc || !entryId.value) return;

  deletingTranslation.value = true;
  try {
    const res = await api.content.deleteTranslation(
      collection,
      entryId.value,
      loc,
    );
    fullEntry.value = res.data;
    toast.success(t("contentEdit.localeDeleted", { locale: loc }));

    // Switch to default locale
    currentLocale.value = defaultLocale.value;
    populateFormFromLocale(fullEntry.value, defaultLocale.value);
  } catch (err) {
    toast.error(err.message ?? t("contentEdit.deleteLocaleFailed"));
  } finally {
    deletingTranslation.value = false;
    showDeleteTranslationModal.value = false;
    pendingDeleteLocale.value = "";
  }
}
const formLoaded = ref(false);
const newEntryBaseline = ref(null);
const isDirty = computed(() => {
  if (!formLoaded.value) return false;
  if (parentUid.value !== parentBaseline.value) return true;
  if (pageTemplate.value !== pageTemplateBaseline.value) return true;
  const base = liveEntryBeforeRestore.value ?? fullEntry.value;
  const drafts = {
    ...localeDrafts.value,
    [currentLocale.value]: { form: form.value, resets: resetFields.value },
  };
  return hasEntryDraftChanges(
    drafts,
    (language) => base ? formFromEntry(base, language) : newEntryBaseline.value,
    (language) => !isNew.value && contentTypeLocales.value.length > 0 && !base?.publication?.[language],
  );
});
const {
  leavePromptOpen,
  confirmLeave,
  bypass: bypassLeaveGuard,
} = useUnsavedChangesGuard(isDirty);
const entryMeta = ref({ created_at: null, updated_at: null, id: null });
const contentTypeSchema = ref(null);

const customSchema = ref({});
const fieldErrors = ref({});
const loadError = ref("");
const showDeleteModal = ref(false);
const deleting = ref(false);
const deletingTranslation = ref(false);
const showDeleteTranslationModal = ref(false);
const pendingDeleteLocale = ref("");
const localeMenu = ref(null);
const saving = ref(false);
const saveError = ref("");
const showRevisions = ref(false);
const revisions = ref([]);
const loadingRevisions = ref(false);
const revisionError = ref("");
const previewingRevision = ref(null);

const hasCustomFields = computed(
  () => Object.keys(customSchema.value).length > 0,
);

// Website preview and live links; absent when the website is off.
const siteLinks = ref(null);
const fullPreview = useSitePreview();
async function openWebsitePreview() {
  try {
    await fullPreview.open({
      target: "entry", type: collection, entry: isNew.value ? "" : entryId.value, locale: currentLocale.value,
      entries: canSaveEntry.value ? [{ type: collection, id: isNew.value ? "" : entryId.value, values: payloadForSave() }] : [],
    });
  } catch (error) {
    toast.error(error.message);
  }
}

// The type's page layout in the builder, previewed with this entry.
const designLink = computed(() => ({
  path: `/page-builder/builder/layout/${collection}/detail`,
  // Builders name entries by stable ID; slugs differ between languages.
  query: { entry: fullEntry.value?.uid || entryId.value, back: route.fullPath },
}));

/** Templates the entry can choose; the one applying now explains "Automatic". */
const templateOptions = computed(() => (siteLinks.value?.templates ?? []).map((template) => ({ id: template.id, name: templateName(t, template) })));
const templateHint = computed(() => {
  const applied = siteLinks.value?.template;
  if (pageTemplate.value) return t("contentEdit.templatePicked");
  if (!applied) return "";
  return t("contentEdit.templateUses", { name: templateName(t, applied), reason: reasonLabel(t, applied.reason, applied, contentTypeSchema.value) });
});

/** The type's page fields and whether this entry has page content of its own in each. */
const pageFields = computed(() => Object.entries(contentTypeSchema.value?.fields ?? {})
  .filter(([, config]) => config?.type === "page")
  .map(([key, config]) => ({
    key,
    label: !config.label || config.label === "Page content" || config.label === "site.page.fieldLabel"
      ? t("site.page.fieldLabel")
      : resolveLocalized(config.label, currentLocale.value || defaultLocale.value, defaultLocale.value) || t("site.page.fieldLabel"),
    customized: (fullEntry.value?.values?.[key]?.nodes?.length ?? 0) > 0,
  })));

/**
 * Page fields with an area in the template this entry uses: only those show on the website.
 * Without any, the design action opens the shared template instead.
 */
const pageAreas = computed(() => {
  const placed = siteLinks.value?.page_fields ?? [];
  return pageFields.value.filter((field) => placed.includes(field.key));
});

// A single action panel follows the selected content area.
const selectedPageArea = ref("");
const activePageArea = computed(() => pageAreas.value.find((field) => field.key === selectedPageArea.value) ?? pageAreas.value[0]);

function pageLink(field) {
  return {
    path: `/page-builder/builder/page/${collection}/${fullEntry.value?.uid || entryId.value}`,
    query: { field, back: route.fullPath },
  };
}

const pendingPageReset = ref("");
const resettingPage = ref(false);

/** Back to the layout's default content; the entry's other unsaved edits stay in the form. */
async function resetPage() {
  const field = pendingPageReset.value;
  if (!field || resettingPage.value || !fullEntry.value) return;
  resettingPage.value = true;
  try {
    const res = await api.site.resetPage(collection, fullEntry.value.uid || entryId.value, field, fullEntry.value.revision);
    fullEntry.value = { ...fullEntry.value, revision: res.data.revision, values: { ...fullEntry.value.values, [field]: null } };
    // Saving the form while a restored version is shown builds on the live entry.
    if (liveEntryBeforeRestore.value) liveEntryBeforeRestore.value = { ...liveEntryBeforeRestore.value, revision: res.data.revision };
    pendingPageReset.value = "";
    toast.success(t("site.page.resetDone"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    resettingPage.value = false;
  }
}

let siteLinksRequest = 0;
async function loadSiteLinks() {
  const request = ++siteLinksRequest;
  if (isNew.value) {
    try {
      const status = (await api.site.status()).data;
      if (request === siteLinksRequest) siteLinks.value = { previewable: !!status?.types?.[collection]?.routed };
    } catch {
      if (request === siteLinksRequest) siteLinks.value = null;
    }
    return;
  }
  if (!entryId.value) return;

  try {
    const res = await api.site.links(collection, entryId.value, currentLocale.value ? { locale: currentLocale.value } : {});
    if (request === siteLinksRequest) siteLinks.value = res.data;
  } catch {
    if (request === siteLinksRequest) siteLinks.value = null;
  }
}

watch(
  () => [entryId.value, currentLocale.value, fullEntry.value?.status, fullEntry.value?.updated_at],
  loadSiteLinks,
);

onMounted(async () => {
  document.addEventListener("pointerdown", closeLocaleMenu);
  document.addEventListener("keydown", onLocaleMenuKeydown);

  loadUsers();
  loadSiteLinks();

  try {
    const typeRes = await api.contentTypes.get(collection);
    contentTypeSchema.value = typeRes.data;
    const builtins = new Set(["title", "slug"]);
    // Page content is designed in the page builder, not edited as a form field.
    customSchema.value = Object.fromEntries(
      Object.entries(typeRes.data.fields ?? {}).filter(
        ([k, config]) => !builtins.has(k) && config?.type !== "page",
      ),
    );
    contentTypeLocales.value = typeRes.data.locales ?? [];
    if (typeRes.data.hierarchical) {
      loadSiblingPages();
      if (isNew.value && typeof route.query.parent === "string") parentUid.value = parentBaseline.value = route.query.parent;
    }

    if (isNew.value) {
      if (defaultLocale.value) {
        form.value.locale = contentTypeLocales.value.includes(route.query.locale) ? route.query.locale : defaultLocale.value;
        currentLocale.value = form.value.locale;
      }
    }

    form.value = applyFieldDefaults(form.value);
  } catch {
    // Field defaults are best-effort; the entry load below still shows API errors.
  }

  if (!isNew.value) {
    try {
      const res = await api.content.get(collection, entryId.value);
      const entry = res.data;
      fullEntry.value = entry;
      entryMeta.value = {
        created_at: entry.created_at ?? null,
        updated_at: entry.updated_at ?? null,
        id: entry.id ?? null,
      };

      const initLocale =
        contentTypeLocales.value.includes(route.query.locale) ? route.query.locale
          : contentTypeLocales.value.includes(editorLanguages.activeLocale.value) ? editorLanguages.activeLocale.value : defaultLocale.value;
      currentLocale.value = initLocale;

      form.value = formFromEntry(entry, initLocale);
      parentUid.value = parentBaseline.value = entry.parent || "";
      pageTemplate.value = pageTemplateBaseline.value = entry.page_template || "";
    } catch (err) {
      loadError.value = err.message;
    }
  }

  await nextTick();
  newEntryBaseline.value = cloneLocaleValue(form.value);
  formLoaded.value = true;
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", closeLocaleMenu);
  document.removeEventListener("keydown", onLocaleMenuKeydown);
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

watch(
  [apiEndpointUrl, () => contentTypeSchema.value?.api_enabled],
  ([url, enabled]) => {
    if (!enabled) {
      apiEndpointStore.clearEndpoint(apiEndpointOwner);
      return;
    }
    apiEndpointStore.setEndpoint(
      {
        label: isNew.value ? "Collection" : "Entry",
        url,
      },
      apiEndpointOwner,
    );
  },
  { immediate: true },
);

function saveDraft() {
  if (entryForm.value?.reportValidity()) handleSave();
}

async function handleSave(publish = false) {
  if (!canSaveEntry.value || saving.value) return;
  const values = cloneLocaleValue(form.value);
  if (publish) values.status = "published";
  saveError.value = "";
  fieldErrors.value = {};
  saving.value = true;

  try {
    if (isNew.value) {
      const res = await api.content.create(collection, {
        ...payloadForSave(values),
        ...(contentTypeSchema.value?.hierarchical ? { parent: parentUid.value } : {}),
      });
      parentBaseline.value = parentUid.value;
      toast.success(t("contentEdit.entryCreated"));
      form.value.status = values.status;
      newEntryBaseline.value = cloneLocaleValue(form.value);
      router.push(`/content/${collection}/${res.data.uid || res.data.id}`);
    } else {
      const drafts = { ...localeDrafts.value, [currentLocale.value]: { form: values, resets: [...resetFields.value] } };
      let latest = liveEntryBeforeRestore.value ?? fullEntry.value;
      for (const [language, draft] of Object.entries(drafts)) {
        const payload = payloadForSave(draft.form, language, draft.resets, latest);
        const changed = Object.keys(payload).some((key) => !['locale', 'revision', 'reset_fields'].includes(key)) || payload.reset_fields.length || !latest.publication?.[language];
        if (!changed) continue;
        try {
          latest = (await api.content.update(collection, latest.uid || latest.id, payload)).data;
          fullEntry.value = latest;
          delete localeDrafts.value[language];
        } catch (error) {
          switchLocale(language);
          throw error;
        }
      }
      if (contentTypeSchema.value?.hierarchical && parentUid.value !== (latest.parent || "")) {
        latest = (await api.content.update(collection, latest.uid || latest.id, {
          parent: parentUid.value, locale: currentLocale.value || undefined, revision: latest.revision,
        })).data;
        fullEntry.value = latest;
      }
      parentBaseline.value = parentUid.value = latest.parent || "";
      if (pageTemplate.value !== (latest.page_template || "")) {
        latest = (await api.content.update(collection, latest.uid || latest.id, {
          page_template: pageTemplate.value, locale: currentLocale.value || undefined, revision: latest.revision,
        })).data;
        fullEntry.value = latest;
      }
      pageTemplateBaseline.value = pageTemplate.value = latest.page_template || "";
      const res = { data: latest };
      localeDrafts.value = {};
      toast.success(t("contentEdit.entrySaved"));
      fullEntry.value = res.data;
      delete localeDrafts.value[currentLocale.value];
      resetFields.value = [];
      liveEntryBeforeRestore.value = null;
      previewingRevision.value = null;
      populateFormFromEntry(res.data, currentLocale.value);
      entryMeta.value = {
        created_at: res.data.created_at ?? null,
        updated_at: res.data.updated_at ?? null,
        id: res.data.id ?? null,
      };
      if (res.data.id && (res.data.uid || res.data.id) !== entryId.value) {
        router.replace(`/content/${collection}/${res.data.uid || res.data.id}`);
      }
    }
  } catch (err) {
    saveError.value = errorMessage(err);
    fieldErrors.value = err.fields ?? {};
    toast.error(saveError.value);
  } finally {
    saving.value = false;
  }
}

async function openRevisions() {
  showRevisions.value = true;
  revisionError.value = "";
  loadingRevisions.value = true;

  try {
    const res = await api.content.revisions(collection, entryId.value);
    revisions.value = res.data ?? [];
  } catch (err) {
    revisionError.value = err.message;
  } finally {
    loadingRevisions.value = false;
  }
}

function restoreRevision(revision) {
  if (!canSaveEntry.value) return;
  const entry = revision.entry ?? revision;
  const restoreLocale =
    contentTypeLocales.value.length > 0
      ? currentLocale.value || defaultLocale.value
      : "";

  liveEntryBeforeRestore.value ??= fullEntry.value;
  fullEntry.value = entry;
  currentLocale.value = restoreLocale;
  populateFormFromEntry(entry, restoreLocale);
  previewingRevision.value = revision;
  showRevisions.value = false;
  toast.success(t("contentEdit.revisionLoaded"));
}

function payloadForSave(values = form.value, language = currentLocale.value, resets = resetFields.value, base = liveEntryBeforeRestore.value ?? fullEntry.value) {
  const payload = { ...values, revision: base?.revision, reset_fields: [...resets] };
  for (const name of resets) delete payload[name];
  for (const [name, config] of Object.entries(customSchema.value)) {
    if (config.localized === false && language !== defaultLocale.value) delete payload[name];
    if (base && config.localized !== false && !resets.includes(name)) {
      const previous = entryForLocale(base, language, defaultLocale.value)[name];
      if (JSON.stringify(fieldValueForForm(previous ?? null, config)) === JSON.stringify(payload[name])) delete payload[name];
    }
  }
  if (base && payload.title === entryForLocale(base, language, defaultLocale.value).title) delete payload.title;
  payload.published_at = localDatetimeToUtcIso(payload.published_at);

  for (const [fieldName, config] of Object.entries(customSchema.value)) {
    if (
      config?.type === "datetime" &&
      Object.prototype.hasOwnProperty.call(payload, fieldName)
    ) {
      payload[fieldName] = localDatetimeToUtcIso(payload[fieldName]);
    }
  }

  if (base) {
    const source = entryForLocale(base, language, defaultLocale.value);
    for (const key of Object.keys(payload)) {
      if (['locale', 'revision', 'reset_fields'].includes(key)) continue;
      if (JSON.stringify(payload[key]) === JSON.stringify(source[key] ?? null)) delete payload[key];
    }
  }
  if (language !== defaultLocale.value) delete payload.author_id;
  return payload;
}

/** A failed save names the fields it is about, so they can be found even where the form has no place for the message. */
function errorMessage(err) {
  const fields = Object.entries(err.fields ?? {});
  if (!fields.length) return err.message;
  const label = (name) => {
    if (["title", "slug", "status", "parent"].includes(name)) return t(`contentEdit.${name}`);
    if (name === "published_at") return t("contentEdit.publishedAt");
    const config = contentTypeSchema.value?.fields?.[name];
    return resolveLocalized(config?.label, currentLocale.value || defaultLocale.value, defaultLocale.value) || name;
  };
  return fields.map(([name, message]) => `${label(name)}: ${message}`).join(" ");
}

function fieldValueForForm(value, config) {
  if (config?.type === "datetime" && value) {
    return toDatetimeLocal(value);
  }

  return cloneLocaleValue(value);
}

async function cancelRestore() {
  const restoreLocale =
    contentTypeLocales.value.length > 0
      ? currentLocale.value || defaultLocale.value
      : "";
  let currentEntry = liveEntryBeforeRestore.value;

  if (!isNew.value && entryId.value) {
    try {
      const res = await api.content.get(collection, entryId.value);
      currentEntry = res.data;
    } catch {
      // Fall back to the saved entry snapshot from before the revision preview.
    }
  }

  if (currentEntry) {
    fullEntry.value = currentEntry;
    populateFormFromEntry(currentEntry, restoreLocale);
  }

  localeDrafts.value = {};
  resetFields.value = [];
  liveEntryBeforeRestore.value = null;
  previewingRevision.value = null;
}

function applyFieldDefaults(source) {
  const next = { ...source };

  for (const [fieldName, config] of Object.entries(customSchema.value)) {
    if (next[fieldName] === undefined) {
      next[fieldName] = fieldDefaultValue(config);
    } else if (config?.type === "repeater" && Array.isArray(next[fieldName])) {
      next[fieldName] = next[fieldName].map((row) =>
        applySubfieldDefaults(row, config.subfields ?? []),
      );
    }
  }

  return next;
}

function applySubfieldDefaults(row, subfields) {
  const next = { ...(row ?? {}) };

  for (const subfield of subfields) {
    if (!subfield?.key || next[subfield.key] !== undefined) continue;
    next[subfield.key] = fieldDefaultValue(subfield);
  }

  return next;
}

const REVISION_SKIP = new Set([
  "id",
  "collection",
  "created_at",
  "updated_at",
  "author_id",
  "deleted_at",
  "deleted_by",
  "updated_by",
  "data",
  "values", "publication", "revision",
]);

const currentVsFirstDiff = computed(() => {
  if (revisions.value.length === 0) return [];
  const current = flatRevisionEntry(form.value, currentLocale.value);
  const previous = flatRevisionEntry(
    revisions.value[0]?.entry,
    currentLocale.value,
  );
  const allKeys = new Set([...Object.keys(current), ...Object.keys(previous)]);
  const changes = [];
  for (const key of allKeys) {
    // The form never holds page content; it is saved from the page builder.
    if (pageFields.value.some((field) => field.key === key)) continue;
    if (
      JSON.stringify(normDiffVal(current[key])) !==
      JSON.stringify(normDiffVal(previous[key]))
    ) {
      changes.push({ field: key, from: previous[key], to: current[key] });
    }
  }
  return changes;
});

function flatRevisionEntry(entry, loc = "") {
  const source = revisionFieldSource(entry, loc);
  const out = {};
  for (const [k, v] of Object.entries(source)) {
    if (!REVISION_SKIP.has(k)) out[k] = v;
  }
  return out;
}

function revisionDiff(index) {
  const current = flatRevisionEntry(
    revisions.value[index]?.entry,
    currentLocale.value,
  );
  const previous = flatRevisionEntry(
    revisions.value[index + 1]?.entry,
    currentLocale.value,
  );
  const allKeys = new Set([...Object.keys(current), ...Object.keys(previous)]);
  const changes = [];
  for (const key of allKeys) {
    if (
      JSON.stringify(normDiffVal(current[key])) !==
      JSON.stringify(normDiffVal(previous[key]))
    ) {
      changes.push({ field: key, from: previous[key], to: current[key] });
    }
  }
  return changes;
}

function revisionFieldSource(entry, loc = "") {
  return entryForLocale(entry, loc || currentLocale.value || defaultLocale.value, defaultLocale.value);
}

function normDiffVal(v) {
  return v == null || v === "" ? null : v;
}

function formatRevVal(v) {
  if (v == null || v === "") return t("contentEdit.empty");
  if (typeof v === "boolean") return v ? "true" : "false";
  if (Array.isArray(v))
    return `[${t("contentEdit.items", { count: v.length })}]`;
  if (Array.isArray(v.nodes)) return t("site.page.own");
  if (typeof v === "object") return "{…}";
  const s = String(v);
  return s.length > 35 ? s.slice(0, 35) + "…" : s;
}

async function handleDelete() {
  if (!canDeleteEntry.value) return;
  try {
    deleting.value = true;
    await api.content.delete(collection, entryId.value);
    toast.success(t("contentEdit.entryMovedTrash"));
    bypassLeaveGuard();
    router.push(`/content/${collection}`);
  } catch (err) {
    saveError.value = err.message;
    toast.error(err.message);
    showDeleteModal.value = false;
  } finally {
    deleting.value = false;
  }
}

function toDatetimeLocal(iso) {
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function localDatetimeToUtcIso(value) {
  if (!value) return value ?? "";
  const date = new Date(String(value));
  if (Number.isNaN(date.getTime())) return value;
  return date.toISOString().replace(/\.\d{3}Z$/, "Z");
}

function formatDateTime(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
</script>

<style scoped src="../components/site/pageBuilderCta.css"></style>
