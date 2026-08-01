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
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { concatMap, finalize, Observable } from 'rxjs';
import { Dashboard } from '../../../../core/api/api.models';
import { hasProblemCode } from '../../../../core/errors/api-error';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';

/**
 * "Manage dashboards" (spec §7.3): the one place where dashboards themselves
 * are created, named, ordered and removed, rather than what is on them.
 *
 * Everything here is somebody's own. A dashboard belongs to exactly one
 * membership, so this screen never shows another person's — and the server
 * answers `404` rather than `403` for one, which is why nothing here tries to
 * explain a refusal it will never see.
 *
 * Two rules the server owns and this screen only surfaces: exactly one
 * dashboard is the default, and the last one cannot be deleted — a member has
 * to have somewhere to land, so emptying it is the way to start over.
 */
@Component({
  selector: 'app-dashboard-manage',
  standalone: true,
  imports: [ErrorStateComponent, DatePipe, PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './dashboard-manage.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardManageComponent implements OnInit {
  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly tenantStore = inject(TenantStore);
  private readonly destroyRef = inject(DestroyRef);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly dashboards = signal<readonly Dashboard[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly actionError = signal<TranslationKey | null>(null);
  protected readonly busy = signal(false);

  protected readonly newName = signal('');
  protected readonly renameTarget = signal<string | null>(null);
  protected readonly renameValue = signal('');
  protected readonly deleteTarget = signal<string | null>(null);

  protected readonly mayCreate = computed(() =>
    this.tenantStore.hasAnyPermission(['dashboard.create']),
  );

  protected readonly mayUpdate = computed(() =>
    this.tenantStore.hasAnyPermission(['dashboard.update-own']),
  );

  protected readonly mayDelete = computed(() =>
    this.tenantStore.hasAnyPermission(['dashboard.delete-own']),
  );

  /** The order the switcher uses: position, then identifier to settle ties. */
  protected readonly ordered = computed<readonly Dashboard[]>(() =>
    [...this.dashboards()].sort(
      (left, right) => left.position - right.position || left.id.localeCompare(right.id),
    ),
  );

  ngOnInit(): void {
    this.load();
  }

  protected create(): void {
    const tenantId = this.tenantId();
    const name = this.newName().trim();

    if (tenantId === null || name === '' || this.busy()) {
      return;
    }

    this.run(
      this.workspace.create(tenantId, name),
      () => this.newName.set(''),
      'dashboard.manage.createError',
    );
  }

  protected startRename(dashboard: Dashboard): void {
    this.renameTarget.set(dashboard.id);
    this.renameValue.set(dashboard.name);
    this.actionError.set(null);
  }

  protected cancelRename(): void {
    this.renameTarget.set(null);
    this.renameValue.set('');
  }

  protected confirmRename(dashboard: Dashboard): void {
    const tenantId = this.tenantId();
    const name = this.renameValue().trim();

    if (tenantId === null || name === '' || this.busy()) {
      return;
    }

    this.run(
      this.workspace.update(tenantId, dashboard.id, dashboard.version, name),
      () => this.cancelRename(),
      'dashboard.manage.renameError',
    );
  }

  protected duplicate(dashboard: Dashboard): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.busy()) {
      return;
    }

    this.run(
      this.workspace.duplicate(tenantId, dashboard.id, this.freeName(dashboard.name)),
      () => undefined,
      'dashboard.manage.duplicateError',
    );
  }

  protected makeDefault(dashboard: Dashboard): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.busy() || dashboard.is_default) {
      return;
    }

    this.run(
      this.workspace.makeDefault(tenantId, dashboard.id),
      () => undefined,
      'dashboard.manage.defaultError',
    );
  }

  /**
   * Reordering is a swap, and the endpoint moves one dashboard at a time, so it
   * takes two writes chained on each other. If the second one fails the list is
   * reloaded and the failure is shown — a half-done swap is visible and
   * repeatable, which is better than pretending it did not happen.
   */
  protected move(dashboard: Dashboard, offset: number): void {
    const tenantId = this.tenantId();
    const order = this.ordered();
    const index = order.findIndex((entry) => entry.id === dashboard.id);
    const neighbour = order[index + offset];

    if (tenantId === null || neighbour === undefined || this.busy()) {
      return;
    }

    this.busy.set(true);
    this.actionError.set(null);

    this.workspace
      .update(tenantId, dashboard.id, dashboard.version, dashboard.name, neighbour.position)
      .pipe(
        concatMap(() =>
          this.workspace.update(
            tenantId,
            neighbour.id,
            neighbour.version,
            neighbour.name,
            dashboard.position,
          ),
        ),
        finalize(() => this.busy.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => this.load(),
        error: () => {
          this.actionError.set('dashboard.manage.orderError');
          this.load();
        },
      });
  }

  protected askDelete(dashboard: Dashboard): void {
    this.deleteTarget.set(dashboard.id);
    this.actionError.set(null);
  }

  protected cancelDelete(): void {
    this.deleteTarget.set(null);
  }

  protected confirmDelete(dashboard: Dashboard): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.busy()) {
      return;
    }

    this.busy.set(true);
    this.actionError.set(null);

    this.workspace
      .remove(tenantId, dashboard.id)
      .pipe(
        finalize(() => this.busy.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.deleteTarget.set(null);
          this.load();
        },
        error: (error: unknown) => {
          this.deleteTarget.set(null);
          // The server refuses to leave a member with nothing to open; say that
          // rather than "something went wrong".
          this.actionError.set(
            hasProblemCode(error, 'LAST_DASHBOARD_REQUIRED')
              ? 'dashboard.manage.lastOne'
              : 'dashboard.manage.deleteError',
          );
        },
      });
  }

  private run<T>(request: Observable<T>, onSuccess: () => void, errorKey: TranslationKey): void {
    this.busy.set(true);
    this.actionError.set(null);

    request
      .pipe(
        finalize(() => this.busy.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          onSuccess();
          this.load();
        },
        error: (error: unknown) =>
          this.actionError.set(
            hasProblemCode(error, 'DASHBOARD_NAME_TAKEN') ? 'dashboard.manage.nameTaken' : errorKey,
          ),
      });
  }

  /**
   * A name the owner does not already use. The server would answer `409`, and a
   * conflict is the wrong reply to "make me a copy of this" — the point of the
   * button is not having to invent a name first.
   */
  private freeName(base: string): string {
    const taken = new Set(this.dashboards().map((entry) => this.normalize(entry.name)));

    for (let suffix = 2; suffix <= 50; ++suffix) {
      const candidate = `${base} ${suffix}`;

      if (!taken.has(this.normalize(candidate))) {
        return candidate.slice(0, 160);
      }
    }

    return base.slice(0, 158) + ' 2';
  }

  /** The server compares names case- and whitespace-insensitively. */
  private normalize(name: string): string {
    return name.trim().replace(/\s+/gu, ' ').toLowerCase();
  }

  /** The list again — the actions below keep their own sentences. */
  protected reload(): void {
    this.loading.set(true);
    this.loadFailure.set(null);
    this.load();
  }

  private load(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loading.set(false);
      this.loadFailure.set(new Error('No active tenant.'));

      return;
    }

    this.workspace
      .list(tenantId)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => this.dashboards.set(response.dashboards),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }
}
