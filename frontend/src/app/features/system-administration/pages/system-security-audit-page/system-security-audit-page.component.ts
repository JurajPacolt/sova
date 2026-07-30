import { DatePipe } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { SecurityAuditEvent, SecurityAuditQuery } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { SystemSecurityAuditService } from '../../system-security-audit.service';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-system-security-audit-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    DatePipe,
    PageHeaderComponent,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  templateUrl: './system-security-audit-page.component.html',
  styleUrl: './system-security-audit-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SystemSecurityAuditPageComponent implements OnInit {
  private readonly audit = inject(SystemSecurityAuditService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);

  protected readonly events = signal<readonly SecurityAuditEvent[]>([]);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadingMore = signal(false);
  /** The failed request itself; the shared error state reads the status. */
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly filterForm = this.formBuilder.nonNullable.group({
    event_type: [''],
    outcome: [''],
    actor_user_id: [''],
    request_id: [''],
  });

  ngOnInit(): void {
    this.load(true);
  }

  protected applyFilters(): void {
    this.load(true);
  }

  protected resetFilters(): void {
    this.filterForm.reset();
    this.load(true);
  }

  protected loadMore(): void {
    if (this.nextCursor() !== null) {
      this.load(false);
    }
  }

  protected metadata(event: SecurityAuditEvent): string {
    return JSON.stringify(event.metadata);
  }

  private load(reset: boolean): void {
    if (this.loading() || this.loadingMore()) {
      return;
    }

    this.loadFailure.set(null);
    (reset ? this.loading : this.loadingMore).set(true);
    const rawFilters = this.filterForm.getRawValue();
    const outcome =
      rawFilters.outcome === 'SUCCESS' || rawFilters.outcome === 'FAILURE'
        ? rawFilters.outcome
        : undefined;
    const query: SecurityAuditQuery = {
      limit: 50,
      cursor: reset ? undefined : (this.nextCursor() ?? undefined),
      event_type: rawFilters.event_type.trim() || undefined,
      outcome,
      actor_user_id: rawFilters.actor_user_id.trim() || undefined,
      request_id: rawFilters.request_id.trim() || undefined,
    };

    this.audit
      .list(query)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => (reset ? this.loading : this.loadingMore).set(false)),
      )
      .subscribe({
        next: (page) => {
          this.events.update((events) => (reset ? page.events : [...events, ...page.events]));
          this.nextCursor.set(page.next_cursor);
        },
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }
}
