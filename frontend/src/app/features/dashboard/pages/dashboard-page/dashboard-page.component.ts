import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  Dashboard,
  DashboardWidget,
  SavedQuery,
  WidgetPlacement,
  WidgetTypeDefinition,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { describeApiError, hasProblemCode } from '../../../../core/errors/api-error';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { DashboardWidgetComponent } from '../../components/dashboard-widget/dashboard-widget.component';
import { WidgetEditorComponent } from '../../components/widget-editor/widget-editor.component';
import { WidgetSettingsComponent } from '../../components/widget-settings/widget-settings.component';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';

/** A widget with the place it currently occupies — the draft one while editing. */
interface LayoutCell {
  readonly widget: DashboardWidget;
  readonly placement: WidgetPlacement;
}

const GRID_COLUMNS = 12;

/**
 * One personal dashboard: the switcher, the grid, and the explicit edit mode.
 *
 * The identifier stays in the route because that is what people bookmark and
 * share; the bare path is resolved before this screen is reached.
 *
 * Switching writes the "last active" preference, and plain navigation does not.
 * The server keeps that write out of `GET` on purpose — a prefetch or a link
 * preview must never move where somebody lands next — so re-issuing it on every
 * load would put the side effect straight back.
 *
 * Widgets fetch their own data. This page is done once it knows which widgets
 * exist; whether each has numbers yet is the widget's own business, which is
 * how one unreachable saved query fails to blank the page.
 *
 * **Editing is a mode, not a mood.** Nothing moves until somebody turns it on
 * (spec §7.3), moves are collected into a draft, and the whole arrangement is
 * written in a single request against the dashboard's version — because two
 * widgets passing each other is only legal as a pair. A version conflict is
 * *offered* back, never silently overwritten: another tab's work is not this
 * tab's to discard.
 */
