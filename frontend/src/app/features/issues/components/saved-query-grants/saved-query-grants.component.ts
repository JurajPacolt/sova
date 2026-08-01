import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import {
  isProblemDetails,
  SavedQueryAccess,
  SavedQueryGrantInput,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { IssueWorkspaceService } from '../../issue-workspace.service';

interface GrantRow {
  readonly principalId: string;
  readonly kind: 'MEMBER' | 'WORKGROUP';
  readonly displayName: string;
  readonly access: SavedQueryAccess;
}

/**
 * Who else holds one saved query.
 *
 * **Sharing is not access.** A grant lets somebody run the query; the rows it
 * returns are still intersected with their own `issue.view` scope every time it
 * runs, so a shared query legitimately answers differently for different
 * people. Nothing here hands out issues, and the wording says so.
 *
 * The endpoint replaces the whole set rather than patching it, so this editor
 * keeps the full list locally and sends all of it — a principal removed here
 * really loses access, which a partial update could not guarantee.
 */
@Component({
  selector: 'app-saved-query-grants',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './saved-query-grants.component.html',
  styleUrl: './saved-query-grants.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SavedQueryGrantsComponent implements OnInit {
  readonly savedQueryId = input.required<string>();

  /** Raised after a successful save: visibility follows the grants. */
  readonly changed = output<void>();

  private readonly workspace = inject(IssueWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly rows = signal<readonly GrantRow[]>([]);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<TranslationKey | null>(null);
  protected readonly saved = signal(false);

  protected readonly candidates = signal<readonly GrantRow[]>([]);
  protected readonly selectedPrincipal = signal('');
  protected readonly selectedAccess = signal<SavedQueryAccess>('VIEW');

  /** Only principals not already listed, so the same one cannot be added twice. */
  protected readonly available = computed(() => {
    const taken = new Set(this.rows().map((row) => row.principalId));

    return this.candidates().filter((candidate) => !taken.has(candidate.principalId));
  });

  ngOnInit(): void {
    this.loadGrants();
    this.loadCandidates();
  }

  protected add(): void {
    const principalId = this.selectedPrincipal();
    const candidate = this.available().find((entry) => entry.principalId === principalId);

    if (candidate === undefined) {
      return;
    }

    this.saved.set(false);
    this.rows.update((current) => [...current, { ...candidate, access: this.selectedAccess() }]);
    this.selectedPrincipal.set('');
  }

  protected remove(principalId: string): void {
    this.saved.set(false);
    this.rows.update((current) => current.filter((row) => row.principalId !== principalId));
  }

  protected changeAccess(principalId: string, access: string): void {
    if (access !== 'VIEW' && access !== 'EDIT') {
      return;
    }

    this.saved.set(false);
    this.rows.update((current) =>
      current.map((row) => (row.principalId === principalId ? { ...row, access } : row)),
    );
  }

  protected save(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.saved.set(false);

    const grants: readonly SavedQueryGrantInput[] = this.rows().map((row) => ({
      membership_id: row.kind === 'MEMBER' ? row.principalId : null,
      workgroup_id: row.kind === 'WORKGROUP' ? row.principalId : null,
      access: row.access,
    }));

    this.workspace
      .replaceSavedQueryGrants(tenantId, this.savedQueryId(), { grants })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => {
          this.saved.set(true);
          this.changed.emit();
        },
        error: (failure: unknown) => this.error.set(this.messageFor(failure)),
      });
  }

  private loadGrants(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.loading.set(true);

    this.workspace
      .savedQueryGrants(tenantId, this.savedQueryId())
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (grants) =>
          this.rows.set(
            grants.map((grant) => ({
              principalId: grant.membership_id ?? grant.workgroup_id ?? '',
              kind: grant.membership_id !== null ? 'MEMBER' : 'WORKGROUP',
              displayName: grant.display_name ?? '',
              access: grant.access,
            })),
          ),
        error: () => this.error.set('savedQuery.grants.loadError'),
      });
  }

  /**
   * A grant may only name an active principal of this tenant, so a disabled
   * member or an archived group is never offered — the server would reject it
   * with the same answer it gives for one that does not exist.
   */
  private loadCandidates(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.workspace
      .members(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (memberships) =>
          this.candidates.update((current) => [
            ...current,
            ...memberships
              .filter((membership) => membership.status === 'ACTIVE')
              .map((membership): GrantRow => ({
                principalId: membership.id,
                kind: 'MEMBER',
                displayName: membership.user.display_name,
                access: 'VIEW',
              })),
          ]),
        error: () => undefined,
      });

    this.workspace
      .workgroups(tenantId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (workgroups) =>
          this.candidates.update((current) => [
            ...current,
            ...workgroups
              .filter((workgroup) => workgroup.status === 'ACTIVE')
              .map((workgroup): GrantRow => ({
                principalId: workgroup.id,
                kind: 'WORKGROUP',
                displayName: workgroup.name,
                access: 'VIEW',
              })),
          ]),
        error: () => undefined,
      });
  }

  private messageFor(failure: unknown): TranslationKey {
    const body =
      typeof failure === 'object' && failure !== null && 'error' in failure
        ? (failure as { error: unknown }).error
        : null;

    return isProblemDetails(body) && body.code === 'SAVED_QUERY_GRANT_INVALID'
      ? 'savedQuery.grants.invalid'
      : 'savedQuery.grants.saveError';
  }
}
