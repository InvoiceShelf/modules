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

/**
 * Host records a module can attach actions, panels and form sections to. Not
 * every entity has every slot; the SDK README lists where each one renders.
 */
export type ExtensionEntity =
  | 'invoice'
  | 'estimate'
  | 'recurring-invoice'
  | 'customer'
  | 'payment'
  | 'expense'
  | 'item'

/**
 * Where an entity action renders: in each list row's menu, on the record's own
 * page, or in the header of its create and edit form.
 */
export type EntityActionPlacement = 'list' | 'detail' | 'form'

/**
 * A read-only snapshot of a host record, built for modules: plain values under
 * the host API's field names, frozen, never a live store object. A field that
 * does not apply to the entity is null. Amounts are in the currency's minor
 * units, as the host API returns them. Load anything else by `id` through
 * `client`.
 */
export interface EntityRecord {
  readonly entity: ExtensionEntity
  readonly id: number
  /** The document number (invoice, estimate, payment number), or the name of a customer or item. */
  readonly number: string | null
  readonly status: string | null
  readonly paid_status: string | null
  /** The invoice type, such as `CREDIT_NOTE`. */
  readonly type: string | null
  readonly customer_id: number | null
  readonly total: number | null
  readonly due_amount: number | null
  readonly currency_code: string | null
  readonly related_invoice_id: number | null
}

/** Handed as the `context` prop to list and detail actions and to panels. */
export interface EntityContext {
  readonly entity: ExtensionEntity
  readonly placement: 'list' | 'detail'
  record(): EntityRecord | null
  can(ability: string | string[]): boolean
  /** Reload the record in the host view, for example after the module changed it through the API. */
  refresh(): void | Promise<void>
}

/** Handed as the `context` prop to form actions and form sections, and to save hooks. */
export interface EntityFormContext {
  readonly entity: ExtensionEntity
  isEdit(): boolean
  /** The saved record while editing; null for a new one. */
  record(): EntityRecord | null
  can(ability: string | string[]): boolean
  /** Document forms (invoice, estimate) only; false elsewhere. */
  isRecurring(): boolean
  /** Document forms only; null elsewhere. */
  total(): number | null
  currencyCode(): string | null
  date(): string | null
  dueDate(): string | null
}

/**
 * Runs after the host saved the record, on create and on update, in priority
 * order. Return true to take over the next navigation (the first hook that
 * does wins); false or nothing keeps the host's. A hook that throws or rejects
 * is logged and reported to the user as a warning: the record stays saved and
 * the host navigates as usual, so the user is never invited to save twice.
 */
export interface EntitySaveHookContribution extends ExtensionContribution {
  handle(saved: EntityRecord, context: EntityFormContext): boolean | void | Promise<boolean | void>
}

/** An entry in the global New menu and the command palette. */
export interface CreateActionContribution extends ExtensionContribution {
  /** A translation key. */
  label: string
  icon: string
  to: RouteLocationRaw
  ability?: string | string[]
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

/**
 * Running inside a thin client
 *
 * The same bundle runs in a browser served by its own server, and inside the
 * iOS and Android clients, which are a static app package pointed at whichever
 * server the user signed in to. A module that follows the SDK works in both.
 * Four rules are what make that true.
 *
 * 1. Call the API only through `extensions.client`. Never a bare `fetch`, never
 *    an axios instance of your own. Only the host client carries the server
 *    base URL and the bearer token, so in a client `fetch('/api/v1/...')`
 *    resolves against the app package, where there is no server and no
 *    credentials to send.
 * 2. Never hardcode `/api` or `/storage` as a root-relative URL in a template
 *    or in code. Root-relative is not server-relative in a client: the root is
 *    the app package. A path handed to `extensions.client` is fine, because the
 *    host client resolves it against the server. For anything else, such as a
 *    stored file, the host exposes no asset-URL helper today, so ask for one
 *    rather than assembling a URL in the module.
 * 3. Ship self-contained CSS. The host's release bundle is built in CI with no
 *    `Modules/` directory present, so the host's Tailwind scan never sees your
 *    markup and cannot emit a class for it. Everything your markup needs must
 *    come out of your own compiled stylesheet. The host's reset and its theme
 *    custom properties are shared and may be relied on; a host utility class
 *    may not.
 * 4. Do not register a script or a style as a remote http(s) URL. Pass a local
 *    path inside the module to `Registry::registerScript` and
 *    `Registry::registerStyle`. An operator cannot add CORS headers to an
 *    origin they do not control, so the client manifest flags such a script as
 *    unsupported and clients skip it, which leaves the module working on the
 *    web and silently absent on mobile.
 */
export interface InvoiceShelfExtensionApi {
  /**
   * The host's axios instance: server base URL, credentials, and the host's own
   * interceptors. Every request a module makes goes through it, in a browser and
   * in a thin client alike. See "Running inside a thin client" above.
   */
  readonly client: AxiosInstance
  readonly router: Router
  registerHeaderAction(contribution: ComponentExtensionContribution): () => void
  registerCompanyLayoutOverlay(contribution: ComponentExtensionContribution): () => void
  registerRichEditorToolbarAction(contribution: ComponentExtensionContribution): () => void
  /** Add an entry to the global New menu and the command palette. */
  registerCreateAction(contribution: CreateActionContribution): () => void
  /**
   * Add an action to a record: in each list row's menu (`'list'`), on its page
   * (`'detail'`) or in its form header (`'form'`). The component receives
   * `context`: an `EntityContext`, or an `EntityFormContext` for `'form'`.
   */
  registerEntityAction(
    entity: ExtensionEntity,
    placement: EntityActionPlacement,
    contribution: ComponentExtensionContribution,
  ): () => void
  /** Add a panel to a record's page, below the host's content. The component receives `context: EntityContext`. */
  registerEntityPanel(entity: ExtensionEntity, contribution: ComponentExtensionContribution): () => void
  /** Add a section to a record's create and edit form. The component receives `context: EntityFormContext`. */
  registerEntityFormSection(entity: ExtensionEntity, contribution: ComponentExtensionContribution): () => void
  /** Run after the host saved a record from its form. See `EntitySaveHookContribution`. */
  registerEntitySaveHook(entity: ExtensionEntity, contribution: EntitySaveHookContribution): () => void
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
