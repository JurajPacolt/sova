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
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  IssueSearchHit,
  IssueTransition,
  ProjectWorkflowStatus,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { IssueWorkspaceService } from '../../issue-workspace.service';

interface BoardColumn {
  readonly status: ProjectWorkflowStatus;
  readonly issues: readonly IssueSearchHit[];
}

/**
 * The Kanban board of one project.
 *
 * A board is necessarily per project: its columns are that project's statuses,
 * and two projects can run entirely different workflows. There is no
 * cross-project board for the same reason there is no cross-project workflow.
 *
 * Moving a card is a **transition**, never a direct status write — the client
 * sends a transition identifier and the version it saw, exactly like the detail
 * screen, so the backend keeps deciding what is legal. The available moves for
 * a card are fetched when the user asks for them rather than for every card up
 * front, which would be one request per issue on every load.
 */
@Component({
  selector: 'app-issue-board-page',
  standalone: true,
  imports: [PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './issue-board-page.component.html',
  styleUrl: './issue-board-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IssueBoardPageComponent implements OnInit {
  readonly projectId = input.required<string>();

  private readonly workspace = inject(IssueWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly projectCode = signal('');
  protected readonly loading = signal(true);
  protected readonly loadError = signal<TranslationKey | null>(null);
  protected readonly moveError = signal<TranslationKey | null>(null);

  private readonly statuses = signal<readonly ProjectWorkflowStatus[]>([]);
  private readonly issues = signal<readonly IssueSearchHit[]>([]);

  protected readonly openCardId = signal<string | null>(null);
  protected readonly moves = signal<readonly IssueTransition[]>([]);
  protected readonly movesVersion = signal(0);
  protected readonly movesLoading = signal(false);
  protected readonly moving = signal(false);

  protected readonly columns = computed<readonly BoardColumn[]>(() => {
    const grouped = new Map<string, IssueSearchHit[]>();

    for (const issue of this.issues()) {
      const bucket = grouped.get(issue.status.code);

      if (bucket === undefined) {
        grouped.set(issue.status.code, [issue]);
      } else {
        bucket.push(issue);
      }
    }

    return this.statuses().map((status) => ({
      status,
      issues: grouped.get(status.code) ?? [],
    }));
  });

  ngOnInit(): void {
    this.load();
  }

  protected toggleMoves(issue: IssueSearchHit): void {
    if (this.openCardId() === issue.id) {
      this.openCardId.set(null);

      return;
    }

    const tenantId = this.tenantId();
    this.openCardId.set(issue.id);
    this.moves.set([]);
    this.moveError.set(null);

    if (tenantId === null) {
      return;
    }

    this.movesLoading.set(true);

    this.workspace
      .transitions(tenantId, issue.id)
      .pipe(
        finalize(() => this.movesLoading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.moves.set(response.transitions);
          this.movesVersion.set(response.issue_version);
        },
        error: () => this.moves.set([]),
      });
  }

  protected move(issue: IssueSearchHit, transition: IssueTransition): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.moving()) {
      return;
    }

    // A transition that needs extra input belongs on the detail screen, where
    // there is room to ask for it properly. Sending it blind would fail with
    // ISSUE_TRANSITION_INVALID and look like a bug to the user.
    if (transition.required_fields.length > 0) {
      this.openCardId.set(null);
      this.moveError.set('issue.detail.resolutionRequired');

      return;
    }

    this.moving.set(true);
    this.moveError.set(null);

    this.workspace
      .executeTransition(tenantId, issue.id, transition.id, {
        expected_issue_version: this.movesVersion(),
      })
      .pipe(
        finalize(() => this.moving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.openCardId.set(null);
          // The card only settles in its new column once the server agreed, so
          // a refused move never leaves the board showing something untrue.
          this.loadIssues(tenantId, this.projectCode());
        },
        error: () => {
          this.openCardId.set(null);
          this.moveError.set('issue.board.moveError');
        },
      });
  }

  private load(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loading.set(false);
      this.loadError.set('issue.board.loadError');

      return;
    }

    // SovaQL addresses projects by their immutable code, so the identifier from
    // the route is resolved through the project list the caller may see.
    this.workspace
      .projects(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (projects) => {
          const project = projects.find((candidate) => candidate.id === this.projectId());

          if (project === undefined) {
            this.loading.set(false);
            this.loadError.set('issue.board.projectNotFound');

            return;
          }

          this.projectCode.set(project.code);
          this.loadStatuses(tenantId);
          this.loadIssues(tenantId, project.code);
        },
        error: () => {
          this.loading.set(false);
          this.loadError.set('issue.board.loadError');
        },
      });
  }

  private loadStatuses(tenantId: string): void {
    this.workspace
      .configuration(tenantId, this.projectId())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (configuration) =>
          this.statuses.set(
            [...configuration.statuses]
              .filter((status) => status.status === 'ACTIVE')
              .sort((left, right) => left.position - right.position),
          ),
        error: () => this.statuses.set([]),
      });
  }

  private loadIssues(tenantId: string, projectCode: string): void {
    this.workspace
      .search(tenantId, {
        query: `project = ${projectCode} ORDER BY priority DESC, updated DESC`,
        page_size: 100,
      })
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => this.issues.set(response.issues),
        error: () => this.loadError.set('issue.board.loadError'),
      });
  }
}
