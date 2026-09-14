import type { AxiosInstance } from 'axios'
import type { Component } from 'vue'
import type { RouteLocationRaw, Router } from 'vue-router'

export type ExtensionVisibilityPredicate = () => boolean

export interface ExtensionContribution {
  /** A module-stable identifier. Registering the same id replaces the prior contribution. */
  id: string
  /** Lower values render first. Defaults to 100. */
  priority?: number
  /** Return false to omit this contribution without unregistering it. */
  visible?: ExtensionVisibilityPredicate
}

export interface ComponentExtensionContribution extends ExtensionContribution {
  component: Component
  props?: Record<string, unknown>
}

export interface RichEditorContext {
  getHtml(): string
  insertContent(content: string): void
  replaceContent(content: string): void
}

export interface SettingsNavigationContribution extends ExtensionContribution {
  title: string
  icon: string
  to: RouteLocationRaw
}

export interface SettingsPageContribution
  extends Omit<SettingsNavigationContribution, 'to'> {
  /** A child path below the relevant settings route, without a leading slash. */
  path: string
  component: Component
  meta?: Record<string, unknown>
}

export interface PageRouteMeta {
  /** Namespaced ability id(s) checked by the host route guard, e.g. 'tasks-projects:view-project'. */
  ability?: string | string[]
  /** i18n key for the page title. */
  title?: string
  [key: string]: unknown
}

export interface PageChildContribution {
  id: string
  /** Relative to the parent page, without a leading slash. '' is the index child. */
  path: string
  component: Component
  meta?: PageRouteMeta
}

export interface PageContribution extends PageChildContribution {
  /** The module.json slug. The page mounts at /admin/modules/{module}/{path}; 'settings' is reserved by the host. */
  module: string
  children?: PageChildContribution[]
}

export interface BootstrapCompletedEvent {
  adminMode: boolean
  companyId: number | null
}

export interface CompanyChangeEvent {
  previousCompanyId: number | null
  companyId: number | null
}

export interface InvoiceShelfExtensionEvents {
  'bootstrap:completed': BootstrapCompletedEvent
  'company:changing': CompanyChangeEvent
  'company:changed': CompanyChangeEvent
}

export interface InvoiceShelfExtensionApi {
  readonly client: AxiosInstance
  readonly router: Router
  registerHeaderAction(contribution: ComponentExtensionContribution): () => void
  registerCompanyLayoutOverlay(contribution: ComponentExtensionContribution): () => void
  registerRichEditorToolbarAction(contribution: ComponentExtensionContribution): () => void
  registerCompanySettingsNavigation(contribution: SettingsNavigationContribution): () => void
  registerAdminSettingsNavigation(contribution: SettingsNavigationContribution): () => void
  registerCompanySettingsPage(contribution: SettingsPageContribution): () => void
  registerAdminSettingsPage(contribution: SettingsPageContribution): () => void
  registerPage(contribution: PageContribution): () => void
  addMessages(messages: Record<string, Record<string, unknown>>): void
  notify(type: 'success' | 'error' | 'warning' | 'info', message: string): void
  on<EventName extends keyof InvoiceShelfExtensionEvents>(
    event: EventName,
    listener: (payload: InvoiceShelfExtensionEvents[EventName]) => void,
  ): () => void
  /** Remove all registered UI and dynamically-added routes. Useful before a host reload. */
  reset(): void
}
