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
import { finalize } from 'rxjs';
import { SystemUser, UserAccountStatus } from '../../../../core/api/api.models';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { SystemUserAdministrationService } from '../../system-user-administration.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';

type AdminTarget = Extract<UserAccountStatus, 'ACTIVE' | 'DISABLED'>;

const STATUS_KEYS: Readonly<Record<UserAccountStatus, TranslationKey>> = {
  PENDING_VERIFICATION: 'systemUsers.pendingVerification',
  ACTIVE: 'common.active',
  LOCKED: 'systemUsers.locked',
  DISABLED: 'systemUsers.disabled',
  EXPIRED: 'systemUsers.expired',
  DELETED: 'systemUsers.deleted',
};

const TRANSITIONS: Readonly<Record<UserAccountStatus, readonly AdminTarget[]>> = {
  PENDING_VERIFICATION: ['ACTIVE', 'DISABLED'],
  ACTIVE: ['DISABLED'],
  LOCKED: ['ACTIVE', 'DISABLED'],
  DISABLED: ['ACTIVE'],
  EXPIRED: [],
  DELETED: [],
};

interface LifecycleSelection {
  readonly user: SystemUser;
  readonly target: AdminTarget;
}

@Component({
  selector: 'app-system-user-list-page',
  standalone: true,
  imports: [ErrorStateComponent, PageHeaderComponent, TranslatePipe],
  templateUrl: './system-user-list-page.component.html',
  styleUrl: './system-user-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SystemUserListPageComponent implements OnInit {
  private readonly administration = inject(SystemUserAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly session = inject(AuthSessionStore);

  protected readonly users = signal<readonly SystemUser[]>([]);
  protected readonly loading = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly activeCount = computed(
    () => this.users().filter((user) => user.status === 'ACTIVE').length,
  );

  protected readonly lifecycleSelection = signal<LifecycleSelection | null>(null);
  protected readonly lifecycleUserId = signal<string | null>(null);
  protected readonly lifecycleError = signal(false);

  protected readonly superadminActionUserId = signal<string | null>(null);
  protected readonly superadminError = signal(false);

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
        next: (users) => this.users.set(users),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected isOwnAccount(user: SystemUser): boolean {
    return user.id === this.session.user()?.id;
  }

  protected statusKey(status: UserAccountStatus): TranslationKey {
    return STATUS_KEYS[status];
  }

  protected transitions(status: UserAccountStatus): readonly AdminTarget[] {
    return TRANSITIONS[status];
  }

  protected selectLifecycle(user: SystemUser, target: AdminTarget): void {
    this.lifecycleError.set(false);
    this.lifecycleSelection.set({ user, target });
  }

  protected cancelLifecycle(): void {
    this.lifecycleSelection.set(null);
    this.lifecycleError.set(false);
  }

  protected confirmLifecycle(): void {
    const selection = this.lifecycleSelection();

    if (selection === null || this.lifecycleUserId() !== null) {
      return;
    }

    this.lifecycleError.set(false);
    this.lifecycleUserId.set(selection.user.id);
    this.administration
      .changeStatus(selection.user.id, selection.target)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.lifecycleUserId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.users.update((users) =>
            users.map((user) => (user.id === updated.id ? updated : user)),
          );
          this.cancelLifecycle();
        },
        error: () => {
          this.lifecycleError.set(true);
          this.refresh();
        },
      });
  }

  protected toggleSuperadmin(user: SystemUser): void {
    if (this.superadminActionUserId() !== null) {
      return;
    }

    this.superadminError.set(false);
    this.superadminActionUserId.set(user.id);
    const request$ = user.is_superadmin
      ? this.administration.revokeSuperadmin(user.id)
      : this.administration.grantSuperadmin(user.id);

    request$
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.superadminActionUserId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.users.update((users) =>
            users.map((existing) => (existing.id === updated.id ? updated : existing)),
          );
        },
        error: () => this.superadminError.set(true),
      });
  }
}
