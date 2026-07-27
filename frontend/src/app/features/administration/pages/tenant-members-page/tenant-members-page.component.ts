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
import { finalize, forkJoin } from 'rxjs';
import { TenantMembership, TenantRole } from '../../../../core/api/api.models';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { TenantMembershipAdministrationService } from '../../tenant-membership-administration.service';
import { TenantRoleAdministrationService } from '../../tenant-role-administration.service';

type MembershipStatus = TenantMembership['status'];

const STATUS_KEYS: Readonly<Record<MembershipStatus, TranslationKey>> = {
  ACTIVE: 'common.active',
  DISABLED: 'tenantMembers.disabled',
  REMOVED: 'tenantMembers.removed',
};

const TRANSITIONS: Readonly<Record<MembershipStatus, readonly MembershipStatus[]>> = {
  ACTIVE: ['DISABLED', 'REMOVED'],
  DISABLED: ['ACTIVE', 'REMOVED'],
  REMOVED: [],
};

interface LifecycleSelection {
  readonly membership: TenantMembership;
  readonly target: MembershipStatus;
}

interface RoleSelection {
  readonly membership: TenantMembership;
}

@Component({
  selector: 'app-tenant-members-page',
  standalone: true,
  imports: [PageHeaderComponent, ReactiveFormsModule, TranslatePipe],
  templateUrl: './tenant-members-page.component.html',
  styleUrl: './tenant-members-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantMembersPageComponent implements OnInit {
  private readonly administration = inject(TenantMembershipAdministrationService);
  private readonly roleAdministration = inject(TenantRoleAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly session = inject(AuthSessionStore);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly memberships = signal<readonly TenantMembership[]>([]);
  protected readonly roles = signal<readonly TenantRole[]>([]);
  protected readonly loading = signal(false);
  protected readonly loadError = signal(false);
  protected readonly activeCount = computed(
    () => this.memberships().filter((membership) => membership.status === 'ACTIVE').length,
  );

  protected readonly inviteForm = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email, Validators.maxLength(254)]],
  });
  protected readonly inviting = signal(false);
  protected readonly inviteError = signal(false);
  protected readonly invitedEmail = signal<string | null>(null);

  protected readonly lifecycleSelection = signal<LifecycleSelection | null>(null);
  protected readonly lifecycleMembershipId = signal<string | null>(null);
  protected readonly lifecycleError = signal(false);

  protected readonly roleSelection = signal<RoleSelection | null>(null);
  protected readonly roleActionMembershipId = signal<string | null>(null);
  protected readonly roleError = signal(false);
  protected readonly roleForm = this.formBuilder.nonNullable.group({
    role_id: ['', Validators.required],
  });

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.loadError.set(false);
    this.loading.set(true);
    forkJoin({
      memberships: this.administration.list(tenantId),
      roleList: this.roleAdministration.list(tenantId),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ memberships, roleList }) => {
          this.memberships.set(memberships);
          this.roles.set(roleList.roles.filter((role) => role.status === 'ACTIVE'));
        },
        error: () => this.loadError.set(true),
      });
  }

  protected invite(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.inviteForm.invalid || this.inviting()) {
      this.inviteForm.markAllAsTouched();
      return;
    }

    this.inviteError.set(false);
    this.invitedEmail.set(null);
    this.inviting.set(true);
    this.administration
      .invite(tenantId, this.inviteForm.getRawValue().email)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.inviting.set(false)),
      )
      .subscribe({
        next: (invitation) => {
          this.invitedEmail.set(invitation.email);
          this.inviteForm.reset();
        },
        error: () => this.inviteError.set(true),
      });
  }

  protected isOwnMembership(membership: TenantMembership): boolean {
    return membership.user.id === this.session.user()?.id;
  }

  protected statusKey(status: MembershipStatus): TranslationKey {
    return STATUS_KEYS[status];
  }

  protected transitions(status: MembershipStatus): readonly MembershipStatus[] {
    return TRANSITIONS[status];
  }

  protected selectLifecycle(membership: TenantMembership, target: MembershipStatus): void {
    this.lifecycleError.set(false);
    this.lifecycleSelection.set({ membership, target });
  }

  protected cancelLifecycle(): void {
    this.lifecycleSelection.set(null);
    this.lifecycleError.set(false);
  }

  protected confirmLifecycle(): void {
    const tenantId = this.tenantId();
    const selection = this.lifecycleSelection();

    if (tenantId === null || selection === null || this.lifecycleMembershipId() !== null) {
      return;
    }

    this.lifecycleError.set(false);
    this.lifecycleMembershipId.set(selection.membership.id);
    this.administration
      .changeStatus(tenantId, selection.membership.id, selection.target)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.lifecycleMembershipId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.memberships.update((memberships) =>
            memberships.map((membership) => (membership.id === updated.id ? updated : membership)),
          );
          this.cancelLifecycle();
        },
        error: () => {
          this.lifecycleError.set(true);
          this.refresh();
        },
      });
  }

  protected availableRolesFor(membership: TenantMembership): readonly TenantRole[] {
    const assignedIds = new Set(membership.roles.map((role) => role.id));

    return this.roles().filter((role) => !assignedIds.has(role.id));
  }

  protected openRoleSelection(membership: TenantMembership): void {
    this.roleError.set(false);
    this.roleForm.reset();
    this.roleSelection.set({ membership });
  }

  protected cancelRoleSelection(): void {
    this.roleSelection.set(null);
    this.roleError.set(false);
  }

  protected addRole(): void {
    const tenantId = this.tenantId();
    const selection = this.roleSelection();

    if (
      tenantId === null ||
      selection === null ||
      this.roleForm.invalid ||
      this.roleActionMembershipId() !== null
    ) {
      this.roleForm.markAllAsTouched();
      return;
    }

    const roleId = this.roleForm.getRawValue().role_id;
    this.roleError.set(false);
    this.roleActionMembershipId.set(selection.membership.id);
    this.administration
      .assignRole(tenantId, selection.membership.id, roleId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.roleActionMembershipId.set(null)),
      )
      .subscribe({
        next: () => {
          this.cancelRoleSelection();
          this.refresh();
        },
        error: () => this.roleError.set(true),
      });
  }

  protected removeRole(membership: TenantMembership, roleId: string): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.roleActionMembershipId() !== null) {
      return;
    }

    this.roleError.set(false);
    this.roleActionMembershipId.set(membership.id);
    this.administration
      .unassignRole(tenantId, membership.id, roleId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.roleActionMembershipId.set(null)),
      )
      .subscribe({
        next: () => this.refresh(),
        error: () => this.roleError.set(true),
      });
  }
}
