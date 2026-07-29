import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { finalize } from 'rxjs';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';

/**
 * The bare `dashboards` path: it decides which dashboard to open and hands over.
 *
 * Resolving lives in its own screen rather than inside the dashboard itself, so
 * the address bar always names what is on display — a dashboard is bookmarked
 * and shared by identifier — and the dashboard screen never has to reason about
 * a missing one. The replacement is deliberate: the bare path was a redirect,
 * and Back should leave the dashboards, not bounce between them.
 *
 * A member with nothing to open is the one case that stays here. The server
 * hands everybody who may own a dashboard their starter one on this very call,
 * so an empty answer means the caller may not have one at all — which is a
 * sentence, not an error.
 */
@Component({
  selector: 'app-dashboard-entry',
  standalone: true,
  imports: [TranslatePipe],
  templateUrl: './dashboard-entry.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardEntryComponent implements OnInit {
  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly tenantStore = inject(TenantStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly loading = signal(true);
  protected readonly loadError = signal<TranslationKey | null>(null);
  protected readonly restoring = signal(false);
  protected readonly empty = signal(false);

  protected readonly mayCreate = computed(() =>
    this.tenantStore.hasAnyPermission(['dashboard.create']),
  );

  ngOnInit(): void {
    this.resolve();
  }

  protected restoreTemplate(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.restoring()) {
      return;
    }

    this.restoring.set(true);
    this.loadError.set(null);

    this.workspace
      .restoreFromTemplate(tenantId)
      .pipe(
        finalize(() => this.restoring.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => this.show(response.dashboard.id),
        error: () => this.loadError.set('dashboard.restoreError'),
      });
  }

  private resolve(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loading.set(false);
      this.loadError.set('dashboard.loadError');

      return;
    }

    this.workspace
      .list(tenantId)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          // The last one they looked at, then their default; the server has
          // already worked that out, so the client does not second-guess it.
          const target = response.active_dashboard_id ?? response.dashboards[0]?.id ?? null;

          if (target === null) {
            this.empty.set(true);

            return;
          }

          this.show(target);
        },
        error: () => this.loadError.set('dashboard.loadError'),
      });
  }

  private show(dashboardId: string): void {
    void this.router.navigate([dashboardId], { relativeTo: this.route, replaceUrl: true });
  }
}
