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
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { NotificationKind, NotificationPreference } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { NotificationCentreService } from '../../../../core/notifications/notification-centre.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

const KIND_KEYS: Readonly<Record<NotificationKind, TranslationKey>> = {
  ISSUE_ASSIGNED: 'notifications.kind.assigned',
  ISSUE_MENTIONED: 'notifications.kind.mentioned',
  ISSUE_COMMENTED: 'notifications.kind.commented',
  ISSUE_TRANSITIONED: 'notifications.kind.transitioned',
};

/**
 * NOT-03 of webflow §12: one channel decision per event kind.
 *
 * The rows come from the server, not from a list held here — a build that
 * invented its own would either hide an event kind the server already delivers
 * or offer one it does not. `in_app_locked` is likewise the server's answer:
 * assignment and being addressed by name may not be silently missed, and the
 * domain enforces that whatever a client sends, so the checkbox is disabled to
 * describe the rule rather than to pretend the choice exists.
 *
 * Saving replaces the whole set, because that is what `PUT` means here; the
 * screen therefore submits every row it was given, including the ones nobody
 * touched.
 */
@Component({
  selector: 'app-notification-preferences-page',
  standalone: true,
  imports: [ErrorStateComponent, PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './notification-preferences-page.component.html',
  styleUrl: './notification-preferences-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationPreferencesPageComponent implements OnInit {
  private readonly centre = inject(NotificationCentreService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly preferences = signal<readonly NotificationPreference[]>([]);
  protected readonly loading = signal(false);
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly saving = signal(false);
  protected readonly saveError = signal(false);
  protected readonly saved = signal(false);

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
      .preferences(tenantId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (list) => this.preferences.set(list.preferences),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected kindKey(kind: NotificationKind): TranslationKey {
    return KIND_KEYS[kind];
  }

  protected toggleInApp(kind: NotificationKind, enabled: boolean): void {
    this.update(kind, (preference) =>
      preference.in_app_locked ? preference : { ...preference, in_app: enabled },
    );
  }

  protected toggleEmail(kind: NotificationKind, enabled: boolean): void {
    this.update(kind, (preference) => ({ ...preference, email: enabled }));
  }

  protected checked(event: Event): boolean {
    return (event.target as HTMLInputElement).checked;
  }

  protected save(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.saving()) {
      return;
    }

    this.saved.set(false);
    this.saveError.set(false);
    this.saving.set(true);
    this.centre
      .replacePreferences(tenantId, {
        preferences: this.preferences().map((preference) => ({
          kind: preference.kind,
          in_app: preference.in_app,
          email: preference.email,
        })),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        // The answer is the stored truth, including any locked channel the
        // domain corrected on the way in.
        next: (list) => {
          this.preferences.set(list.preferences);
          this.saved.set(true);
        },
        error: () => this.saveError.set(true),
      });
  }

  private update(
    kind: NotificationKind,
    change: (preference: NotificationPreference) => NotificationPreference,
  ): void {
    this.saved.set(false);
    this.preferences.update((preferences) =>
      preferences.map((preference) => (preference.kind === kind ? change(preference) : preference)),
    );
  }
}
