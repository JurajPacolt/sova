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
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  ProjectListItem,
  ProjectStatus,
  ProjectVisibility,
  TenantMembership,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { ProjectAdministrationService } from '../../project-administration.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { FocusSummaryDirective } from '../../../../core/a11y/focus-summary.directive';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

type StatusFilter = 'ALL' | ProjectStatus;

@Component({
  selector: 'app-project-list-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    FocusSummaryDirective,
    PageHeaderComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './project-list-page.component.html',
  styleUrl: './project-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectListPageComponent implements OnInit {
  private readonly administration = inject(ProjectAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly projects = signal<readonly ProjectListItem[]>([]);
  protected readonly memberships = signal<readonly TenantMembership[]>([]);
  protected readonly loading = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);

  protected readonly search = signal('');
  protected readonly statusFilter = signal<StatusFilter>('ACTIVE');

  protected readonly activeProjectCount = computed(
    () => this.projects().filter((project) => project.status === 'ACTIVE').length,
  );

  protected readonly visibleProjects = computed(() => {
    const term = this.search().trim().toLocaleLowerCase();
    const status = this.statusFilter();

    return this.projects().filter((project) => {
      if (status !== 'ALL' && project.status !== status) {
        return false;
      }

      if (term === '') {
        return true;
      }

      return (
        project.name.toLocaleLowerCase().includes(term) ||
        project.code.toLocaleLowerCase().includes(term)
      );
    });
  });

  protected readonly createFormVisible = signal(false);
  protected readonly creating = signal(false);
  protected readonly createError = signal<TranslationKey | null>(null);

  protected readonly createForm = this.formBuilder.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^[A-Za-z][A-Za-z0-9]{1,9}$/)]],
    name: ['', [Validators.required, Validators.maxLength(160)]],
    description: ['', Validators.maxLength(500)],
    visibility: this.formBuilder.nonNullable.control<ProjectVisibility>('TENANT'),
    lead_membership_id: [''],
  });

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
    this.administration
      .list(tenantId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (projects) => this.projects.set(projects),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected updateSearch(value: string): void {
    this.search.set(value);
  }

  protected updateStatusFilter(value: string): void {
    this.statusFilter.set(
      value === 'ACTIVE' || value === 'ARCHIVED' || value === 'ALL' ? value : 'ACTIVE',
    );
  }

  protected toggleCreateForm(): void {
    const opening = !this.createFormVisible();
    this.createFormVisible.set(opening);

    if (!opening) {
      return;
    }

    this.createError.set(null);
    this.createForm.reset({
      code: '',
      name: '',
      description: '',
      visibility: 'TENANT',
      lead_membership_id: '',
    });
    this.loadMemberships();
  }

  protected createProject(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.creating()) {
      return;
    }

    const raw = this.createForm.getRawValue();

    // The API requires a lead for a private project; catch it before the round trip.
    if (raw.visibility === 'PRIVATE' && raw.lead_membership_id === '') {
      this.createError.set('projects.leadRequired');
      return;
    }

    if (this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      return;
    }

    this.createError.set(null);
    this.creating.set(true);
    this.administration
      .create(tenantId, {
        code: raw.code,
        name: raw.name,
        description: raw.description,
        visibility: raw.visibility,
        ...(raw.lead_membership_id === '' ? {} : { lead_membership_id: raw.lead_membership_id }),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.creating.set(false)),
      )
      .subscribe({
        next: () => {
          this.createFormVisible.set(false);
          this.refresh();
        },
        error: (error: unknown) => this.createError.set(this.createErrorKey(error)),
      });
  }

  private createErrorKey(error: unknown): TranslationKey {
    const status = (error as { status?: number } | null)?.status;

    if (status === 403) {
      return 'projects.createForbidden';
    }

    if (status === 409) {
      return 'projects.codeTaken';
    }

    return 'projects.createError';
  }

  private loadMemberships(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.memberships().length > 0) {
      return;
    }

    this.administration
      .listActiveMemberships(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        // A missing membership list only costs the lead picker, so it stays silent.
        next: (memberships) => this.memberships.set(memberships),
        error: () => this.memberships.set([]),
      });
  }
}
