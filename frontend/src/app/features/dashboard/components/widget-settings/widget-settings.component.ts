import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  linkedSignal,
  output,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { DashboardWidget, SavedQuery, WidgetTypeDefinition } from '../../../../core/api/api.models';
import { problemCode } from '../../../../core/errors/api-error';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';
import { widgetDimensionLabelKey, widgetTypeLabelKey } from '../../widget-labels';

/** A stored value paired with the catalog key that names it. */
interface Choice {
  readonly value: string;
  readonly labelKey: TranslationKey;
}

/** The source of a widget as the select offers it. */
interface Source {
  readonly id: string;
  readonly name: string;
}

/** The closed set the server accepts; an open range would allow an unbounded scan. */
const RANGES = [7, 30, 90, 365] as const;

/**
 * Reconfigures a widget that already exists: its data source, its title and the
 * settings of its own type.
 *
 * The **type** is not among them, and the form says so instead of offering a
 * select the server would refuse. Changing it would reinterpret a configuration
 * written against a different schema, which is exactly what the registry
 * forbids, so a different type means a different widget.
 *
 * What this form does not show, it does not throw away. The payload starts from
 * the configuration as stored and overwrites only the keys this build knows, so
 * a setting written by a later version survives an edit made here rather than
 * being reset to a default — `PATCH` replaces the whole configuration, so a
 * form that sent only its own fields would quietly wipe the rest.
 *
 * Only saved queries the caller can already open are offered, because that is
 * the rule the server applies anyway. The widget's current source stays in the
 * list even when it is archived or no longer shared, so opening this form can
 * never silently swap it for whatever happens to be first.
 */
