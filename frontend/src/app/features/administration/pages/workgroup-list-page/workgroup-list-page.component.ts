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
import { TenantMembership, Workgroup, WorkgroupMember } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { TenantMembershipAdministrationService } from '../../tenant-membership-administration.service';
import { WorkgroupAdministrationService } from '../../workgroup-administration.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-workgroup-list-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  templateUrl: './workgroup-list-page.component.html',
  styleUrl: './workgroup-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WorkgroupListPageComponent implements OnInit {
  private readonly administration = inject(WorkgroupAdministrationService);
  private readonly membershipAdministration = inject(TenantMembershipAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly workgroups = signal<readonly Workgroup[]>([]);
  protected readonly tenantMemberships = signal<readonly TenantMembership[]>([]);
  protected readonly loading = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly activeCount = computed(
    () => this.workgroups().filter((workgroup) => workgroup.status === 'ACTIVE').length,
  );

  protected readonly createForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
    description: ['', Validators.maxLength(500)],
  });
  protected readonly creating = signal(false);
  protected readonly createError = signal(false);

  protected readonly statusActionId = signal<string | null>(null);
  protected readonly statusError = signal(false);

  protected readonly expandedWorkgroupId = signal<string | null>(null);
  protected readonly members = signal<readonly WorkgroupMember[]>([]);
  protected readonly membersLoading = signal(false);
  protected readonly membersError = signal(false);

  protected readonly addMemberForm = this.formBuilder.nonNullable.group({
    membership_id: ['', Validators.required],
    role: this.formBuilder.nonNullable.control<'MEMBER' | 'MANAGER'>('MEMBER'),
  });
  protected readonly memberActionId = signal<string | null>(null);

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.loadFailure.set(null);
    this.loading.set(true);
    forkJoin({
      workgroups: this.administration.list(tenantId),
      memberships: this.membershipAdministration.list(tenantId),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ workgroups, memberships }) => {
          this.workgroups.set(workgroups);
          this.tenantMemberships.set(
            memberships.filter((membership) => membership.status === 'ACTIVE'),
          );
        },
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected createWorkgroup(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.createForm.invalid || this.creating()) {
      this.createForm.markAllAsTouched();
      return;
    }

    this.createError.set(false);
    this.creating.set(true);
    this.administration
      .create(tenantId, this.createForm.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.creating.set(false)),
      )
      .subscribe({
        next: (workgroup) => {
          this.workgroups.update((workgroups) => [...workgroups, workgroup]);
          this.createForm.reset();
        },
        error: () => this.createError.set(true),
      });
  }

  protected toggleStatus(workgroup: Workgroup): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.statusActionId() !== null) {
      return;
    }

    const target = workgroup.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE';
    this.statusError.set(false);
    this.statusActionId.set(workgroup.id);
    this.administration
      .changeStatus(tenantId, workgroup.id, target)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.statusActionId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.workgroups.update((workgroups) =>
            workgroups.map((existing) => (existing.id === updated.id ? updated : existing)),
          );
        },
        error: () => {
          this.statusError.set(true);
          this.refresh();
        },
      });
  }

  protected toggleMembers(workgroup: Workgroup): void {
    const tenantId = this.tenantId();

    if (this.expandedWorkgroupId() === workgroup.id) {
      this.expandedWorkgroupId.set(null);
      return;
    }

    if (tenantId === null) {
      return;
    }

    this.expandedWorkgroupId.set(workgroup.id);
    this.membersError.set(false);
    this.membersLoading.set(true);
    this.addMemberForm.reset({ membership_id: '', role: 'MEMBER' });
    this.administration
      .listMembers(tenantId, workgroup.id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.membersLoading.set(false)),
      )
      .subscribe({
        next: (members) => this.members.set(members),
        error: () => this.membersError.set(true),
      });
  }

  protected availableMemberships(): readonly TenantMembership[] {
    const assignedIds = new Set(this.members().map((member) => member.membership_id));

    return this.tenantMemberships().filter((membership) => !assignedIds.has(membership.id));
  }

  protected addMember(): void {
    const tenantId = this.tenantId();
    const workgroupId = this.expandedWorkgroupId();

    if (
      tenantId === null ||
      workgroupId === null ||
      this.addMemberForm.invalid ||
      this.memberActionId() !== null
    ) {
      this.addMemberForm.markAllAsTouched();
      return;
    }

    const raw = this.addMemberForm.getRawValue();
    this.membersError.set(false);
    this.memberActionId.set(raw.membership_id);
    this.administration
      .upsertMember(tenantId, workgroupId, raw.membership_id, raw.role)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.memberActionId.set(null)),
      )
      .subscribe({
        next: (member) => {
          this.members.update((members) => [
            ...members.filter((existing) => existing.membership_id !== member.membership_id),
            member,
          ]);
          this.addMemberForm.reset({ membership_id: '', role: 'MEMBER' });
          this.refreshWorkgroupCount(workgroupId);
        },
        error: () => this.membersError.set(true),
      });
  }

  protected removeMember(workgroupId: string, membershipId: string): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.memberActionId() !== null) {
      return;
    }

    this.membersError.set(false);
    this.memberActionId.set(membershipId);
    this.administration
      .removeMember(tenantId, workgroupId, membershipId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.memberActionId.set(null)),
      )
      .subscribe({
        next: () => {
          this.members.update((members) =>
            members.filter((member) => member.membership_id !== membershipId),
          );
          this.refreshWorkgroupCount(workgroupId);
        },
        error: () => this.membersError.set(true),
      });
  }

  private refreshWorkgroupCount(workgroupId: string): void {
    this.workgroups.update((workgroups) =>
      workgroups.map((workgroup) =>
        workgroup.id === workgroupId
          ? { ...workgroup, member_count: this.members().length }
          : workgroup,
      ),
    );
  }
}
