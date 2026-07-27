import { DatePipe } from '@angular/common';
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
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { SecurityAuditEvent, SecurityAuditQuery } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { TenantSecurityAuditService } from '../../tenant-security-audit.service';

@Component({
  selector: 'app-tenant-audit-page',
  standalone: true,
  imports: [DatePipe, PageHeaderComponent, ReactiveFormsModule, TranslatePipe],
  templateUrl: './tenant-audit-page.component.html',
  styleUrl: './tenant-audit-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantAuditPageComponent implements OnInit {
  private readonly audit = inject(TenantSecurityAuditService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly events = signal<readonly SecurityAuditEvent[]>([]);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadingMore = signal(false);
  protected readonly loadError = signal(false);
  protected readonly exporting = signal(false);
  protected readonly exportError = signal(false);
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

  protected export(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.exporting()) {
      return;
    }

    this.exportError.set(false);
    this.exporting.set(true);
    this.audit
      .export(tenantId, this.query(undefined))
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.exporting.set(false)),
      )
      .subscribe({
        error: () => this.exportError.set(true),
      });
  }

  private load(reset: boolean): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.loading() || this.loadingMore()) {
      return;
    }

    this.loadError.set(false);
    (reset ? this.loading : this.loadingMore).set(true);
    this.audit
      .list(tenantId, this.query(reset ? undefined : (this.nextCursor() ?? undefined)))
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => (reset ? this.loading : this.loadingMore).set(false)),
      )
      .subscribe({
        next: (page) => {
          this.events.update((events) => (reset ? page.events : [...events, ...page.events]));
          this.nextCursor.set(page.next_cursor);
        },
        error: () => this.loadError.set(true),
      });
  }

  private query(cursor: string | undefined): SecurityAuditQuery {
    const rawFilters = this.filterForm.getRawValue();
    const outcome =
      rawFilters.outcome === 'SUCCESS' || rawFilters.outcome === 'FAILURE'
        ? rawFilters.outcome
        : undefined;

    return {
      limit: 50,
      cursor,
      event_type: rawFilters.event_type.trim() || undefined,
      outcome,
      actor_user_id: rawFilters.actor_user_id.trim() || undefined,
      request_id: rawFilters.request_id.trim() || undefined,
    };
  }
}
