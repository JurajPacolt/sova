import { computed, DestroyRef, effect, inject, Injectable, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable, of, Subscription, timer } from 'rxjs';
import { catchError, switchMap, tap } from 'rxjs/operators';
import {
  MarkNotificationsReadResponse,
  NotificationEntry,
  NotificationList,
  NotificationPreferenceList,
  ReplaceNotificationPreferencesRequest,
} from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantStore } from '../tenancy/tenant.store';

/** How often the badge asks again while a tenant is open. */
const POLL_INTERVAL_MS = 60_000;

/**
 * The unread badge, and the one place that talks to the inbox API.
 *
 * It lives in `core/` rather than in the notifications feature because the shell
 * header carries the badge, and the shell may not reach into a feature. The
 * pages of the feature use the same instance, so opening the centre and reading
 * something there moves the number in the header without a second request.
 *
 * The count is polled rather than pushed: webflow §11.3 names polling as the
 * accepted MVP alternative to a socket, and a socket would only signal a change
 * that this request has to make anyway. A failed poll keeps the last known
 * number instead of dropping to zero — zero is a claim that there is nothing
 * new, and a dropped connection is not evidence of that.
 */
@Injectable({ providedIn: 'root' })
export class NotificationCentreService {
  private readonly api = inject(SovaApiClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly unread = signal(0);
  private readonly latest = signal<readonly NotificationEntry[]>([]);
  private poll: Subscription | null = null;

  /** Unread notifications of the active tenant, as far as the last poll knows. */
  readonly unreadCount = this.unread.asReadonly();
  readonly unreadNotifications = this.latest.asReadonly();
  readonly hasUnread = computed(() => this.unread() > 0);

  constructor() {
    // A tenant switch is a different inbox: stop the old poll, clear the old
    // number, and start again. Leaving the previous count on screen would
    // attach one tenant's activity to another's name.
    effect(() => {
      const tenantId = this.tenantStore.activeTenantId();

      this.poll?.unsubscribe();
      this.poll = null;
      this.unread.set(0);
      this.latest.set([]);

      if (tenantId === null) {
        return;
      }

      this.poll = timer(0, POLL_INTERVAL_MS)
        .pipe(
          switchMap(() =>
            this.api.listNotifications(tenantId, true).pipe(catchError(() => of(null))),
          ),
          takeUntilDestroyed(this.destroyRef),
        )
        .subscribe((list) => {
          if (list !== null) {
            this.apply(list);
          }
        });
    });

    this.destroyRef.onDestroy(() => this.poll?.unsubscribe());
  }

  /** The full inbox for the centre; the badge is refreshed from the same answer. */
  list(tenantId: string, unreadOnly: boolean): Observable<NotificationList> {
    return this.api.listNotifications(tenantId, unreadOnly).pipe(
      tap((list) => {
        this.unread.set(list.unread_count);

        if (unreadOnly) {
          this.latest.set(list.notifications);
        }
      }),
    );
  }

  /** An empty selection means all of them, exactly as the endpoint reads it. */
  markRead(
    tenantId: string,
    notificationIds: readonly string[] = [],
  ): Observable<MarkNotificationsReadResponse> {
    return this.api
      .markNotificationsRead(
        tenantId,
        notificationIds.length === 0 ? {} : { notification_ids: notificationIds },
      )
      .pipe(
        tap((result) => {
          this.unread.set(result.unread_count);
          this.latest.update((entries) =>
            notificationIds.length === 0
              ? []
              : entries.filter((entry) => !notificationIds.includes(entry.id)),
          );
        }),
      );
  }

  preferences(tenantId: string): Observable<NotificationPreferenceList> {
    return this.api.getNotificationPreferences(tenantId);
  }

  replacePreferences(
    tenantId: string,
    request: ReplaceNotificationPreferencesRequest,
  ): Observable<NotificationPreferenceList> {
    return this.api.replaceNotificationPreferences(tenantId, request);
  }

  private apply(list: NotificationList): void {
    this.unread.set(list.unread_count);
    this.latest.set(list.notifications);
  }
}
