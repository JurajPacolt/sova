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
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { NotificationEntry, NotificationKind } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { NotificationCentreService } from '../../../../core/notifications/notification-centre.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

type NotificationFilter = 'ALL' | 'UNREAD' | NotificationKind;

const FILTERS: readonly {
  readonly value: NotificationFilter;
  readonly labelKey: TranslationKey;
}[] = [
  { value: 'ALL', labelKey: 'notifications.filter.all' },
  { value: 'UNREAD', labelKey: 'notifications.filter.unread' },
  { value: 'ISSUE_ASSIGNED', labelKey: 'notifications.kind.assigned' },
  { value: 'ISSUE_MENTIONED', labelKey: 'notifications.kind.mentioned' },
  { value: 'ISSUE_COMMENTED', labelKey: 'notifications.kind.commented' },
  { value: 'ISSUE_TRANSITIONED', labelKey: 'notifications.kind.transitioned' },
];

const KIND_KEYS: Readonly<Record<NotificationKind, TranslationKey>> = {
  ISSUE_ASSIGNED: 'notifications.kind.assigned',
  ISSUE_MENTIONED: 'notifications.kind.mentioned',
  ISSUE_COMMENTED: 'notifications.kind.commented',
  ISSUE_TRANSITIONED: 'notifications.kind.transitioned',
};

/**
 * NOT-02, the notification centre of webflow §11.2.
 *
 * Only `unread` is a server-side filter, because only that one exists in the
 * API; the event kinds narrow what was already fetched. That is honest as long
 * as the screen says the list is the newest hundred — a client-side filter over
 * a capped list can only ever show what the cap let through, and pretending
 * otherwise would make an empty result look like an absence of events.
 *
 * Opening an entry marks it read and then navigates; it deliberately does not
 * wait for the write. Being told that marking failed is worse than a number
 * that corrects itself on the next poll, and the entry is on its way off the
 * screen anyway.
 */
@Component({
  selector: 'app-notification-list-page',
  standalone: true,
  imports: [ErrorStateComponent, PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './notification-list-page.component.html',
  styleUrl: './notification-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationListPageComponent implements OnInit {
  private readonly centre = inject(NotificationCentreService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly filters = FILTERS;
  protected readonly filter = signal<NotificationFilter>('ALL');
  protected readonly notifications = signal<readonly NotificationEntry[]>([]);
  protected readonly loading = signal(false);
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly markingAll = signal(false);
  protected readonly markError = signal(false);

  protected readonly unreadCount = this.centre.unreadCount;

  protected readonly visible = computed(() => {
    const selected = this.filter();
    const entries = this.notifications();

    if (selected === 'ALL') {
      return entries;
    }

    if (selected === 'UNREAD') {
      return entries.filter((entry) => entry.read_at === null);
    }

    return entries.filter((entry) => entry.kind === selected);
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
    this.centre
      .list(tenantId, false)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (list) => this.notifications.set(list.notifications),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected selectFilter(value: NotificationFilter): void {
    this.filter.set(value);
  }

  protected kindKey(kind: NotificationKind): TranslationKey {
    return KIND_KEYS[kind];
  }

  /** The key the event carried, which is what the person was told about. */
  protected issueKey(entry: NotificationEntry): string | null {
    return entry.payload.issue_key ?? null;
  }

  protected open(entry: NotificationEntry): void {
    const key = this.issueKey(entry);

    this.markRead([entry.id]);

    if (key === null) {
      return;
    }

    void this.router.navigate(['..', 'issues', key], { relativeTo: this.route });
  }

  protected markAllRead(): void {
    const unread = this.notifications().filter((entry) => entry.read_at === null);

    if (unread.length === 0 || this.markingAll()) {
      return;
    }

    this.markingAll.set(true);
    this.markRead([], () => this.markingAll.set(false));
  }

  private markRead(ids: readonly string[], done?: () => void): void {
    const tenantId = this.tenantId();

    if (tenantId === null) {
      done?.();
      return;
    }

    const readAt = new Date().toISOString();
    this.markError.set(false);
    this.centre
      .markRead(tenantId, ids)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => done?.()),
      )
      .subscribe({
        next: () =>
          this.notifications.update((entries) =>
            entries.map((entry) =>
              (ids.length === 0 || ids.includes(entry.id)) && entry.read_at === null
                ? { ...entry, read_at: readAt }
                : entry,
            ),
          ),
        error: () => this.markError.set(true),
      });
  }
}
