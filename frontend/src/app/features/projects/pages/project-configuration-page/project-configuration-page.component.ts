import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs/operators';
import {
  ConfigurationHistoryEntry,
  CreateProjectIssueTypeRequest,
  IssueHierarchyLevel,
  IssueStatusCategory,
  ProjectConfiguration,
  ProjectIssueType,
  ProjectWorkflow,
  ProjectWorkflowStatus,
  UpdateProjectIssueTypeRequest,
  UpdateWorkflowDraftRequest,
  WorkflowImpact,
  WorkflowTransitionRule,
  WorkflowValidationError,
  WorkflowVersion,
} from '../../../../core/api/api.models';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';
import { problemCode } from '../../../../core/errors/api-error';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { ProjectConfigurationService } from '../../project-configuration.service';

/** One status of the draft, held by code because that is what the API takes. */
interface DraftStatusRow {
  readonly code: string;
  readonly name: string;
  readonly category: IssueStatusCategory;
}

interface DraftTransitionRow {
  readonly code: string;
  readonly name: string;
  readonly from: string;
  readonly to: string;
  readonly isPrimary: boolean;
  readonly permissionCode: string | null;
  /** Carried through untouched; `PUT` replaces the set, so dropping is deleting. */
  readonly rules: readonly WorkflowTransitionRule[];
}

const CATEGORY_KEYS: Readonly<Record<IssueStatusCategory, TranslationKey>> = {
  TO_DO: 'projectConfig.category.toDo',
  IN_PROGRESS: 'projectConfig.category.inProgress',
  DONE: 'projectConfig.category.done',
};

const CATEGORIES: readonly IssueStatusCategory[] = ['TO_DO', 'IN_PROGRESS', 'DONE'];

const STATUS_CODE = /^[A-Z][A-Z0-9_]{1,31}$/;
const TRANSITION_CODE = /^[A-Z][A-Z0-9_]{1,63}$/;
const ISSUE_TYPE_TOKEN = /^[a-z0-9-]{0,48}$/;
const HIERARCHY_LEVELS: readonly IssueHierarchyLevel[] = [1, 0, -1];

/**
 * Project workflow authoring (`WORKFLOW-A-TYPY-ULOH.md` §8), the half of F5.2
 * that was deliberately deferred out of the backend checkpoint.
 *
 * The screen is readable by anyone who may see the project — `GET
 * …/configuration` needs only `project.view` — and every mutation is refused by
 * the server without `project.workflow.manage` or `project.workflow.publish`.
 * So the affordances are shown and the refusal is reported, rather than the
 * client guessing at a project-scoped permission the tenant store does not hold.
 *
 * Two editors are the case this screen is built around. The draft carries an
 * optimistic version and the configuration a revision, and both are sent back
 * with the write: a save that loses the race is answered with `409` and an
 * offer to load what is now stored — never a silent overwrite, and never a
 * silent discard of what is on screen either, because only the person who typed
 * it knows which of the two is worth keeping.
 *
 * Publishing acts on the **stored** draft, not on the unsaved one, so the
 * button says so and stays out of reach until the draft is saved.
 */