@Component({
  selector: 'app-dashboard-page',
  standalone: true,
  imports: [
    DashboardWidgetComponent,
    ErrorStateComponent,
    PageHeaderComponent,
    RouterLink,
    TranslatePipe,
    WidgetEditorComponent,
    WidgetSettingsComponent,
  ],
  templateUrl: './dashboard-page.component.html',
  styleUrl: './dashboard-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardPageComponent implements OnInit {
  readonly dashboardId = input.required<string>();

  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly tenantStore = inject(TenantStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  /**
   * The failed request itself, not a message about it: the shared error state
   * decides what a `404`, a lost connection or a server fault should say, so
   * every screen tells the same story about the same status.
   */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly dashboards = signal<readonly Dashboard[]>([]);
  protected readonly widgets = signal<readonly DashboardWidget[]>([]);
  protected readonly widgetsLoading = signal(true);
  protected readonly version = signal(1);

  protected readonly editing = signal(false);
  /** The widget in flight while a pointer drag is running. */
  protected readonly draggedWidgetId = signal<string | null>(null);
  protected readonly adding = signal(false);
  protected readonly configuring = signal<DashboardWidget | null>(null);
  protected readonly saving = signal(false);
  protected readonly conflict = signal(false);
  protected readonly layoutError = signal<TranslationKey | null>(null);
  protected readonly types = signal<readonly WidgetTypeDefinition[]>([]);
  protected readonly queries = signal<readonly SavedQuery[]>([]);

  private readonly draft = signal<readonly WidgetPlacement[]>([]);

  protected readonly mayEdit = computed(() =>
    this.tenantStore.hasAnyPermission(['dashboard.update-own']),
  );

  protected readonly openDashboard = computed(
    () => this.dashboards().find((dashboard) => dashboard.id === this.dashboardId()) ?? null,
  );

  /**
   * Somebody else's dashboard answers `404`, so this one case keeps its own
   * sentence: it is absent, not denied, and "no longer available" is the honest
   * way to say that without confirming whose it is.
   */
  protected readonly loadErrorKey = computed<TranslationKey | null>(() => {
    const failure = this.loadFailure();

    return failure !== null && describeApiError(failure).status === 404
      ? 'dashboard.notFound'
      : null;
  });

  /**
   * Document order, and therefore the order a narrow screen falls back to: top
   * row first, then left to right, with the identifier settling ties. The
   * desktop grid places every widget by its own coordinates, so this ordering
   * never moves anything there — the mobile column is derived, not stored.
   */
  protected readonly cells = computed<readonly LayoutCell[]>(() => {
    const placements = new Map(this.draft().map((placement) => [placement.id, placement]));

    return this.widgets()
      .map((widget) => ({
        widget,
        placement: placements.get(widget.id) ?? {
          id: widget.id,
          x: widget.x,
          y: widget.y,
          width: widget.width,
          height: widget.height,
        },
      }))
      .sort(
        (left, right) =>
          left.placement.y - right.placement.y ||
          left.placement.x - right.placement.x ||
          left.widget.id.localeCompare(right.widget.id),
      );
  });

  /**
   * Overlap is the one rule that needs the whole set at once, so it is checked
   * here as well — not to replace the server's word, but so a move that cannot
   * be saved says so while it is still being made.
   */
  protected readonly overlapping = computed(() => {
    const placements = this.cells().map((cell) => cell.placement);

    return placements.some((placement, index) =>
      placements
        .slice(index + 1)
        .some(
          (other) =>
            placement.x < other.x + other.width &&
            other.x < placement.x + placement.width &&
            placement.y < other.y + other.height &&
            other.y < placement.y + placement.height,
        ),
    );
  });

  ngOnInit(): void {
    this.reload();
  }

  /** The whole screen again, which is what "try again" means here. */
  protected reload(): void {
    this.loadDashboards();
    this.loadWidgets();
  }

  protected open(dashboard: Dashboard): void {
    if (dashboard.id === this.dashboardId()) {
      return;
    }

    void this.router.navigate(['../', dashboard.id], { relativeTo: this.route });

    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    // Choosing a dashboard is exactly the explicit act the preference records.
    // A failure costs nothing visible: landing in the wrong place next time is
    // a smaller problem than an error banner over a working screen.
    this.workspace
      .markActive(tenantId, dashboard.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }

  protected startEditing(): void {
    this.editing.set(true);
    this.layoutError.set(null);
    this.conflict.set(false);
    this.resetDraft(this.widgets());
    this.loadEditingCatalogues();
  }

  protected stopEditing(): void {
    this.editing.set(false);
    this.adding.set(false);
    this.configuring.set(null);
    this.conflict.set(false);
    this.layoutError.set(null);
    // Dropping the draft is the whole point of Cancel: what the server holds
    // is what everybody else sees.
    this.draft.set([]);
  }

  protected move(cell: LayoutCell, dx: number, dy: number): void {
    this.placeAt(cell, cell.placement.x + dx, cell.placement.y + dy);
  }

  private placeAt(cell: LayoutCell, x: number, y: number): void {
    this.replace({
      ...cell.placement,
      // A widget cannot leave the grid sideways; downwards it can go as far as
      // it likes, because a dashboard scrolls.
      x: this.clamp(x, 0, GRID_COLUMNS - cell.placement.width),
      y: Math.max(0, y),
    });
  }

  /**
   * Pointer dragging, on top of the same draft the buttons write to.
   *
   * The buttons are the keyboard path and the one that has to exist; this adds
   * the gesture people reach for first. A drop is turned into a grid cell by
   * arithmetic on the grid's own box, so it lands where it looks like it landed,
   * and it goes through `placeAt` — the same clamping, the same draft, the same
   * single save against the dashboard's version.
   */
  protected startWidgetDrag(cell: LayoutCell, event: DragEvent): void {
    if (!this.editing()) {
      return;
    }

    this.draggedWidgetId.set(cell.widget.id);

    const transfer = event.dataTransfer;

    if (transfer) {
      transfer.setData('text/plain', cell.widget.id);
      transfer.effectAllowed = 'move';
    }
  }

  protected endWidgetDrag(): void {
    this.draggedWidgetId.set(null);
  }

  protected allowWidgetDrop(event: DragEvent): void {
    if (this.draggedWidgetId() === null) {
      return;
    }

    event.preventDefault();

    const transfer = event.dataTransfer;

    if (transfer) {
      transfer.dropEffect = 'move';
    }
  }

  protected dropWidget(event: DragEvent): void {
    const cell = this.cells().find((candidate) => candidate.widget.id === this.draggedWidgetId());
    this.endWidgetDrag();

    if (cell === undefined || !(event.currentTarget instanceof HTMLElement)) {
      return;
    }

    event.preventDefault();

    const target = this.gridCellAt(event.currentTarget, event.clientX, event.clientY);

    if (target !== null) {
      this.placeAt(cell, target.x, target.y);
    }
  }

  /**
   * The grid cell under the pointer, measured from the grid element itself
   * rather than from a copy of the stylesheet — a hard-coded row height would
   * be a second source of truth, free to disagree with the CSS.
   *
   * Below the single-column breakpoint there is no column to compute, and the
   * order is derived from the document there, so a drop is simply ignored.
   */
  private gridCellAt(
    grid: HTMLElement,
    clientX: number,
    clientY: number,
  ): { readonly x: number; readonly y: number } | null {
    const box = grid.getBoundingClientRect();
    const styles = getComputedStyle(grid);
    const columnGap = Number.parseFloat(styles.columnGap) || 0;
    const rowGap = Number.parseFloat(styles.rowGap) || 0;
    const rowHeight = Number.parseFloat(styles.gridAutoRows) || 0;
    const columns = styles.gridTemplateColumns.split(' ').filter((entry) => entry !== '').length;

    if (box.width <= 0 || rowHeight <= 0 || columns < GRID_COLUMNS) {
      return null;
    }

    const columnStep = (box.width + columnGap) / GRID_COLUMNS;
    const rowStep = rowHeight + rowGap;

    return {
      x: this.clamp(Math.floor((clientX - box.left) / columnStep), 0, GRID_COLUMNS - 1),
      y: Math.max(0, Math.floor((clientY - box.top) / rowStep)),
    };
  }

  protected resize(cell: LayoutCell, dw: number, dh: number): void {
    const definition = this.definitionOf(cell.widget);
    const width = this.clamp(
      cell.placement.width + dw,
      definition?.min_width ?? 1,
      Math.min(definition?.max_width ?? GRID_COLUMNS, GRID_COLUMNS - cell.placement.x),
    );

    this.replace({
      ...cell.placement,
      width,
      height: this.clamp(
        cell.placement.height + dh,
        definition?.min_height ?? 1,
        definition?.max_height ?? 12,
      ),
    });
  }

  protected saveLayout(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.saving() || this.overlapping()) {
      return;
    }

    this.apply(
      tenantId,
      this.cells().map((cell) => cell.placement),
    );
  }

  /**
   * The offer after a conflict: take the other tab's dashboard as it now is,
   * keep this tab's moves for the widgets that still exist, and send that. The
   * user asked for it explicitly by pressing the button — nothing was
   * overwritten behind their back.
   */
  protected reapplyLayout(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.saving()) {
      return;
    }

    this.saving.set(true);

    this.workspace
      .widgets(tenantId, this.dashboardId())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (widgets) => {
          const pending = new Map(this.draft().map((placement) => [placement.id, placement]));
          this.widgets.set(widgets);

          const merged = widgets.map((widget) => {
            const kept = pending.get(widget.id);

            // A widget the other tab added is placed where they put it; only
            // the ones this tab actually moved carry the draft position.
            return (
              kept ?? {
                id: widget.id,
                x: widget.x,
                y: widget.y,
                width: widget.width,
                height: widget.height,
              }
            );
          });

          this.draft.set(merged);
          this.refreshVersion(tenantId, () => this.apply(tenantId, merged));
        },
        error: () => {
          this.saving.set(false);
          this.layoutError.set('dashboard.edit.saveError');
        },
      });
  }

  protected removeWidget(cell: LayoutCell): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.layoutError.set(null);

    this.workspace
      .removeWidget(tenantId, this.dashboardId(), cell.widget.id)
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.widgets.set(this.widgets().filter((widget) => widget.id !== cell.widget.id));
          this.draft.set(this.draft().filter((placement) => placement.id !== cell.widget.id));

          // A form for a widget that no longer exists could only ever fail.
          if (this.configuring()?.id === cell.widget.id) {
            this.configuring.set(null);
          }

          this.refreshVersion(tenantId, () => undefined);
        },
        error: () => this.layoutError.set('dashboard.edit.removeError'),
      });
  }

  /**
   * Opens the settings of one widget. Adding and configuring share the panel
   * above the grid, so only one of them is ever open — two forms writing
   * different widgets at once would be two versions to keep straight.
   */
  protected configure(cell: LayoutCell): void {
    this.adding.set(false);
    this.layoutError.set(null);
    this.configuring.set(cell.widget);
  }

  /**
   * The saved widget replaces the one it came from, so the card re-reads its
   * data with the new question. The draft is untouched: settings do not move
   * anything, and an unsaved arrangement is still the user's to save or drop.
   */
  protected onWidgetSaved(widget: DashboardWidget): void {
    this.configuring.set(null);
    this.widgets.set(this.widgets().map((entry) => (entry.id === widget.id ? widget : entry)));
  }

  protected onWidgetAdded(widget: DashboardWidget): void {
    const tenantId = this.tenantId();
    this.adding.set(false);
    this.widgets.set([...this.widgets(), widget]);
    // The server placed it under everything already there; the draft takes that
    // position so an unsaved move elsewhere on the page survives.
    this.draft.set([
      ...this.draft(),
      { id: widget.id, x: widget.x, y: widget.y, width: widget.width, height: widget.height },
    ]);

    if (tenantId !== null) {
      this.refreshVersion(tenantId, () => undefined);
    }
  }

  private apply(tenantId: string, placements: readonly WidgetPlacement[]): void {
    this.saving.set(true);
    this.layoutError.set(null);
    this.conflict.set(false);

    this.workspace
      .applyLayout(tenantId, this.dashboardId(), {
        expected_version: this.version(),
        widgets: placements,
      })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (widgets) => {
          this.widgets.set(widgets);
          this.resetDraft(widgets);
          this.followLayout(widgets);
          this.refreshVersion(tenantId, () => undefined);
        },
        error: (error: unknown) => {
          if (this.isConflict(error)) {
            // Somebody else moved first. Their arrangement stands until this
            // user says otherwise.
            this.conflict.set(true);

            return;
          }

          this.layoutError.set('dashboard.edit.saveError');
        },
      });
  }

  /**
   * A saved arrangement bumps the version of every widget it moved, including
   * one whose settings are open. The form is handed the widget as it now
   * stands, so its next save is not a conflict this tab caused itself.
   *
   * Only the layout response is trusted for this. It changes coordinates and
   * versions and nothing else, so nobody's edit can be masked by it — a plain
   * re-read of the widgets could carry another tab's changed configuration, and
   * silently adopting its version is exactly how that edit would be lost.
   */
  private followLayout(widgets: readonly DashboardWidget[]): void {
    const open = this.configuring();

    if (open === null) {
      return;
    }

    this.configuring.set(widgets.find((widget) => widget.id === open.id) ?? null);
  }

  private refreshVersion(tenantId: string, then: () => void): void {
    this.workspace
      .get(tenantId, this.dashboardId())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (dashboard) => {
          this.version.set(dashboard.version);
          then();
        },
        error: () => this.layoutError.set('dashboard.edit.saveError'),
      });
  }

  private isConflict(error: unknown): boolean {
    return hasProblemCode(error, 'DASHBOARD_VERSION_CONFLICT');
  }

  private replace(placement: WidgetPlacement): void {
    this.layoutError.set(null);
    this.draft.set([...this.draft().filter((entry) => entry.id !== placement.id), placement]);
  }

  private resetDraft(widgets: readonly DashboardWidget[]): void {
    this.draft.set(
      widgets.map((widget) => ({
        id: widget.id,
        x: widget.x,
        y: widget.y,
        width: widget.width,
        height: widget.height,
      })),
    );
  }

  private definitionOf(widget: DashboardWidget): WidgetTypeDefinition | null {
    return this.types().find((type) => type.type_key === widget.type_key) ?? null;
  }

  private clamp(value: number, minimum: number, maximum: number): number {
    return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
  }

  private loadEditingCatalogues(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.types().length > 0) {
      return;
    }

    // Only needed once somebody edits, so a read-only visit never pays for it.
    this.workspace
      .widgetTypes(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (types) => this.types.set(types),
        error: () => this.layoutError.set('dashboard.edit.catalogueError'),
      });

    this.workspace
      .savedQueries(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (queries) => this.queries.set(queries),
        error: () => this.layoutError.set('dashboard.edit.catalogueError'),
      });
  }

  private loadDashboards(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loadFailure.set(new Error('No active tenant.'));

      return;
    }

    // Only the switcher depends on this list, and it stays hidden until there
    // is a second dashboard to switch to — so it needs no loading state of its
    // own; the widgets carry the one the page shows.
    this.workspace
      .list(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.dashboards.set(response.dashboards);
          const open = response.dashboards.find((dashboard) => dashboard.id === this.dashboardId());

          if (open !== undefined) {
            this.version.set(open.version);
          }
        },
        error: (error: unknown) => this.loadFailure.set(error),
      });
  }

  private loadWidgets(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.widgetsLoading.set(false);

      return;
    }

    this.widgetsLoading.set(true);
    this.loadFailure.set(null);

    this.workspace
      .widgets(tenantId, this.dashboardId())
      .pipe(
        finalize(() => this.widgetsLoading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (widgets) => this.widgets.set(widgets),
        error: (error: unknown) => this.loadFailure.set(error),
      });
  }
}
