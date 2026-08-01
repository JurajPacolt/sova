import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { DashboardWidget, SavedQuery, WidgetTypeDefinition } from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';
import { widgetDimensionLabelKey, widgetTypeLabelKey } from '../../widget-labels';

/**
 * Adds a widget to the open dashboard.
 *
 * A widget is a **pointer**: it names a saved query and a way to summarise it.
 * So the form asks for exactly that, plus the fields the chosen type cannot do
 * without — a breakdown needs something to group by, a matrix needs two
 * different axes. Everything else is left out on purpose: the server fills a
 * missing key with its default, and a form that repeated all of them would be
 * a second copy of the schema, free to drift from the real one.
 *
 * Only queries the caller can already open are offered, because that is the
 * rule the server applies anyway — a widget cannot become a way to run somebody
 * else's private query.
 */
@Component({
  selector: 'app-widget-editor',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './widget-editor.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WidgetEditorComponent {
  readonly tenantId = input.required<string>();
  readonly dashboardId = input.required<string>();
  readonly types = input.required<readonly WidgetTypeDefinition[]>();
  readonly queries = input.required<readonly SavedQuery[]>();

  readonly added = output<DashboardWidget>();
  readonly cancelled = output<void>();

  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly typeKey = signal('');
  protected readonly savedQueryId = signal('');
  protected readonly title = signal('');
  protected readonly groupBy = signal('');
  protected readonly columnBy = signal('');
  protected readonly saving = signal(false);
  protected readonly failed = signal<TranslationKey | null>(null);

  protected readonly selectedType = computed(
    () => this.types().find((type) => type.type_key === this.typeKey()) ?? null,
  );

  protected readonly needsGroupBy = computed(
    () => this.selectedType()?.type_key === 'issue_breakdown',
  );

  protected readonly needsAxes = computed(() => this.selectedType()?.type_key === 'issue_matrix');

  protected readonly dimensions = computed<readonly string[]>(
    () => this.selectedType()?.dimensions ?? [],
  );

  protected readonly complete = computed(() => {
    if (this.typeKey() === '' || this.savedQueryId() === '') {
      return false;
    }

    if (this.needsGroupBy()) {
      return this.groupBy() !== '';
    }

    if (this.needsAxes()) {
      // The same field on both axes would draw a diagonal and nothing else, so
      // the server refuses it; there is no reason to let somebody send it.
      return this.groupBy() !== '' && this.columnBy() !== '' && this.groupBy() !== this.columnBy();
    }

    return true;
  });

  protected submit(): void {
    if (!this.complete() || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.failed.set(null);

    this.workspace
      .addWidget(this.tenantId(), this.dashboardId(), {
        saved_query_id: this.savedQueryId(),
        type_key: this.typeKey(),
        title: this.title().trim(),
        configuration: this.configuration(),
      })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (widget) => {
          this.reset();
          this.added.emit(widget);
        },
        error: () => this.failed.set('dashboard.edit.addError'),
      });
  }

  /**
   * The registry ships localisation **keys**, never wording, so the key is
   * checked against the catalog before use — an unknown one would otherwise
   * render as itself, and a type this build does not know should say so.
   */
  protected labelKeyOf(type: WidgetTypeDefinition): TranslationKey {
    return widgetTypeLabelKey(type.label_key);
  }

  /** The same for a grouping field: `statusCategory` is a key, not wording. */
  protected dimensionLabelKey(dimension: string): TranslationKey {
    return widgetDimensionLabelKey(dimension);
  }

  protected cancel(): void {
    this.reset();
    this.cancelled.emit();
  }

  protected onTypeChange(value: string): void {
    this.typeKey.set(value);
    // The axes belong to the previous type's vocabulary; keeping them would
    // offer a field the new type cannot group by.
    this.groupBy.set('');
    this.columnBy.set('');
  }

  private configuration(): Readonly<Record<string, unknown>> {
    if (this.needsGroupBy()) {
      return { group_by: this.groupBy() };
    }

    if (this.needsAxes()) {
      return { rows: this.groupBy(), columns: this.columnBy() };
    }

    return {};
  }

  private reset(): void {
    this.typeKey.set('');
    this.savedQueryId.set('');
    this.title.set('');
    this.groupBy.set('');
    this.columnBy.set('');
    this.failed.set(null);
  }
}