@Component({
  selector: 'app-project-configuration-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './project-configuration-page.component.html',
  styleUrl: './project-configuration-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectConfigurationPageComponent implements OnInit {
  /** Bound from the route by `withComponentInputBinding()`. */
  readonly projectId = input.required<string>();

  private readonly configurationService = inject(ProjectConfigurationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly categories = CATEGORIES;
  protected readonly hierarchyLevels = HIERARCHY_LEVELS;
  protected readonly configuration = signal<ProjectConfiguration | null>(null);
  protected readonly history = signal<readonly ConfigurationHistoryEntry[]>([]);
  protected readonly loading = signal(false);
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly selectedWorkflowId = signal<string | null>(null);

  protected readonly editingIssueTypeId = signal<string | null>(null);
  protected readonly issueTypeEditorOpen = signal(false);
  protected readonly issueTypeSaving = signal(false);
  protected readonly issueTypeError = signal<TranslationKey | null>(null);
  protected readonly issueTypeSuccess = signal(false);
  protected readonly archiveCandidate = signal<ProjectIssueType | null>(null);

  protected readonly draftVersion = signal<WorkflowVersion | null>(null);
  protected readonly statuses = signal<readonly DraftStatusRow[]>([]);
  protected readonly transitions = signal<readonly DraftTransitionRow[]>([]);
  protected readonly initialStatusCode = signal<string | null>(null);
  protected readonly dirty = signal(false);

  protected readonly creatingDraft = signal(false);
  protected readonly savingDraft = signal(false);
  protected readonly draftError = signal<TranslationKey | null>(null);
  protected readonly draftConflict = signal(false);

  protected readonly validating = signal(false);
  protected readonly validation = signal<readonly WorkflowValidationError[] | null>(null);
  protected readonly validationClean = signal(false);
  protected readonly impact = signal<WorkflowImpact | null>(null);
  protected readonly loadingImpact = signal(false);
  protected readonly reportError = signal(false);

  protected readonly publishing = signal(false);
  protected readonly publishError = signal<TranslationKey | null>(null);
  protected readonly published = signal(false);
  protected readonly statusMapping = signal<Readonly<Record<string, string>>>({});

  protected readonly workflows = computed<readonly ProjectWorkflow[]>(
    () => this.configuration()?.workflows ?? [],
  );

  protected readonly issueTypes = computed<readonly ProjectIssueType[]>(
    () => this.configuration()?.issue_types ?? [],
  );

  protected readonly assignableWorkflows = computed<readonly ProjectWorkflow[]>(() =>
    this.workflows().filter(
      (workflow) => workflow.status === 'ACTIVE' && workflow.published_version !== null,
    ),
  );

  protected readonly selectedWorkflow = computed<ProjectWorkflow | null>(() => {
    const id = this.selectedWorkflowId();

    return this.workflows().find((workflow) => workflow.id === id) ?? null;
  });

  /** Project statuses that are not in the draft yet, offered for adding. */
  protected readonly availableStatuses = computed<readonly ProjectWorkflowStatus[]>(() => {
    const used = new Set(this.statuses().map((row) => row.code));

    return (this.configuration()?.statuses ?? []).filter(
      (status) => status.status === 'ACTIVE' && !used.has(status.code),
    );
  });

  protected readonly issueTypesUsingWorkflow = computed(() => {
    const workflowId = this.selectedWorkflowId();

    return (this.configuration()?.issue_types ?? []).filter(
      (type) => type.workflow_id === workflowId,
    );
  });

  protected readonly addStatusForm = this.formBuilder.nonNullable.group({
    status_id: [''],
  });

  protected readonly newStatusForm = this.formBuilder.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(STATUS_CODE)]],
    name: ['', [Validators.required, Validators.maxLength(120)]],
    category: ['TO_DO' as IssueStatusCategory, Validators.required],
  });

  protected readonly transitionForm = this.formBuilder.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(TRANSITION_CODE)]],
    name: ['', [Validators.required, Validators.maxLength(120)]],
    from: ['', Validators.required],
    to: ['', Validators.required],
    is_primary: [false],
  });

  protected readonly issueTypeForm = this.formBuilder.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(STATUS_CODE)]],
    name: ['', [Validators.required, Validators.maxLength(120)]],
    description: ['', Validators.maxLength(500)],
    hierarchy_level: this.formBuilder.nonNullable.control<IssueHierarchyLevel>(
      0,
      Validators.required,
    ),
    position: [0, [Validators.required, Validators.min(0), Validators.max(10000)]],
    icon: ['', Validators.pattern(ISSUE_TYPE_TOKEN)],
    color_token: ['', Validators.pattern(ISSUE_TYPE_TOKEN)],
    workflow_id: ['', Validators.required],
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
    this.configurationService
      .configuration(tenantId, this.projectId())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (configuration) => {
          this.configuration.set(configuration);
          this.selectWorkflow(
            this.selectedWorkflowId() ?? configuration.workflows[0]?.id ?? null,
            true,
          );
          this.loadHistory(tenantId);
        },
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected selectWorkflow(workflowId: string | null, force = false): void {
    if (!force && workflowId === this.selectedWorkflowId()) {
      return;
    }

    this.selectedWorkflowId.set(workflowId);
    this.resetReports();
    this.draftError.set(null);
    this.draftConflict.set(false);
    this.publishError.set(null);
    this.published.set(false);

    const workflow = this.workflows().find((candidate) => candidate.id === workflowId) ?? null;
    this.adoptDraft(workflow?.draft_version ?? null);
  }

  protected categoryKey(category: IssueStatusCategory): TranslationKey {
    return CATEGORY_KEYS[category];
  }

  /** `1` is a container above the issue, `-1` a sub-task below it. */
  protected hierarchyKey(level: number): TranslationKey {
    if (level > 0) {
      return 'projectConfig.hierarchy.above';
    }

    return level < 0 ? 'projectConfig.hierarchy.below' : 'projectConfig.hierarchy.standard';
  }

  protected workflowName(workflowId: string | null): string {
    if (workflowId === null) {
      return '—';
    }

    return this.workflows().find((workflow) => workflow.id === workflowId)?.name ?? workflowId;
  }

  protected statusName(code: string): string {
    return this.statuses().find((row) => row.code === code)?.name ?? code;
  }

  /** The published version reads by identifier; the editor reads by code. */
  protected publishedStatusName(version: WorkflowVersion, statusId: string): string {
    return version.statuses.find((status) => status.status_id === statusId)?.name ?? statusId;
  }

  protected beginCreateIssueType(): void {
    this.editingIssueTypeId.set(null);
    this.issueTypeEditorOpen.set(true);
    this.archiveCandidate.set(null);
    this.issueTypeError.set(null);
    this.issueTypeSuccess.set(false);
    this.issueTypeForm.reset({
      code: '',
      name: '',
      description: '',
      hierarchy_level: 0,
      position: this.nextIssueTypePosition(),
      icon: '',
      color_token: '',
      workflow_id: this.assignableWorkflows()[0]?.id ?? '',
    });
  }

  protected beginEditIssueType(issueType: ProjectIssueType): void {
    this.editingIssueTypeId.set(issueType.id);
    this.issueTypeEditorOpen.set(true);
    this.archiveCandidate.set(null);
    this.issueTypeError.set(null);
    this.issueTypeSuccess.set(false);
    this.issueTypeForm.reset({
      code: issueType.code,
      name: issueType.name,
      description: issueType.description,
      hierarchy_level: issueType.hierarchy_level,
      position: issueType.position,
      icon: issueType.icon,
      color_token: issueType.color_token,
      workflow_id: issueType.workflow_id ?? '',
    });
  }

  protected cancelIssueTypeEdit(): void {
    this.editingIssueTypeId.set(null);
    this.issueTypeEditorOpen.set(false);
    this.issueTypeError.set(null);
    this.issueTypeForm.reset();
  }

  protected saveIssueType(): void {
    const tenantId = this.tenantId();
    const configuration = this.configuration();

    if (tenantId === null || configuration === null || this.issueTypeSaving()) {
      return;
    }

    if (this.issueTypeForm.invalid) {
      this.issueTypeForm.markAllAsTouched();
      return;
    }

    const value = this.issueTypeForm.getRawValue();
    const editingId = this.editingIssueTypeId();
    const common = {
      name: value.name.trim(),
      description: value.description.trim(),
      hierarchy_level: value.hierarchy_level,
      position: value.position,
      icon: value.icon.trim(),
      color_token: value.color_token.trim(),
      workflow_id: value.workflow_id,
      expected_config_version: configuration.revision,
    };
    const operation =
      editingId === null
        ? this.configurationService.createIssueType(tenantId, this.projectId(), {
            ...common,
            code: value.code.trim().toUpperCase(),
          } satisfies CreateProjectIssueTypeRequest)
        : this.configurationService.updateIssueType(tenantId, this.projectId(), editingId, {
            ...common,
            expected_type_version: this.issueTypeVersion(editingId),
          } satisfies UpdateProjectIssueTypeRequest);

    this.issueTypeError.set(null);
    this.issueTypeSuccess.set(false);
    this.issueTypeSaving.set(true);
    operation
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.issueTypeSaving.set(false)),
      )
      .subscribe({
        next: () => {
          this.editingIssueTypeId.set(null);
          this.issueTypeEditorOpen.set(false);
          this.issueTypeSuccess.set(true);
          this.refresh();
        },
        error: (failure: unknown) => this.issueTypeError.set(this.issueTypeMessage(failure)),
      });
  }

  protected requestArchiveIssueType(issueType: ProjectIssueType): void {
    this.archiveCandidate.set(issueType);
    this.issueTypeError.set(null);
    this.issueTypeSuccess.set(false);
  }

  protected cancelArchiveIssueType(): void {
    this.archiveCandidate.set(null);
  }

  protected archiveIssueType(): void {
    const tenantId = this.tenantId();
    const configuration = this.configuration();
    const issueType = this.archiveCandidate();

    if (
      tenantId === null ||
      configuration === null ||
      issueType === null ||
      this.issueTypeSaving()
    ) {
      return;
    }

    this.issueTypeError.set(null);
    this.issueTypeSuccess.set(false);
    this.issueTypeSaving.set(true);
    this.configurationService
      .archiveIssueType(tenantId, this.projectId(), issueType.id, {
        expected_config_version: configuration.revision,
        expected_type_version: issueType.version,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.issueTypeSaving.set(false)),
      )
      .subscribe({
        next: () => {
          this.archiveCandidate.set(null);
          this.editingIssueTypeId.set(null);
          this.issueTypeEditorOpen.set(false);
          this.issueTypeSuccess.set(true);
          this.refresh();
        },
        error: (failure: unknown) => this.issueTypeError.set(this.issueTypeMessage(failure)),
      });
  }

  protected createDraft(): void {
    const tenantId = this.tenantId();
    const workflowId = this.selectedWorkflowId();

    if (tenantId === null || workflowId === null || this.creatingDraft()) {
      return;
    }

    this.draftError.set(null);
    this.creatingDraft.set(true);
    this.configurationService
      .createDraft(tenantId, this.projectId(), workflowId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.creatingDraft.set(false)),
      )
      .subscribe({
        next: (draft) => {
          this.adoptDraft(draft);
          this.refresh();
        },
        error: (failure: unknown) => this.draftError.set(this.draftMessage(failure)),
      });
  }

  protected addExistingStatus(): void {
    const statusId = this.addStatusForm.getRawValue().status_id;
    const status = this.availableStatuses().find((candidate) => candidate.id === statusId);

    if (status === undefined) {
      return;
    }

    this.statuses.update((rows) => [
      ...rows,
      { code: status.code, name: status.name, category: status.category },
    ]);
    this.addStatusForm.reset();
    this.markDirty();
  }

  protected addNewStatus(): void {
    if (this.newStatusForm.invalid) {
      this.newStatusForm.markAllAsTouched();
      return;
    }

    const value = this.newStatusForm.getRawValue();

    if (this.statuses().some((row) => row.code === value.code)) {
      this.draftError.set('projectConfig.statusCodeTaken');
      return;
    }

    this.statuses.update((rows) => [
      ...rows,
      { code: value.code, name: value.name, category: value.category },
    ]);
    this.newStatusForm.reset({ category: 'TO_DO' });
    this.markDirty();
  }

  /**
   * Removing a status takes its transitions with it. A transition that starts
   * or ends nowhere is not a smaller mistake than a missing status — the server
   * refuses it either way, so the screen does not offer to build one.
   */
  protected removeStatus(code: string): void {
    this.statuses.update((rows) => rows.filter((row) => row.code !== code));
    this.transitions.update((rows) => rows.filter((row) => row.from !== code && row.to !== code));

    if (this.initialStatusCode() === code) {
      this.initialStatusCode.set(this.statuses()[0]?.code ?? null);
    }

    this.markDirty();
  }

  protected setInitialStatus(code: string): void {
    this.initialStatusCode.set(code);
    this.markDirty();
  }

  protected addTransition(): void {
    if (this.transitionForm.invalid) {
      this.transitionForm.markAllAsTouched();
      return;
    }

    const value = this.transitionForm.getRawValue();

    if (value.from === value.to) {
      this.draftError.set('projectConfig.transitionSelfLoop');
      return;
    }

    if (this.transitions().some((row) => row.code === value.code)) {
      this.draftError.set('projectConfig.transitionCodeTaken');
      return;
    }

    this.transitions.update((rows) => [
      ...rows,
      {
        code: value.code,
        name: value.name,
        from: value.from,
        to: value.to,
        isPrimary: value.is_primary,
        permissionCode: null,
        rules: [],
      },
    ]);
    this.transitionForm.reset({ is_primary: false });
    this.markDirty();
  }

  protected removeTransition(code: string): void {
    this.transitions.update((rows) => rows.filter((row) => row.code !== code));
    this.markDirty();
  }

  protected togglePrimary(code: string): void {
    this.transitions.update((rows) =>
      rows.map((row) => (row.code === code ? { ...row, isPrimary: !row.isPrimary } : row)),
    );
    this.markDirty();
  }

  protected saveDraft(): void {
    const tenantId = this.tenantId();
    const workflowId = this.selectedWorkflowId();
    const draft = this.draftVersion();
    const initial = this.initialStatusCode();

    if (tenantId === null || workflowId === null || draft === null || this.savingDraft()) {
      return;
    }

    if (initial === null) {
      this.draftError.set('projectConfig.initialRequired');
      return;
    }

    this.draftError.set(null);
    this.draftConflict.set(false);
    this.savingDraft.set(true);
    this.configurationService
      .saveDraft(tenantId, this.projectId(), workflowId, this.draftPayload(draft.version, initial))
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.savingDraft.set(false)),
      )
      .subscribe({
        next: (saved) => {
          this.adoptDraft(saved);
          this.resetReports();
        },
        error: (failure: unknown) => {
          this.draftError.set(this.draftMessage(failure));
          this.draftConflict.set(problemCode(failure) === 'WORKFLOW_DRAFT_CONFLICT');
        },
      });
  }

  /**
   * Takes the stored draft after a lost race. It deliberately replaces what is
   * on screen: merging two graphs is not a thing a client can do correctly, and
   * pretending otherwise would publish somebody's half-understood mixture.
   */
  protected reloadDraft(): void {
    this.draftConflict.set(false);
    this.draftError.set(null);
    this.refresh();
  }

  protected validateDraft(): void {
    const tenantId = this.tenantId();
    const workflowId = this.selectedWorkflowId();

    if (tenantId === null || workflowId === null || this.validating()) {
      return;
    }

    this.reportError.set(false);
    this.validating.set(true);
    this.configurationService
      .validate(tenantId, this.projectId(), workflowId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.validating.set(false)),
      )
      .subscribe({
        next: (result) => {
          this.validation.set(result.validation_errors);
          this.validationClean.set(result.valid);
        },
        error: () => this.reportError.set(true),
      });
  }

  protected loadImpact(): void {
    const tenantId = this.tenantId();
    const workflowId = this.selectedWorkflowId();

    if (tenantId === null || workflowId === null || this.loadingImpact()) {
      return;
    }

    this.reportError.set(false);
    this.loadingImpact.set(true);
    this.configurationService
      .impact(tenantId, this.projectId(), workflowId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loadingImpact.set(false)),
      )
      .subscribe({
        next: (report) => this.impact.set(report),
        error: () => this.reportError.set(true),
      });
  }

  protected mappingTarget(code: string): string {
    return this.statusMapping()[code] ?? '';
  }

  protected setMappingTarget(code: string, target: string): void {
    this.statusMapping.update((mapping) => ({ ...mapping, [code]: target }));
  }

  protected publish(): void {
    const tenantId = this.tenantId();
    const workflowId = this.selectedWorkflowId();
    const configuration = this.configuration();

    if (tenantId === null || workflowId === null || configuration === null || this.publishing()) {
      return;
    }

    const mapping = this.publishMapping();
    this.publishError.set(null);
    this.published.set(false);
    this.publishing.set(true);
    this.configurationService
      .publish(tenantId, this.projectId(), workflowId, {
        expected_config_version: this.impact()?.expected_config_version ?? configuration.revision,
        ...(Object.keys(mapping).length === 0 ? {} : { status_mapping: mapping }),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.publishing.set(false)),
      )
      .subscribe({
        next: () => {
          this.published.set(true);
          this.statusMapping.set({});
          this.refresh();
        },
        error: (failure: unknown) => {
          this.publishError.set(this.publishMessage(failure));

          // A migration is not a refusal, it is a missing answer: fetch the
          // report so the removed statuses that still carry issues can be named.
          if (problemCode(failure) === 'WORKFLOW_MIGRATION_REQUIRED') {
            this.loadImpact();
          }
        },
      });
  }

  private publishMapping(): Record<string, string> {
    const required = this.impact()?.required_status_mapping_codes ?? [];
    const mapping = this.statusMapping();
    const filtered: Record<string, string> = {};

    for (const code of required) {
      const target = mapping[code];

      if (target !== undefined && target !== '') {
        filtered[code] = target;
      }
    }

    return filtered;
  }

  private nextIssueTypePosition(): number {
    return this.issueTypes().reduce(
      (highest, issueType) => Math.max(highest, issueType.position + 10),
      0,
    );
  }

  private issueTypeVersion(issueTypeId: string): number {
    return this.issueTypes().find((issueType) => issueType.id === issueTypeId)?.version ?? 0;
  }

  private issueTypeMessage(failure: unknown): TranslationKey {
    switch (problemCode(failure)) {
      case 'PROJECT_CONFIG_VERSION_CONFLICT':
      case 'ISSUE_TYPE_VERSION_CONFLICT':
        return 'projectConfig.issueTypeConflict';
      case 'ISSUE_TYPE_CODE_TAKEN':
        return 'projectConfig.issueTypeCodeTaken';
      case 'ISSUE_TYPE_HIERARCHY_IN_USE':
        return 'projectConfig.issueTypeHierarchyInUse';
      case 'ISSUE_TYPE_ARCHIVED':
        return 'projectConfig.issueTypeArchived';
      default:
        return 'projectConfig.issueTypeSaveError';
    }
  }

  private draftPayload(expectedVersion: number, initial: string): UpdateWorkflowDraftRequest {
    return {
      expected_version: expectedVersion,
      initial_status_code: initial,
      statuses: this.statuses().map((row, index) => ({
        code: row.code,
        name: row.name,
        category: row.category,
        position: index,
      })),
      transitions: this.transitions().map((row, index) => ({
        code: row.code,
        name: row.name,
        from: row.from,
        to: row.to,
        permission_code: row.permissionCode,
        is_primary: row.isPrimary,
        position: index,
        rules: row.rules.map((rule) => ({
          type: rule.type,
          key: rule.key,
          configuration: rule.configuration,
          position: rule.position,
        })),
      })),
    };
  }

  private adoptDraft(draft: WorkflowVersion | null): void {
    this.draftVersion.set(draft);
    this.dirty.set(false);

    if (draft === null) {
      this.statuses.set([]);
      this.transitions.set([]);
      this.initialStatusCode.set(null);
      return;
    }

    const codeById = new Map(draft.statuses.map((status) => [status.status_id, status.code]));

    this.statuses.set(
      draft.statuses.map((status) => ({
        code: status.code,
        name: status.name,
        category: status.category,
      })),
    );
    this.transitions.set(
      draft.transitions.map((transition) => ({
        code: transition.code,
        name: transition.name,
        from: codeById.get(transition.from_status_id) ?? '',
        to: codeById.get(transition.to_status_id) ?? '',
        isPrimary: transition.is_primary,
        permissionCode: transition.permission_code,
        rules: transition.rules,
      })),
    );
    this.initialStatusCode.set(
      draft.initial_status_id === null ? null : (codeById.get(draft.initial_status_id) ?? null),
    );
  }

  private loadHistory(tenantId: string): void {
    this.configurationService
      .history(tenantId, this.projectId())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        // The log is context, not the screen: a caller who may read the
        // configuration but not manage it simply does not get it.
        next: (entries) => this.history.set(entries),
        error: () => this.history.set([]),
      });
  }

  private markDirty(): void {
    this.dirty.set(true);
    this.resetReports();
  }

  private resetReports(): void {
    this.validation.set(null);
    this.validationClean.set(false);
    this.impact.set(null);
    this.reportError.set(false);
  }

  private draftMessage(failure: unknown): TranslationKey {
    switch (problemCode(failure)) {
      case 'WORKFLOW_DRAFT_EXISTS':
        return 'projectConfig.draftExists';
      case 'WORKFLOW_DRAFT_CONFLICT':
        return 'projectConfig.draftConflict';
      case 'WORKFLOW_DRAFT_INVALID':
        return 'projectConfig.draftInvalid';
      default:
        return 'projectConfig.draftError';
    }
  }

  private publishMessage(failure: unknown): TranslationKey {
    switch (problemCode(failure)) {
      case 'PROJECT_CONFIG_VERSION_CONFLICT':
        return 'projectConfig.publishConflict';
      case 'WORKFLOW_MIGRATION_REQUIRED':
        return 'projectConfig.migrationRequired';
      case 'WORKFLOW_MIGRATION_TARGET_INVALID':
        return 'projectConfig.migrationTargetInvalid';
      case 'WORKFLOW_INVALID':
        return 'projectConfig.publishInvalid';
      default:
        return 'projectConfig.publishError';
    }
  }
}
