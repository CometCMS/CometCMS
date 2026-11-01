import { computed } from 'vue'
import { useAuthStore } from '../stores/auth.js'
import { useContentTypesStore } from '../stores/contentTypes.js'
import { useSiteStore } from '../stores/site.js'
import { useI18n } from '../i18n/index.js'

const DEFAULT_COLLECTION_ICON = 'mdi:file-document-outline'

export function contentTypeIcon(type) {
  return type?.icon || DEFAULT_COLLECTION_ICON
}

/**
 * True when `path` is `prefix` itself or a page below it, so editing an entry
 * keeps its content type highlighted. `/content/pages` does not match
 * `/content/pages-archive`.
 */
export function pathMatches(path, prefix) {
  return path === prefix || path.startsWith(prefix.endsWith('/') ? prefix : `${prefix}/`)
}

/**
 * A section with sub-pages. Its `match` covers all children, and a section left
 * with a single child (missing permissions) becomes that child's plain link.
 */
function section(item, children) {
  const visible = children.filter(Boolean)
  if (visible.length === 0) return null
  if (visible.length === 1) return { ...visible[0], icon: item.icon }
  return { ...item, to: visible[0].to, match: [...(item.match ?? []), ...visible.flatMap((child) => child.match)], children: visible }
}

/**
 * The admin's information architecture in one place: the sidebar and the
 * command palette both render these groups. Groups run from daily editing
 * (content) to rare setup work (administration).
 *
 * Each item: { key, label, icon, to, match: [path prefixes], children? }.
 * Children are { key, label, to, match } and mirror the tabs of their page.
 * Descriptions clarify setup destinations without adding noise to content links.
 */
export function useNavigation() {
  const auth = useAuthStore()
  const typesStore = useContentTypesStore()
  const site = useSiteStore()
  const { t } = useI18n()

  const allTypes = computed(() =>
    typesStore.list.length > 0 ? typesStore.list : (auth.user?.accessible_content_types ?? []),
  )

  const contentTypes = computed(() =>
    allTypes.value.filter((type) => auth.can('content.read', `content:${type.name}:*`)),
  )

  const showPageBuilder = computed(() => auth.can('site.manage') && site.pageBuilderActive)

  const groups = computed(() => {
    const result = [
      {
        key: 'main',
        items: [
          { key: 'dashboard', label: t('app.nav.dashboard'), icon: 'mdi:view-dashboard-outline', to: '/dashboard', match: ['/dashboard'] },
        ],
      },
    ]

    result.push({
      key: 'content',
      label: t('app.nav.content'),
      items: [
        ...contentTypes.value.map((type) => ({
          key: `content:${type.name}`,
          label: type.label,
          icon: contentTypeIcon(type),
          to: `/content/${type.name}`,
          match: [`/content/${type.name}`, `/trash/${type.name}`],
          type,
        })),
        { key: 'media', label: t('app.nav.media'), icon: 'mdi:image-multiple-outline', to: '/media', match: ['/media'] },
      ],
    })

    const website = []
    if (showPageBuilder.value) {
      website.push(section({ key: 'page-builder', label: t('app.nav.website'), description: t('app.nav.designHint'), icon: 'mdi:palette-outline', match: ['/page-builder'] }, [
        { key: 'website-design', label: t('site.builder.designSettings'), to: '/page-builder/design', match: ['/page-builder/design'] },
        { key: 'website-templates', label: t('site.templates.title'), to: '/page-builder/templates', match: ['/page-builder/templates'] },
        { key: 'website-menus', label: t('site.tab.menus'), to: '/page-builder/menus', match: ['/page-builder/menus'] },
        { key: 'website-components', label: t('site.component.group'), to: '/page-builder/components', match: ['/page-builder/components'] },
        { key: 'website-fonts', label: t('site.tab.fonts'), to: '/page-builder/fonts', match: ['/page-builder/fonts'] },
      ]))
    }
    if (auth.can('site.manage')) {
      website.push(section({ key: 'website-settings', label: t('app.nav.websiteSettings'), description: t('app.nav.websiteSettingsHint'), icon: 'mdi:cog-outline' }, [
        { key: 'website-general', label: t('website.tab.general'), to: '/website/general', match: ['/website/general'] },
        { key: 'website-seo', label: t('website.tab.seo'), to: '/website/seo', match: ['/website/seo'] },
        site.data?.types?.some((type) => type.allow_submissions) && { key: 'website-forms', label: t('website.tab.forms'), to: '/website/forms', match: ['/website/forms'] },
        { key: 'website-extensions', label: t('site.extensions.title'), to: '/website/extensions', match: ['/website/extensions'] },
      ]))
    }
    if (website.length > 0) {
      result.push({ key: 'website', label: t('app.nav.websiteGroup'), items: website })
    }

    const admin = []
    if (auth.can('schema.read')) {
      admin.push({ key: 'content-types', label: t('app.nav.contentTypes'), description: t('app.nav.contentTypesHint'), icon: 'mdi:shape-outline', to: '/content-types', match: ['/content-types'] })
    }
    admin.push(section({ key: 'connect', label: t('app.nav.connect'), description: t('app.nav.connectHint'), icon: 'mdi:connection', match: ['/connect'] }, [
      auth.can('tokens.read') && { key: 'connect-tokens', label: t('connect.accessTokens'), to: '/connect/access-tokens', match: ['/connect/access-tokens'] },
      { key: 'connect-api', label: t('connect.api'), to: '/connect/api', match: ['/connect/api'] },
      { key: 'connect-mcp', label: t('connect.mcp'), to: '/connect/mcp', match: ['/connect/mcp'] },
      auth.can('webhooks.manage') && { key: 'connect-webhooks', label: t('connect.webhooks'), to: '/connect/webhooks', match: ['/connect/webhooks'] },
    ]))
    admin.push(
      section({ key: 'users', label: t('app.nav.usersRoles'), description: t('app.nav.usersRolesHint'), icon: 'mdi:account-group-outline' }, [
        auth.can('users.read') && { key: 'users-list', label: t('app.nav.users'), to: '/users', match: ['/users'] },
        auth.can('roles.read') && { key: 'roles', label: t('app.nav.roles'), to: '/roles', match: ['/roles'] },
      ]),
    )
    if (auth.can('workspaces.manage')) {
      admin.push({ key: 'workspaces', label: t('workspaces.title'), icon: 'mdi:folder-multiple-outline', to: '/website/workspaces', match: ['/website/workspaces'] })
    }
    if (auth.can('backups.read')) {
      admin.push({ key: 'backups', label: t('app.nav.backupRestore'), icon: 'mdi:backup-restore', to: '/backups', match: ['/backups'] })
    }
    if (auth.can('updates.check')) {
      admin.push({ key: 'updates', label: t('app.nav.updates'), icon: 'mdi:update', to: '/update', match: ['/update'] })
    }
    result.push({ key: 'admin', label: t('app.nav.administration'), items: admin.filter(Boolean) })

    return result.filter((group) => group.items.length > 0)
  })

  function isActive(item, path) {
    // Bare section URLs render the default tab, without claiming sibling routes.
    const defaults = { '/website': '/website/general', '/page-builder': '/page-builder/design', '/connect': '/connect/access-tokens' }
    return item.match.some((prefix) => pathMatches(defaults[path] ?? path, prefix))
  }

  return { groups, contentTypes, allTypes, isActive }
}
