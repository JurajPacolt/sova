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
import { ActivatedRoute, Router } from '@angular/router';
import { finalize } from 'rxjs';
import { Dashboard, DashboardWidget } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { DashboardWidgetComponent } from '../../components/dashboard-widget/dashboard-widget.component';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';

/**
 * One personal dashboard, with the switcher for the caller's others.
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
 */
@Component({
  selector: 'app-dashboard-page',
  standalone: true,
  imports: [DashboardWidgetComponent, PageHeaderComponent, TranslatePipe],
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

  protected readonly loadError = signal<TranslationKey | null>(null);
  protected readonly dashboards = signal<readonly Dashboard[]>([]);
  protected readonly widgets = signal<readonly DashboardWidget[]>([]);
  protected readonly widgetsLoading = signal(true);

  protected readonly openDashboard = computed(
    () => this.dashboards().find((dashboard) => dashboard.id === this.dashboardId()) ?? null,
  );

  /**
   * Document order, and therefore the order a narrow screen falls back to: top
   * row first, then left to right, with the identifier settling ties. The
   * desktop grid places every widget by its own coordinates, so this ordering
   * never moves anything there — the mobile column is derived, not stored.
   */
  protected readonly orderedWidgets = computed<readonly DashboardWidget[]>(() =>
    [...this.widgets()].sort(
      (left, right) => left.y - right.y || left.x - right.x || left.id.localeCompare(right.id),
    ),
  );

  ngOnInit(): void {
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

  private loadDashboards(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loadError.set('dashboard.loadError');

      return;
    }

    // Only the switcher depends on this list, and it stays hidden until there
    // is a second dashboard to switch to — so it needs no loading state of its
    // own; the widgets carry the one the page shows.
    this.workspace
      .list(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => this.dashboards.set(response.dashboards),
        error: () => this.loadError.set('dashboard.loadError'),
      });
  }

  private loadWidgets(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.widgetsLoading.set(false);

      return;
    }

    this.workspace
      .widgets(tenantId, this.dashboardId())
      .pipe(
        finalize(() => this.widgetsLoading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (widgets) => this.widgets.set(widgets),
        // Somebody else's dashboard is not forbidden, it is absent, so the
        // screen says "gone" rather than "denied".
        error: () => this.loadError.set('dashboard.notFound'),
      });
  }
}
