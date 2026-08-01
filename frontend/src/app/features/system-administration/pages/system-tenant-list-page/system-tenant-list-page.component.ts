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
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  ChangeSystemTenantStatusRequest,
  SystemTenant,
  TenantMembership,
  TenantStatus,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { ImpersonationSessionService } from '../../../../core/auth/impersonation-session.service';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { SystemTenantAdministrationService } from '../../system-tenant-administration.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

const STATUS_KEYS: Readonly<Record<TenantStatus, TranslationKey>> = {
  ACTIVE: 'common.active',
  ARCHIVED: 'common.archived',
  DELETION_PENDING: 'common.deletionPending',
  PENDING: 'common.pending',
  SUSPENDED: 'common.suspended',
};

type LifecycleTarget = ChangeSystemTenantStatusRequest['status'];

const TRANSITIONS: Readonly<Record<TenantStatus, readonly LifecycleTarget[]>> = {
  ACTIVE: ['SUSPENDED', 'ARCHIVED'],
  ARCHIVED: ['DELETION_PENDING'],
  DELETION_PENDING: ['ARCHIVED'],
  PENDING: [],
  SUSPENDED: ['ACTIVE', 'ARCHIVED'],
};

interface LifecycleSelection {
  readonly tenant: SystemTenant;
  readonly target: LifecycleTarget;
}

interface ImpersonationSelection {
  readonly tenant: SystemTenant;
  readonly members: readonly TenantMembership[];
}

@Component({
  selector: 'app-system-tenant-list-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './system-tenant-list-page.component.html',
  styleUrl: './system-tenant-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SystemTenantListPageComponent implements OnInit {
  private readonly administration = inject(SystemTenantAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly impersonationSession = inject(ImpersonationSessionService);
  private readonly router = inject(Router);

  protected readonly tenants = signal<readonly SystemTenant[]>([]);
  protected readonly loading = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly creating = signal(false);
  protected readonly createError = signal(false);
  protected readonly createdOwnerEmail = signal<string | null>(null);
  protected readonly lifecycleSelection = signal<LifecycleSelection | null>(null);
  protected readonly lifecycleTenantId = signal<string | null>(null);
  protected readonly lifecycleError = signal(false);
  protected readonly impersonationSelection = signal<ImpersonationSelection | null>(null);
  protected readonly impersonationTargetsLoading = signal<string | null>(null);
  protected readonly impersonationStarting = signal(false);
  protected readonly impersonationError = signal(false);
  protected readonly activeCount = computed(
    () => this.tenants().filter((tenant) => tenant.status === 'ACTIVE').length,
  );
  protected readonly createForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(200)]],
    slug: ['', [Validators.required, Validators.pattern(/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/)]],
    owner_email: ['', [Validators.required, Validators.email, Validators.maxLength(254)]],
  });
  protected readonly reasonControl = this.formBuilder.nonNullable.control('', [
    Validators.required,
    Validators.minLength(10),
    Validators.maxLength(500),
  ]);
  protected readonly impersonationForm = this.formBuilder.nonNullable.group({
    effective_user_id: ['', Validators.required],
    reason: ['', [Validators.required, Validators.minLength(10), Validators.maxLength(500)]],
    password: ['', [Validators.required, Validators.maxLength(1024)]],
  });
  private creationAttempt: { readonly fingerprint: string; readonly key: string } | null = null;

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    this.loadFailure.set(null);
    this.loading.set(true);
    this.administration
      .list()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (tenants) => this.tenants.set(tenants),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected createTenant(): void {
    if (this.createForm.invalid || this.creating()) {
      this.createForm.markAllAsTouched();
      return;
    }

    const request = this.createForm.getRawValue();
    const fingerprint = JSON.stringify(request);

    if (this.creationAttempt?.fingerprint !== fingerprint) {
      this.creationAttempt = {
        fingerprint,
        key: crypto.randomUUID(),
      };
    }

    this.createError.set(false);
    this.createdOwnerEmail.set(null);
    this.creating.set(true);
    this.administration
      .create(request, this.creationAttempt.key)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.creating.set(false)),
      )
      .subscribe({
        next: (result) => {
          this.createdOwnerEmail.set(result.owner_invitation.email);
          this.createForm.reset();
          this.creationAttempt = null;
          this.refresh();
        },
        error: () => this.createError.set(true),
      });
  }

  protected transitions(status: TenantStatus): readonly LifecycleTarget[] {
    return TRANSITIONS[status];
  }

  protected selectLifecycle(tenant: SystemTenant, target: LifecycleTarget): void {
    this.lifecycleError.set(false);
    this.reasonControl.reset();
    this.lifecycleSelection.set({ tenant, target });
  }

  protected cancelLifecycle(): void {
    this.lifecycleSelection.set(null);
    this.reasonControl.reset();
    this.lifecycleError.set(false);
  }

  protected changeStatus(): void {
    const selection = this.lifecycleSelection();

    if (selection === null || this.reasonControl.invalid || this.lifecycleTenantId() !== null) {
      this.reasonControl.markAsTouched();
      return;
    }

    this.lifecycleError.set(false);
    this.lifecycleTenantId.set(selection.tenant.id);
    this.administration
      .changeStatus(selection.tenant.id, {
        status: selection.target,
        revision: selection.tenant.revision,
        reason: this.reasonControl.getRawValue(),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.lifecycleTenantId.set(null)),
      )
      .subscribe({
        next: (updatedTenant) => {
          this.tenants.update((tenants) =>
            tenants.map((tenant) => (tenant.id === updatedTenant.id ? updatedTenant : tenant)),
          );
          this.cancelLifecycle();
        },
        error: () => {
          this.lifecycleError.set(true);
          this.refresh();
        },
      });
  }

  protected statusKey(status: TenantStatus): TranslationKey {
    return STATUS_KEYS[status];
  }

  protected selectImpersonation(tenant: SystemTenant): void {
    if (tenant.status !== 'ACTIVE' || this.impersonationTargetsLoading() !== null) {
      return;
    }

    this.impersonationError.set(false);
    this.impersonationSelection.set(null);
    this.impersonationForm.reset();
    this.impersonationTargetsLoading.set(tenant.id);
    this.administration
      .listActiveMembers(tenant.id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.impersonationTargetsLoading.set(null)),
      )
      .subscribe({
        next: (members) => this.impersonationSelection.set({ tenant, members }),
        error: () => this.impersonationError.set(true),
      });
  }

  protected cancelImpersonation(): void {
    this.impersonationSelection.set(null);
    this.impersonationForm.reset();
    this.impersonationError.set(false);
  }

  protected startImpersonation(): void {
    const selection = this.impersonationSelection();

    if (selection === null || this.impersonationForm.invalid || this.impersonationStarting()) {
      this.impersonationForm.markAllAsTouched();
      return;
    }

    this.impersonationError.set(false);
    this.impersonationStarting.set(true);
    this.impersonationSession
      .start({
        tenant_id: selection.tenant.id,
        ...this.impersonationForm.getRawValue(),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.impersonationStarting.set(false)),
      )
      .subscribe({
        next: (response) => {
          void this.router.navigate(['/t', response.impersonation.tenant.slug, 'dashboards']);
        },
        error: () => this.impersonationError.set(true),
      });
  }
}
