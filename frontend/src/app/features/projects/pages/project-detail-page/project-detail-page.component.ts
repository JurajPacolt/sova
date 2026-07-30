import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  ProjectListItem,
  ProjectMember,
  ProjectRole,
  ProjectVisibility,
  ProjectWorkgroupLink,
  TenantMembership,
  Workgroup,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { ProjectAdministrationService } from '../../project-administration.service';
import { describeApiError } from '../../../../core/errors/api-error';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-project-detail-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './project-detail-page.component.html',
  styleUrl: './project-detail-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectDetailPageComponent {
  private readonly administration = inject(ProjectAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  /** Bound from the `:projectId` route parameter. */
  readonly projectId = input.required<string>();

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly project = signal<ProjectListItem | null>(null);
  protected readonly projectLoading = signal(false);
  protected readonly projectMissing = signal(false);
  /**
   * The failed request itself. Loads get the shared error state, which reads
   * the status; the action alerts below keep their own sentence, because
   * "try again" on a write is a request to repeat it.
   */
  protected readonly projectFailure = signal<unknown>(null);

  protected readonly roles = signal<readonly ProjectRole[]>([]);
  protected readonly members = signal<readonly ProjectMember[]>([]);
  protected readonly workgroupLinks = signal<readonly ProjectWorkgroupLink[]>([]);
  protected readonly memberships = signal<readonly TenantMembership[]>([]);
  protected readonly workgroups = signal<readonly Workgroup[]>([]);

  protected readonly detailsForbidden = signal(false);
  protected readonly detailsFailure = signal<unknown>(null);

  protected readonly statusChanging = signal(false);
  protected readonly statusError = signal(false);
  protected readonly visibilityChanging = signal(false);
  protected readonly visibilityConfirmationOpen = signal(false);
  protected readonly visibilityError = signal<'MANAGER_REQUIRED' | 'GENERAL' | null>(null);

  protected readonly memberActionId = signal<string | null>(null);
  protected readonly memberError = signal(false);
  protected readonly workgroupActionId = signal<string | null>(null);
  protected readonly workgroupError = signal(false);

  protected readonly assignableRoles = computed(() =>
    this.roles().filter((role) => role.status === 'ACTIVE'),
  );

  protected readonly availableMemberships = computed(() => {
    const assigned = new Set(this.members().map((member) => member.membership_id));

    return this.memberships().filter((membership) => !assigned.has(membership.id));
  });

  protected readonly availableWorkgroups = computed(() => {
    const linked = new Set(this.workgroupLinks().map((link) => link.workgroup_id));

    return this.workgroups().filter((workgroup) => !linked.has(workgroup.id));
  });

  protected readonly assignForm = this.formBuilder.nonNullable.group({
    membership_id: ['', Validators.required],
    role_id: ['', Validators.required],
  });

  protected readonly linkForm = this.formBuilder.nonNullable.group({
    workgroup_id: ['', Validators.required],
    role_id: ['', Validators.required],
  });

  constructor() {
    effect(() => {
      const projectId = this.projectId();
      const tenantId = this.tenantId();

      if (tenantId !== null && projectId !== '') {
        this.load(tenantId, projectId);
      }
    });
  }

  protected reload(): void {
    const tenantId = this.tenantId();

    if (tenantId !== null) {
      this.load(tenantId, this.projectId());
    }
  }

  private load(tenantId: string, projectId: string): void {
    this.projectFailure.set(null);
    this.projectMissing.set(false);
    this.projectLoading.set(true);
    this.administration
      .list(tenantId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.projectLoading.set(false)),
      )
      .subscribe({
        next: (projects) => {
          const match = projects.find((candidate) => candidate.id === projectId) ?? null;
          this.project.set(match);

          if (match === null) {
            this.projectMissing.set(true);
            return;
          }

          this.loadDetails(tenantId, projectId);
        },
        error: (failure: unknown) => this.projectFailure.set(failure),
      });
  }

  private loadDetails(tenantId: string, projectId: string): void {
    this.detailsForbidden.set(false);
    this.detailsFailure.set(null);

    // Every section is loaded on its own: a tenant-visible project the caller
    // holds no role in answers 403 here while the header stays usable.
    this.administration
      .listRoles(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (roles) => this.roles.set(roles),
        error: (error: unknown) => this.handleDetailsError(error),
      });

    this.administration
      .listMembers(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (members) => this.members.set(members),
        error: (error: unknown) => this.handleDetailsError(error),
      });

    this.administration
      .listWorkgroupLinks(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (links) => this.workgroupLinks.set(links),
        error: (error: unknown) => this.handleDetailsError(error),
      });

    this.administration
      .listActiveMemberships(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (memberships) => this.memberships.set(memberships),
        error: () => this.memberships.set([]),
      });

    this.administration
      .listActiveWorkgroups(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (workgroups) => this.workgroups.set(workgroups),
        error: () => this.workgroups.set([]),
      });
  }

  /**
   * A `403` here is not a failure to report but a smaller screen: the caller
   * may see the project and not its membership, so that section says so and
   * the rest of the page stays usable.
   */
  private handleDetailsError(error: unknown): void {
    if (describeApiError(error).status === 403) {
      this.detailsForbidden.set(true);

      return;
    }

    this.detailsFailure.set(error);
  }

  protected toggleStatus(): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (tenantId === null || project === null || this.statusChanging()) {
      return;
    }

    const target = project.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE';
    this.statusError.set(false);
    this.statusChanging.set(true);
    this.administration
      .changeStatus(tenantId, project.id, target)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.statusChanging.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.project.set({
            ...project,
            status: updated.status,
            updated_at: updated.updated_at,
          }),
        error: () => this.statusError.set(true),
      });
  }

  protected requestVisibilityChange(): void {
    const project = this.project();

    if (project === null || this.visibilityChanging()) {
      return;
    }

    this.visibilityError.set(null);

    if (project.visibility === 'TENANT') {
      this.visibilityConfirmationOpen.set(true);

      return;
    }

    this.changeVisibility('TENANT');
  }

  protected confirmPrivateVisibility(): void {
    this.visibilityConfirmationOpen.set(false);
    this.changeVisibility('PRIVATE');
  }

  protected cancelPrivateVisibility(): void {
    this.visibilityConfirmationOpen.set(false);
  }

  private changeVisibility(target: ProjectVisibility): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (tenantId === null || project === null || this.visibilityChanging()) {
      return;
    }

    this.visibilityError.set(null);
    this.visibilityChanging.set(true);
    this.administration
      .changeVisibility(tenantId, project.id, target)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.visibilityChanging.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.project.set({
            ...project,
            visibility: updated.visibility,
            updated_at: updated.updated_at,
          }),
        error: (failure: unknown) =>
          this.visibilityError.set(
            describeApiError(failure).code === 'PROJECT_PRIVATE_MANAGER_REQUIRED'
              ? 'MANAGER_REQUIRED'
              : 'GENERAL',
          ),
      });
  }

  protected assignRole(): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (
      tenantId === null ||
      project === null ||
      this.assignForm.invalid ||
      this.memberActionId() !== null
    ) {
      this.assignForm.markAllAsTouched();
      return;
    }

    const raw = this.assignForm.getRawValue();
    this.memberError.set(false);
    this.memberActionId.set(raw.membership_id);
    this.administration
      .assignRole(tenantId, project.id, raw.membership_id, raw.role_id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.memberActionId.set(null)),
      )
      .subscribe({
        next: () => {
          this.assignForm.reset({ membership_id: '', role_id: '' });
          this.refreshMembers(tenantId, project.id);
        },
        error: () => this.memberError.set(true),
      });
  }

  protected unassignRole(membershipId: string, roleId: string): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (tenantId === null || project === null || this.memberActionId() !== null) {
      return;
    }

    this.memberError.set(false);
    this.memberActionId.set(membershipId);
    this.administration
      .unassignRole(tenantId, project.id, membershipId, roleId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.memberActionId.set(null)),
      )
      .subscribe({
        next: () => this.refreshMembers(tenantId, project.id),
        error: () => this.memberError.set(true),
      });
  }

  protected linkWorkgroup(): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (
      tenantId === null ||
      project === null ||
      this.linkForm.invalid ||
      this.workgroupActionId() !== null
    ) {
      this.linkForm.markAllAsTouched();
      return;
    }

    const raw = this.linkForm.getRawValue();
    this.workgroupError.set(false);
    this.workgroupActionId.set(raw.workgroup_id);
    this.administration
      .linkWorkgroup(tenantId, project.id, raw.workgroup_id, raw.role_id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.workgroupActionId.set(null)),
      )
      .subscribe({
        next: () => {
          this.linkForm.reset({ workgroup_id: '', role_id: '' });
          this.refreshWorkgroupLinks(tenantId, project.id);
        },
        error: () => this.workgroupError.set(true),
      });
  }

  protected unlinkWorkgroup(workgroupId: string): void {
    const tenantId = this.tenantId();
    const project = this.project();

    if (tenantId === null || project === null || this.workgroupActionId() !== null) {
      return;
    }

    this.workgroupError.set(false);
    this.workgroupActionId.set(workgroupId);
    this.administration
      .unlinkWorkgroup(tenantId, project.id, workgroupId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.workgroupActionId.set(null)),
      )
      .subscribe({
        next: () => this.refreshWorkgroupLinks(tenantId, project.id),
        error: () => this.workgroupError.set(true),
      });
  }

  private refreshMembers(tenantId: string, projectId: string): void {
    this.administration
      .listMembers(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (members) => this.members.set(members),
        error: () => this.memberError.set(true),
      });
  }

  private refreshWorkgroupLinks(tenantId: string, projectId: string): void {
    this.administration
      .listWorkgroupLinks(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (links) => this.workgroupLinks.set(links),
        error: () => this.workgroupError.set(true),
      });
  }
}
