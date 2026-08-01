import { DatePipe } from '@angular/common';
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
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  Issue,
  IssueAttachment,
  IssueComment,
  IssueHistoryEntry,
  IssueLink,
  IssueLinkRelation,
  IssueLinkType,
  IssuePriority,
  IssueTransition,
} from '../../../../core/api/api.models';
import { describeApiError, problemCode } from '../../../../core/errors/api-error';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { IssueWorkspaceService } from '../../issue-workspace.service';
import { issuePriorityKey } from '../../issue-labels';

/**
 * The issue detail.
 *
 * Each section loads on its own, so losing one — a `403` on comments, say —
 * leaves the rest of the screen usable instead of blanking it. The transition
 * list always carries the version it was computed against, and that version is
 * what gets sent back, which is how a concurrent change is detected rather than
 * silently overwritten.
 */
@Component({
  selector: 'app-issue-detail-page',
  standalone: true,
  imports: [
    DatePipe,
    ErrorStateComponent,
    PageHeaderComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './issue-detail-page.component.html',
  styleUrl: './issue-detail-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IssueDetailPageComponent implements OnInit {
  readonly issueKey = input.required<string>();

  private readonly workspace = inject(IssueWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly issue = signal<Issue | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadFailure = signal<unknown>(null);
  /** The key resolved to nothing — no request failed, the issue is simply not there. */
  protected readonly missing = signal(false);

  /**
   * A `404` says the same thing as an empty resolution: the issue does not
   * exist, or it sits in a project this reader cannot see. The server answers
   * both the same way on purpose, and so does this screen.
   */
  protected readonly loadErrorKey = computed<TranslationKey | null>(() => {
    const failure = this.loadFailure();

    return failure !== null && describeApiError(failure).status === 404
      ? 'issue.detail.notFound'
      : null;
  });

  protected readonly transitions = signal<readonly IssueTransition[]>([]);
  protected readonly transitionVersion = signal<number>(0);
  protected readonly transitionError = signal<TranslationKey | null>(null);
  protected readonly busyTransitionId = signal<string | null>(null);
  protected readonly resolutionControl = new FormControl('', { nonNullable: true });

  protected readonly comments = signal<readonly IssueComment[]>([]);
  protected readonly commentsAvailable = signal(true);
  protected readonly commentError = signal<TranslationKey | null>(null);
  protected readonly commentSubmitting = signal(false);
  protected readonly commentControl = new FormControl('', { nonNullable: true });

  protected readonly attachments = signal<readonly IssueAttachment[]>([]);
  protected readonly attachmentsAvailable = signal(true);
  protected readonly attachmentError = signal<TranslationKey | null>(null);
  protected readonly attachmentUploading = signal(false);

  protected readonly links = signal<readonly IssueLink[]>([]);
  protected readonly linksAvailable = signal(true);
  protected readonly linkError = signal<TranslationKey | null>(null);
  protected readonly linkSubmitting = signal(false);
  protected readonly linkKeyControl = new FormControl('', { nonNullable: true });
  protected readonly linkTypeControl = new FormControl<IssueLinkType>('RELATES_TO', {
    nonNullable: true,
  });

  protected readonly history = signal<readonly IssueHistoryEntry[]>([]);
  protected readonly historyAvailable = signal(true);

  protected readonly watching = signal(false);
  protected readonly watcherCount = signal(0);
  protected readonly watchersAvailable = signal(true);

  protected readonly resolutionRequired = computed(() =>
    this.transitions().some((transition) => transition.required_fields.includes('resolution')),
  );

  ngOnInit(): void {
    this.reload();
  }

  /** Everything the screen is made of, which is what "try again" means here. */
  protected reload(): void {
    this.loading.set(true);
    this.loadFailure.set(null);
    this.missing.set(false);
    this.resolve();
  }

  protected execute(transition: IssueTransition): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null || this.busyTransitionId() !== null) {
      return;
    }

    const resolution = this.resolutionControl.value.trim();

    if (transition.required_fields.includes('resolution') && resolution === '') {
      this.transitionError.set('issue.detail.resolutionRequired');

      return;
    }

    this.busyTransitionId.set(transition.id);
    this.transitionError.set(null);

    this.workspace
      .executeTransition(tenantId, issue.id, transition.id, {
        // The version the offered list was computed against, so a concurrent
        // change is reported instead of quietly overwritten.
        expected_issue_version: this.transitionVersion(),
        ...(resolution === '' ? {} : { fields: { resolution } }),
      })
      .pipe(
        finalize(() => this.busyTransitionId.set(null)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.issue.set(response.issue);
          this.resolutionControl.setValue('');
          this.loadTransitions(tenantId, response.issue.id);
          this.loadHistory(tenantId, response.issue.id);
        },
        error: () => this.transitionError.set('issue.detail.transitionError'),
      });
  }

  protected submitComment(): void {
    const tenantId = this.tenantId();
    const issue = this.issue();
    const body = this.commentControl.value.trim();

    if (tenantId === null || issue === null || body === '' || this.commentSubmitting()) {
      return;
    }

    this.commentSubmitting.set(true);
    this.commentError.set(null);

    this.workspace
      .addComment(tenantId, issue.id, body)
      .pipe(
        finalize(() => this.commentSubmitting.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.comments.update((current) => [...current, response.comment]);
          // The text is only cleared once the server has it, so a failure never
          // loses what the user wrote.
          this.commentControl.setValue('');
          this.loadHistory(tenantId, issue.id);
        },
        error: () => this.commentError.set('issue.comments.error'),
      });
  }

  protected removeComment(comment: IssueComment): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null) {
      return;
    }

    this.workspace
      .removeComment(tenantId, issue.id, comment.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.loadComments(tenantId, issue.id),
        error: () => this.commentError.set('issue.comments.error'),
      });
  }

  protected toggleWatching(): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null) {
      return;
    }

    const next = !this.watching();

    this.workspace
      .setWatching(tenantId, issue.id, next)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (state) => {
          this.watching.set(state.watching);
          this.loadWatchers(tenantId, issue.id);
        },
        error: () => this.watchersAvailable.set(false),
      });
  }

  /**
   * Keys are what people read and share, so the URL keeps them; SovaQL turns
   * one into the identifier the rest of the API uses.
   */
  private resolve(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      this.loading.set(false);
      this.missing.set(true);

      return;
    }

    this.workspace
      .findByKey(tenantId, this.issueKey())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          const hit = response.issues[0];

          if (hit === undefined) {
            this.loading.set(false);
            this.missing.set(true);

            return;
          }

          this.load(tenantId, hit.id);
        },
        error: (failure: unknown) => {
          this.loading.set(false);
          this.loadFailure.set(failure);
        },
      });
  }

  private load(tenantId: string, issueId: string): void {
    this.workspace
      .get(tenantId, issueId)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => this.issue.set(response.issue),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });

    this.loadTransitions(tenantId, issueId);
    this.loadComments(tenantId, issueId);
    this.loadAttachments(tenantId, issueId);
    this.loadLinks(tenantId, issueId);
    this.loadHistory(tenantId, issueId);
    this.loadWatchers(tenantId, issueId);
  }

  protected priorityKey(priority: IssuePriority): TranslationKey {
    return issuePriorityKey(priority);
  }

  protected relationKey(relation: IssueLinkRelation): TranslationKey {
    switch (relation) {
      case 'BLOCKS':
        return 'issue.links.blocks';
      case 'IS_BLOCKED_BY':
        return 'issue.links.isBlockedBy';
      case 'DUPLICATES':
        return 'issue.links.duplicates';
      case 'IS_DUPLICATED_BY':
        return 'issue.links.isDuplicatedBy';
      default:
        return 'issue.links.relatesTo';
    }
  }

  protected scanNoticeKey(attachment: IssueAttachment): TranslationKey | null {
    switch (attachment.scan_status) {
      case 'PENDING':
        return 'issue.attachments.pending';
      case 'INFECTED':
        return 'issue.attachments.infected';
      case 'SKIPPED':
        // Recorded honestly rather than shown as a clean verdict.
        return 'issue.attachments.unscanned';
      default:
        return null;
    }
  }

  protected selectFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    // The picker is reset either way, so choosing the same file twice in a row
    // still fires a change event.
    input.value = '';

    if (file !== null) {
      this.uploadFile(file);
    }
  }

  protected downloadAttachment(attachment: IssueAttachment): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null) {
      return;
    }

    this.workspace
      .downloadAttachment(tenantId, issue.id, attachment.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (blob) => this.saveBlob(blob, attachment.name),
        error: () => this.attachmentError.set('issue.attachments.error'),
      });
  }

  protected removeAttachment(attachment: IssueAttachment): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null) {
      return;
    }

    this.workspace
      .removeAttachment(tenantId, issue.id, attachment.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.loadAttachments(tenantId, issue.id),
        error: () => this.attachmentError.set('issue.attachments.error'),
      });
  }

  protected submitLink(): void {
    const tenantId = this.tenantId();
    const issue = this.issue();
    const key = this.linkKeyControl.value.trim().toUpperCase();

    if (tenantId === null || issue === null || key === '' || this.linkSubmitting()) {
      return;
    }

    this.linkSubmitting.set(true);
    this.linkError.set(null);

    // The API links by identifier, so the readable key is resolved first — the
    // same lookup the detail route already uses.
    this.workspace
      .findByKey(tenantId, key)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          const target = response.issues[0];

          if (target === undefined) {
            this.linkSubmitting.set(false);
            this.linkError.set('issue.links.notFound');

            return;
          }

          this.createLink(tenantId, issue.id, target.id);
        },
        error: () => {
          this.linkSubmitting.set(false);
          this.linkError.set('issue.links.notFound');
        },
      });
  }

  protected removeLink(link: IssueLink): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null) {
      return;
    }

    this.workspace
      .removeLink(tenantId, issue.id, link.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => this.loadLinks(tenantId, issue.id),
        error: () => this.linkError.set('issue.links.error'),
      });
  }

  private createLink(tenantId: string, issueId: string, targetIssueId: string): void {
    this.workspace
      .addLink(tenantId, issueId, {
        target_issue_id: targetIssueId,
        link_type: this.linkTypeControl.value,
      })
      .pipe(
        finalize(() => this.linkSubmitting.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (response) => {
          this.links.set(response.links);
          this.linkKeyControl.setValue('');
          this.loadHistory(tenantId, issueId);
        },
        error: (failure: unknown) => this.linkError.set(this.linkMessageFor(failure)),
      });
  }

  private uploadFile(file: File): void {
    const tenantId = this.tenantId();
    const issue = this.issue();

    if (tenantId === null || issue === null || this.attachmentUploading()) {
      return;
    }

    this.attachmentUploading.set(true);
    this.attachmentError.set(null);

    this.workspace
      .uploadAttachment(tenantId, issue.id, file)
      .pipe(
        finalize(() => this.attachmentUploading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.loadAttachments(tenantId, issue.id);
          this.loadHistory(tenantId, issue.id);
        },
        error: (failure: unknown) => this.attachmentError.set(this.uploadMessageFor(failure)),
      });
  }

  /**
   * The server decides what may be attached — it sniffs the bytes rather than
   * trusting the name — so the client only translates the answer.
   */
  private uploadMessageFor(failure: unknown): TranslationKey {
    switch (problemCode(failure)) {
      case 'ATTACHMENT_TOO_LARGE':
        return 'issue.attachments.tooLarge';
      case 'ATTACHMENT_TYPE_NOT_ALLOWED':
        return 'issue.attachments.typeRejected';
      default:
        return 'issue.attachments.error';
    }
  }

  private linkMessageFor(failure: unknown): TranslationKey {
    switch (problemCode(failure)) {
      case 'ISSUE_LINK_EXISTS':
        return 'issue.links.exists';
      case 'ISSUE_NOT_FOUND':
        return 'issue.links.notFound';
      default:
        return 'issue.links.error';
    }
  }

  /**
   * The bytes already arrived through the authenticated client, so the browser
   * only has to be handed a temporary object URL. It is revoked immediately —
   * leaving it alive would keep the file in memory for the whole session.
   */
  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
  }

  private loadAttachments(tenantId: string, issueId: string): void {
    this.workspace
      .attachments(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.attachments.set(response.attachments);
          this.attachmentsAvailable.set(true);
        },
        error: () => this.attachmentsAvailable.set(false),
      });
  }

  private loadLinks(tenantId: string, issueId: string): void {
    this.workspace
      .links(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.links.set(response.links);
          this.linksAvailable.set(true);
        },
        error: () => this.linksAvailable.set(false),
      });
  }

  private loadTransitions(tenantId: string, issueId: string): void {
    this.workspace
      .transitions(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.transitions.set(response.transitions);
          this.transitionVersion.set(response.issue_version);
        },
        error: () => this.transitions.set([]),
      });
  }

  private loadComments(tenantId: string, issueId: string): void {
    this.workspace
      .comments(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.comments.set(response.comments);
          this.commentsAvailable.set(true);
        },
        // One forbidden section must not take the whole screen down.
        error: () => this.commentsAvailable.set(false),
      });
  }

  private loadHistory(tenantId: string, issueId: string): void {
    this.workspace
      .history(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.history.set(response.history);
          this.historyAvailable.set(true);
        },
        error: () => this.historyAvailable.set(false),
      });
  }

  private loadWatchers(tenantId: string, issueId: string): void {
    this.workspace
      .watchers(tenantId, issueId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.watching.set(response.watching);
          this.watcherCount.set(response.watchers.length);
          this.watchersAvailable.set(true);
        },
        error: () => this.watchersAvailable.set(false),
      });
  }
}