@Component({
  selector: 'app-widget-settings',
  standalone: true,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './widget-settings.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WidgetSettingsComponent {
  readonly tenantId = input.required<string>();
  readonly dashboardId = input.required<string>();
  readonly widget = input.required<DashboardWidget>();
  readonly types = input.required<readonly WidgetTypeDefinition[]>();
  readonly queries = input.required<readonly SavedQuery[]>();

  readonly saved = output<DashboardWidget>();
  readonly cancelled = output<void>();

  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly saving = signal(false);
  protected readonly failed = signal<TranslationKey | null>(null);

  // The form is the widget until somebody edits it, and it becomes another
  // widget's form when the page opens one — so the fields follow the input
  // rather than being copied once in a lifecycle hook. They follow the
  // **identity**, not every version of it: saving an arrangement bumps the
  // version of every widget, and half-typed settings are not the arrangement's
  // to discard. The version itself is read at submit time, so it is still the
  // current one.
  /**
   * A **memoised** identity, and the only thing the fields below follow. A bare
   * `() => this.widget().id` would not do: the linked signals would consume the
   * input itself and reset on every new version of the same widget, throwing
   * away what somebody was typing. `untracked` in the computations keeps the
   * dependency to this one signal.
   */
  private readonly widgetId = computed(() => this.widget().id);

  protected readonly savedQueryId = linkedSignal({
    source: this.widgetId,
    computation: () => untracked(() => this.widget().saved_query_id),
  });

  protected readonly title = linkedSignal({
    source: this.widgetId,
    computation: () => untracked(() => this.widget().title),
  });

  protected readonly settings = linkedSignal<string, Readonly<Record<string, unknown>>>({
    source: this.widgetId,
    computation: () => untracked(() => ({ ...this.widget().configuration })),
  });

  protected readonly typeKey = computed(() => this.widget().type_key);

  protected readonly definition = computed<WidgetTypeDefinition | null>(
    () => this.types().find((type) => type.type_key === this.typeKey()) ?? null,
  );

  protected readonly typeLabelKey = computed<TranslationKey>(() =>
    widgetTypeLabelKey(this.definition()?.label_key ?? ''),
  );

  protected readonly dimensions = computed<readonly string[]>(
    () => this.definition()?.dimensions ?? [],
  );

  protected readonly isCount = computed(() => this.typeKey() === 'issue_count');
  protected readonly isList = computed(() => this.typeKey() === 'issue_list');
  protected readonly isBreakdown = computed(() => this.typeKey() === 'issue_breakdown');
  protected readonly isMatrix = computed(() => this.typeKey() === 'issue_matrix');
  protected readonly isSeries = computed(() => this.typeKey() === 'issue_time_series');

  /**
   * The columns of an `issue_list` this build can draw. The schema allows more,
   * and stored ones outside this set are carried through untouched — offering a
   * column that would then not appear is a promise the renderer cannot keep.
   */
  protected readonly listColumns: readonly Choice[] = [
    { value: 'title', labelKey: 'dashboard.widget.column.title' },
    { value: 'project', labelKey: 'dashboard.widget.column.project' },
    { value: 'status', labelKey: 'dashboard.widget.column.status' },
    { value: 'priority', labelKey: 'dashboard.widget.column.priority' },
    { value: 'updated', labelKey: 'dashboard.widget.column.updated' },
  ];

  protected readonly ranges = RANGES;

  protected readonly tones: readonly Choice[] = [
    { value: 'NEUTRAL', labelKey: 'dashboard.widget.tone.neutral' },
    { value: 'INFO', labelKey: 'dashboard.widget.tone.info' },
    { value: 'SUCCESS', labelKey: 'dashboard.widget.tone.success' },
    { value: 'WARNING', labelKey: 'dashboard.widget.tone.warning' },
    { value: 'DANGER', labelKey: 'dashboard.widget.tone.danger' },
  ];

  protected readonly densities: readonly Choice[] = [
    { value: 'COMPACT', labelKey: 'dashboard.configure.density.compact' },
    { value: 'COMFORTABLE', labelKey: 'dashboard.configure.density.comfortable' },
  ];

  protected readonly sorts: readonly Choice[] = [
    { value: 'COUNT', labelKey: 'dashboard.configure.sort.count' },
    { value: 'NAME', labelKey: 'dashboard.configure.sort.name' },
  ];

  protected readonly events: readonly Choice[] = [
    { value: 'CREATED', labelKey: 'dashboard.widget.created' },
    { value: 'RESOLVED', labelKey: 'dashboard.widget.resolved' },
  ];

  protected readonly buckets: readonly Choice[] = [
    { value: 'DAY', labelKey: 'dashboard.configure.bucket.day' },
    { value: 'WEEK', labelKey: 'dashboard.configure.bucket.week' },
    { value: 'MONTH', labelKey: 'dashboard.configure.bucket.month' },
  ];

  protected readonly seriesForms: readonly Choice[] = [
    { value: 'LINE', labelKey: 'dashboard.configure.visualization.line' },
    { value: 'BAR', labelKey: 'dashboard.configure.visualization.bar' },
  ];

  /**
   * All three forms, `DONUT` included: the ring is drawn now that the design
   * system has a categorical scale, so offering it no longer promises something
   * that will not appear.
   */
  protected readonly breakdownForms: readonly Choice[] = [
    { value: 'BAR', labelKey: 'dashboard.configure.visualization.bar' },
    { value: 'TABLE', labelKey: 'dashboard.configure.visualization.table' },
    { value: 'DONUT', labelKey: 'dashboard.configure.visualization.donut' },
  ];

  protected readonly sources = computed<readonly Source[]>(() => {
    const offered = this.queries().map((query) => ({ id: query.id, name: query.name }));
    const current = this.widget().saved_query_id;

    return offered.some((source) => source.id === current)
      ? offered
      : [{ id: current, name: this.widget().source_name ?? '' }, ...offered];
  });

  /** Every column of the stored configuration, including ones not offered here. */
  protected readonly columns = computed<readonly string[]>(() => {
    const stored = this.settings()['columns'];

    return Array.isArray(stored)
      ? stored.filter((column): column is string => typeof column === 'string')
      : [];
  });

  protected readonly axesClash = computed(
    () => this.isMatrix() && this.text('rows') !== '' && this.text('rows') === this.text('columns'),
  );

  protected readonly complete = computed(() => {
    if (this.savedQueryId() === '') {
      return false;
    }

    if (this.isList()) {
      const shown = this.columns().length;

      return shown >= 3 && shown <= 10 && this.inRange('limit', 5, 50);
    }

    if (this.isBreakdown()) {
      return this.text('group_by') !== '' && this.inRange('top_n', 3, 20);
    }

    if (this.isMatrix()) {
      return this.text('rows') !== '' && this.text('columns') !== '' && !this.axesClash();
    }

    return true;
  });

  protected submit(): void {
    const widget = this.widget();

    if (!this.complete() || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.failed.set(null);

    this.workspace
      .updateWidget(this.tenantId(), this.dashboardId(), widget.id, {
        // The version the form was filled against, so a widget changed in
        // another tab is reported rather than overwritten.
        expected_version: widget.version,
        saved_query_id: this.savedQueryId(),
        title: this.title().trim(),
        configuration: this.settings(),
      })
      .pipe(
        finalize(() => this.saving.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (updated) => this.saved.emit(updated),
        error: (error: unknown) => this.failed.set(this.reason(error)),
      });
  }

  protected cancel(): void {
    this.cancelled.emit();
  }

  protected dimensionLabelKey(dimension: string): TranslationKey {
    return widgetDimensionLabelKey(dimension);
  }

  protected text(key: string): string {
    const value = this.settings()[key];

    return typeof value === 'string' ? value : '';
  }

  protected flag(key: string, fallback: boolean): boolean {
    const value = this.settings()[key];

    return typeof value === 'boolean' ? value : fallback;
  }

  /** `null` rather than a default, so an emptied box renders empty. */
  protected number(key: string): number | null {
    const value = this.settings()[key];

    return typeof value === 'number' ? value : null;
  }

  protected showsColumn(column: string): boolean {
    return this.columns().includes(column);
  }

  protected set(key: string, value: unknown): void {
    this.failed.set(null);
    this.settings.set({ ...this.settings(), [key]: value });
  }

  /**
   * An emptied number box means "use the server's default": the key is dropped
   * from the payload rather than sent as something the schema would reject.
   */
  protected setNumber(key: string, value: unknown): void {
    this.set(key, typeof value === 'number' && Number.isFinite(value) ? value : undefined);
  }

  protected toggleColumn(column: string, shown: boolean): void {
    const remaining = this.columns().filter((entry) => entry !== column);

    this.set('columns', shown ? [...remaining, column] : remaining);
  }

  /** An absent value is the server's default and therefore in range by definition. */
  protected inRange(key: string, minimum: number, maximum: number): boolean {
    const value = this.settings()[key];

    if (value === undefined || value === null) {
      return true;
    }

    return (
      typeof value === 'number' && Number.isInteger(value) && value >= minimum && value <= maximum
    );
  }

  private reason(error: unknown): TranslationKey {
    switch (problemCode(error)) {
      case 'WIDGET_VERSION_CONFLICT':
        // Somebody else's edit is not this form's to discard, and the version it
        // was filled against is gone — so this asks for a fresh start rather
        // than offering to re-send.
        return 'dashboard.configure.conflict';
      case 'WIDGET_DATA_SOURCE_NOT_FOUND':
        return 'dashboard.configure.sourceGone';
      case 'WIDGET_CONFIGURATION_INVALID':
        return 'dashboard.configure.invalid';
      default:
        return 'dashboard.configure.saveError';
    }
  }
}
