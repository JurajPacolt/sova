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
  IssuePriority,
  IssueSearchHit,
  isProblemDetails,
  ProjectIssueType,
  ProjectListItem,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { QueryEditorComponent } from '../../components/query-editor/query-editor.component';
import { SavedQueryPanelComponent } from '../../components/saved-query-panel/saved-query-panel.component';
import { IssueWorkspaceService } from '../../issue-workspace.service';

/**
 * The issue list is the SovaQL search: there is no second listing endpoint, so
 * the same authorised scope and the same limits apply whether the box is empty
 * or carries a query.
 *
 * Paging appends rather than replaces, because the cursor only walks forwards.
 * A new search always starts from no cursor, since a token from the previous
 * query would rightly be rejected.
 */
@Component({
  selector: 'app-issue-list-page',
  standalone: true,
  imports: [
    PageHeaderComponent,
    QueryEditorComponent,
    ReactiveFormsModule,
    RouterLink,
    SavedQueryPanelComponent,
    TranslatePipe,
  ],
  templateUrl: './issue-list-page.component.html',
  styleUrl: './issue-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IssueListPageComponent implements OnInit {
  private readonly workspace = inject(IssueWorkspaceService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());
  private cursor: string | null = null;

  protected readonly queryText = signal('');

  protected readonly issues = signal<readonly IssueSearchHit[]>([]);
  protected readonly loading = signal(false);
  protected readonly error = signal<TranslationKey | null>(null);
  protected readonly hasMore = signal(false);
  protected readonly searched = signal(false);

  protected readonly count = computed(() => this.issues().length);

  protected readonly createVisible = signal(false);
  protected readonly creating = signal(false);
  protected readonly createError = signal<TranslationKey | null>(null);
  protected readonly availableProjects = signal<readonly ProjectListItem[]>([]);
  protected readonly issueTypes = signal<readonly ProjectIssueType[]>([]);
  protected readonly priorities: readonly IssuePriority[] = ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'];

  protected readonly createForm = this.formBuilder.nonNullable.group({
    projectId: ['', Validators.required],
    issueTypeId: ['', Validators.required],
    title: ['', [Validators.required, Validators.maxLength(255)]],
    description: [''],
    priority: ['NORMAL' as IssuePriority],
  });

  ngOnInit(): void {
    this.run(false);
  }

  protected submit(): void {
    this.run(false);
  }

  protected loadMore(): void {
    this.run(true);
  }

  protected priorityKey(priority: IssuePriority): TranslationKey {
    switch (priority) {
      case 'LOW':
        return 'issue.priority.low';
      case 'HIGH':
        return 'issue.priority.high';
      case 'CRITICAL':
        return 'issue.priority.critical';
      default:
        return 'issue.priority.normal';
    }
  }

  protected toggleCreate(): void {
    const next = !this.createVisible();
    this.createVisible.set(next);
    this.createError.set(null);

    if (next && this.availableProjects().length === 0) {
      this.loadProjects();
    }
  }

  protected projectChanged(): void {
    this.createForm.controls.issueTypeId.setValue('');
    this.issueTypes.set([]);

    const projectId = this.createForm.controls.projectId.value;

    if (projectId !== '') {
      this.loadIssueTypes(projectId);
    }
  }

  protected submitCreate(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.creating() || this.createForm.invalid) {
      this.createForm.markAllAsTouched();

      return;
    }

    const value = this.createForm.getRawValue();
    this.creating.set(true);
    this.createError.set(null);

    this.workspace
      .create(tenantId, value.projectId, {
        issue_type_id: value.issueTypeId,
        title: value.title.trim(),
        description: value.description,
        priority: value.priority,
      })
      .pipe(
        finalize(() => this.creating.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          // Only the title and description are cleared: the project and type
          // are usually the same for the next issue.
          this.createForm.controls.title.setValue('');
          this.createForm.controls.description.setValue('');
          this.createVisible.set(false);
          this.run(false);
        },
        error: (failure: unknown) => this.createError.set(this.createMessageFor(failure)),
      });
  }

  private createMessageFor(failure: unknown): TranslationKey {
    const body =
      typeof failure === 'object' && failure !== null && 'error' in failure
        ? (failure as { error: unknown }).error
        : null;

    return isProblemDetails(body) && body.code === 'ISSUE_TYPE_NOT_AVAILABLE'
      ? 'issue.create.typeUnavailable'
      : 'issue.create.error';
  }

  private loadProjects(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.workspace
      .projects(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (projects) =>
          // Only active projects can take new work; an archived one grants no
          // project permission at all.
          this.availableProjects.set(projects.filter((project) => project.status === 'ACTIVE')),
        error: () => this.availableProjects.set([]),
      });
  }

  /**
   * Types come from the project's own configuration — the client never picks a
   * workflow or an initial status. An archived type, or one without a published
   * workflow, cannot take new issues and is therefore not offered.
   */
  private loadIssueTypes(projectId: string): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.workspace
      .configuration(tenantId, projectId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (configuration) =>
          this.issueTypes.set(
            configuration.issue_types.filter(
              (type) => type.status === 'ACTIVE' && type.workflow_id !== null,
            ),
          ),
        error: () => this.issueTypes.set([]),
      });
  }

  private run(append: boolean): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.loading()) {
      return;
    }

    if (!append) {
      // A cursor belongs to the query it was issued for; starting a new search
      // must not carry the old one over.
      this.cursor = null;
    }

    this.loading.set(true);
    this.error.set(null);

    this.workspace
      .search(tenantId, {
        query: this.queryText().trim(),
        cursor: append ? this.cursor : null,
      })
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.issues.update((current) =>
            append ? [...current, ...response.issues] : [...response.issues],
          );
          this.cursor = response.next_cursor;
          this.hasMore.set(response.next_cursor !== null);
          this.searched.set(true);
        },
        error: (failure: unknown) => {
          if (!append) {
            this.issues.set([]);
            this.hasMore.set(false);
          }

          this.searched.set(true);
          this.error.set(this.messageFor(failure));
        },
      });
  }

  /**
   * A rejected query is the user's to fix, so it reads differently from a
   * server that could not answer at all.
   */
  private messageFor(failure: unknown): TranslationKey {
    const status =
      typeof failure === 'object' && failure !== null && 'status' in failure
        ? (failure as { status: unknown }).status
        : null;

    return status === 422 ? 'issue.list.invalid' : 'issue.list.loadError';
  }
}
