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
import { RouterLink } from '@angular/router';
import { TenantAccess, TenantStatus } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantAccessService } from '../../../../core/tenancy/tenant-access.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { LanguageSwitcherComponent } from '../../../../shared/components/language-switcher/language-switcher.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { ThemeSwitcherComponent } from '../../../../shared/components/theme-switcher/theme-switcher.component';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';

const STATUS_KEYS: Readonly<Record<TenantStatus, TranslationKey>> = {
  ACTIVE: 'common.active',
  ARCHIVED: 'common.archived',
  DELETION_PENDING: 'common.deletionPending',
  PENDING: 'common.pending',
  SUSPENDED: 'common.suspended',
};

const ACCESS_KEYS: Readonly<Record<TenantAccess['type'], TranslationKey>> = {
  MEMBERSHIP: 'tenant.accessMembership',
  SUPERADMIN: 'tenant.accessSuperadmin',
};

@Component({
  selector: 'app-tenant-selection-page',
  standalone: true,
  imports: [
    ErrorStateComponent,
    LanguageSwitcherComponent,
    PageHeaderComponent,
    RouterLink,
    ThemeSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './tenant-selection-page.component.html',
  styleUrl: './tenant-selection-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSelectionPageComponent implements OnInit {
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantAccess = inject(TenantAccessService);
  private readonly tenantStore = inject(TenantStore);

  protected readonly tenants = this.tenantStore.tenants;
  protected readonly loading = this.tenantStore.loading;
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly activeTenants = computed(() =>
    this.tenants().filter((tenant) => tenant.status === 'ACTIVE'),
  );

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    this.loadFailure.set(null);
    this.tenantAccess
      .refresh()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected statusKey(status: TenantStatus): TranslationKey {
    return STATUS_KEYS[status];
  }

  protected accessKey(type: TenantAccess['type']): TranslationKey {
    return ACCESS_KEYS[type];
  }
}
