import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  model,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { isProblemDetails, SavedQuery } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { IssueWorkspaceService } from '../../issue-workspace.service';
import { SavedQueryGrantsComponent } from '../saved-query-grants/saved-query-grants.component';

/**
 * Saved SovaQL queries beside the editor: save what is in the box, load one
 * back into it, bookmark, share and retire.
 *
 * Everything this panel offers is an affordance only. `viewer_access` describes
 * the caller rather than the row, so the same query legitimately shows an Edit
 * button to one person and not to another; the backend decides again on every
 * call and the panel simply believes the answer it was given.
 *
 * Loading a query writes its **raw** text back into the editor, not the
 * canonical form — reopening a query should show what its author typed.
 */
@Component({
  selector: 'app-saved-query-panel',
  standalone: true,
  imports: [FormsModule, SavedQueryGrantsComponent, TranslatePipe],
  templateUrl: './saved-query-panel.component.html',
  styleUrl: './saved-query-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SavedQueryPanelComponent implements OnInit {
  /** The editor's text. Loading a saved query replaces it. */
  readonly query = model<string>('');

  /** Raised after a saved query is loaded, so the page can run it. */
  readonly applied = output<void>();

  private readonly workspace = inject(IssueWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly queries = signal<readonly SavedQuery[]>([]);
  protected readonly loading = signal(false);
  protected readonly listError = signal<TranslationKey | null>(null);

  /** True when the caller has no membership to own or be granted a query. */
  protected readonly unavailable = signal(false);

  protected readonly saveVisible = signal(false);
  protected readonly saving = signal(false);
  protected readonly saveError = signal<TranslationKey | null>(null);
  protected readonly name = signal('');

  /** The query currently loaded in the editor, if it came from this panel. */
  protected readonly loadedId = signal<string | null>(null);
  protected readonly sharingId = signal<string | null>(null);
  protected readonly archivedVisible = signal(false);

  protected readonly canCreate = computed(() =>
    this.tenantStore.hasPermission('saved-query.create'),
  );
  protected readonly canShare = computed(
    () =>
      this.tenantStore.hasPermission('saved-query.share') ||
      this.tenantStore.hasPermission('saved-query.manage'),
  );

  /** Favourites first, then by name — the bookmark is the point of having one. */
  protected readonly live = computed(() =>
    [...this.queries().filter((query) => !query.archived)].sort((left, right) => {
      if (left.favourite !== right.favourite) {
        return left.favourite ? -1 : 1;
      }

      return left.name.localeCompare(right.name);
    }),
  );

  protected readonly archived = computed(() => this.queries().filter((query) => query.archived));

  protected readonly loaded = computed(
    () => this.live().find((query) => query.id === this.loadedId()) ?? null,
  );

  /**
   * Whether the loaded query can absorb what is now in the box. `EDIT` is
   * enough — unlike archiving, which stays with the owner.
   */
  protected readonly canUpdateLoaded = computed(() => {
    const loaded = this.loaded();

    return loaded !== null && loaded.viewer_access === 'EDIT';
  });

  ngOnInit(): void {
    this.reload();
  }

  protected toggleSave(): void {
    const next = !this.saveVisible();
    this.saveVisible.set(next);
    this.saveError.set(null);

    if (next && this.name() === '') {
      this.name.set(this.loaded()?.name ?? '');
    }
  }

  protected load(query: SavedQuery): void {
    // The raw text, not the canonical form: reopening should show what the
    // author wrote, and the server normalises again on the next save anyway.
    this.query.set(query.raw_query);
    this.loadedId.set(query.id);
    this.name.set(query.name);
    this.applied.emit();
  }

  protected save(): void {
    const tenantId = this.tenantId();
    const name = this.name().trim();

    if (tenantId === null || this.saving() || name === '') {
      return;
    }

    this.saving.set(true);
    this.saveError.set(null);

    this.workspace
      .saveQuery(tenantId, { name, query: this.query() })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (saved) => {
          this.saveVisible.set(false);
          this.loadedId.set(saved.id);
          this.reload();
        },
        error: (failure: unknown) => this.saveError.set(this.messageFor(failure)),
      });
  }

  /**
   * Overwrites the loaded query with what is in the box. The version the panel
   * saw travels with it, so a concurrent change is reported rather than
   * silently overwritten. The name is not changed here — renaming is a
   * different intention and would collide differently.
   */
  protected update(): void {
    const tenantId = this.tenantId();
    const loaded = this.loaded();

    if (tenantId === null || loaded === null || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.saveError.set(null);

    this.workspace
      .updateSavedQuery(tenantId, loaded.id, {
        expected_version: loaded.version,
        name: loaded.name,
        description: loaded.description,
        query: this.query(),
      })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => this.reload(),
        error: (failure: unknown) => this.saveError.set(this.messageFor(failure)),
      });
  }

  protected toggleFavourite(query: SavedQuery): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.workspace
      .setSavedQueryFavourite(tenantId, query.id, !query.favourite)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (favourite) =>
          this.queries.update((current) =>
            current.map((entry) => (entry.id === query.id ? { ...entry, favourite } : entry)),
          ),
        error: () => this.listError.set('savedQuery.favouriteError'),
      });
  }

  /** Retiring is the owner's call or an administrator's; `EDIT` is not enough. */
  protected canArchive(query: SavedQuery): boolean {
    return query.viewer_is_owner || this.tenantStore.hasPermission('saved-query.manage');
  }

  protected canManageGrants(query: SavedQuery): boolean {
    return this.canArchive(query) && this.canShare();
  }

  protected archive(query: SavedQuery): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.listError.set(null);

    this.workspace
      .archiveSavedQuery(tenantId, query.id, query.version)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          if (this.loadedId() === query.id) {
            this.loadedId.set(null);
          }

          this.reload();
        },
        error: (failure: unknown) => this.listError.set(this.messageFor(failure)),
      });
  }

  protected toggleSharing(query: SavedQuery): void {
    this.sharingId.update((current) => (current === query.id ? null : query.id));
  }

  protected toggleArchived(): void {
    this.archivedVisible.update((visible) => !visible);
  }

  /**
   * Re-reads the list after a change rather than patching it locally: sharing
   * moves a query between `PRIVATE` and `SHARED`, and the server is the only
   * one that knows what the caller may now see.
   */
  protected reload(): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      return;
    }

    this.loading.set(true);
    this.listError.set(null);

    this.workspace
      .savedQueries(tenantId)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (queries) => this.queries.set(queries),
        error: (failure: unknown) => {
          this.queries.set([]);

          // A saved query is owned by a tenant membership, so a caller acting
          // purely on system power has nothing to own or be granted. That is
          // not an error to report — the panel simply does not apply to them.
          if (this.problemCodeOf(failure) === 'SAVED_QUERY_MEMBERSHIP_REQUIRED') {
            this.unavailable.set(true);

            return;
          }

          this.listError.set('savedQuery.loadError');
        },
      });
  }

  private problemCodeOf(failure: unknown): string | null {
    const body =
      typeof failure === 'object' && failure !== null && 'error' in failure
        ? (failure as { error: unknown }).error
        : null;

    return isProblemDetails(body) ? body.code : null;
  }

  private messageFor(failure: unknown): TranslationKey {
    const body =
      typeof failure === 'object' && failure !== null && 'error' in failure
        ? (failure as { error: unknown }).error
        : null;

    if (!isProblemDetails(body)) {
      return 'savedQuery.saveError';
    }

    switch (body.code) {
      case 'SAVED_QUERY_NAME_TAKEN':
        return 'savedQuery.nameTaken';
      case 'SAVED_QUERY_VERSION_CONFLICT':
        return 'savedQuery.conflict';
      case 'SAVED_QUERY_INVALID':
        return 'savedQuery.invalid';
      case 'SAVED_QUERY_ARCHIVED':
        return 'savedQuery.archivedError';
      default:
        return 'savedQuery.saveError';
    }
  }
}
