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
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import {
  DashboardWidget,
  isWidgetBreakdownData,
  isWidgetCountData,
  isWidgetListData,
  isWidgetMatrixData,
  isWidgetTimeSeriesData,
  IssueSearchHit,
  WidgetBreakdownBucket,
  WidgetData,
  WidgetMatrixCell,
} from '../../../../core/api/api.models';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';

/** A bar with the share of the largest value, so widths compare within one widget. */
interface BreakdownBar {
  readonly label: string;
  readonly count: number;
  readonly share: number;
}

interface MatrixRow {
  readonly label: string;
  readonly cells: readonly { readonly label: string; readonly count: number }[];
}

interface SeriesPoint {
  readonly bucket: string;
  readonly count: number;
  readonly share: number;
}

/** One bucket across every series, so a chart row and a table row are the same row. */
interface ChartRow {
  readonly bucket: string;
  readonly values: readonly { readonly count: number; readonly share: number }[];
}

interface Series {
  readonly event: string;
  readonly labelKey: TranslationKey;
  readonly points: readonly SeriesPoint[];
  /** Polyline coordinates in the chart's own view box, for the line form. */
  readonly line: string;
}

/**
 * One widget of a dashboard: it loads its own data and shows its own outcome.
 *
 * That separation is the point. The server hands out widget data one widget at a
 * time so a single unreachable saved query cannot blank the page, and this
 * component keeps that promise on the client: a failure here is drawn inside
 * this card, and the rest of the dashboard carries on.
 *
 * Nothing about the stored `type_key` is trusted to name something runnable — it
 * selects a branch of this template, and an unknown key renders as unavailable
 * rather than being read as the type that happens to sit next to it.
 */
@Component({
  selector: 'app-dashboard-widget',
  standalone: true,
  imports: [DatePipe, RouterLink, TranslatePipe],
  templateUrl: './dashboard-widget.component.html',
  styleUrl: './dashboard-widget.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardWidgetComponent implements OnInit {
  readonly tenantId = input.required<string>();
  readonly dashboardId = input.required<string>();
  readonly widget = input.required<DashboardWidget>();

  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly data = signal<WidgetData | null>(null);

  protected readonly countValue = computed(() => {
    const payload = this.data();

    return payload !== null && isWidgetCountData(payload) ? payload.count : null;
  });

  protected readonly issues = computed<readonly IssueSearchHit[]>(() => {
    const payload = this.data();

    return payload !== null && isWidgetListData(payload) ? payload.issues : [];
  });

  protected readonly bars = computed<readonly BreakdownBar[]>(() => {
    const payload = this.data();

    if (payload === null || !isWidgetBreakdownData(payload)) {
      return [];
    }

    // Widths are relative to the largest bucket, never to the total: a share of
    // the whole would make every bar in a long tail invisible.
    const largest = payload.buckets.reduce(
      (highest: number, bucket: WidgetBreakdownBucket) => Math.max(highest, bucket.count),
      0,
    );

    return payload.buckets.map((bucket) => ({
      label: bucket.label,
      count: bucket.count,
      share: largest === 0 ? 0 : Math.round((bucket.count / largest) * 100),
    }));
  });

  protected readonly matrixColumns = computed<readonly string[]>(() => {
    const payload = this.data();

    if (payload === null || !isWidgetMatrixData(payload)) {
      return [];
    }

    const labels: string[] = [];

    for (const cell of payload.cells) {
      if (!labels.includes(cell.column_label)) {
        labels.push(cell.column_label);
      }
    }

    return labels;
  });

  protected readonly matrixRows = computed<readonly MatrixRow[]>(() => {
    const payload = this.data();

    if (payload === null || !isWidgetMatrixData(payload)) {
      return [];
    }

    const columns = this.matrixColumns();
    const rows = new Map<string, Map<string, number>>();

    for (const cell of payload.cells) {
      const row = rows.get(cell.row_label) ?? new Map<string, number>();
      row.set(cell.column_label, cell.count);
      rows.set(cell.row_label, row);
    }

    return [...rows.entries()].map(([label, counts]) => ({
      label,
      // Every column is present on every row, including the empty ones: a
      // ragged table would make two rows compare cells that are not aligned.
      cells: columns.map((column) => ({ label: column, count: counts.get(column) ?? 0 })),
    }));
  });

  /** The busiest cell, so the shading of every other cell is relative to it. */
  protected readonly matrixPeak = computed(() => {
    const payload = this.data();

    if (payload === null || !isWidgetMatrixData(payload)) {
      return 0;
    }

    return payload.cells.reduce(
      (highest: number, cell: WidgetMatrixCell) => Math.max(highest, cell.count),
      0,
    );
  });

  protected readonly series = computed<readonly Series[]>(() => {
    const payload = this.data();

    if (payload === null || !isWidgetTimeSeriesData(payload)) {
      return [];
    }

    // One scale for both series: two y-scales in one chart make the pair look
    // comparable when it is not.
    const peak = payload.series.reduce(
      (highest: number, entry) =>
        entry.points.reduce((inner: number, point) => Math.max(inner, point.count), highest),
      0,
    );

    return payload.series.map((entry) => {
      const points = entry.points.map((point) => ({
        bucket: point.bucket,
        count: point.count,
        share: peak === 0 ? 0 : Math.round((point.count / peak) * 100),
      }));

      return {
        event: entry.event,
        labelKey:
          entry.event === 'RESOLVED' ? 'dashboard.widget.resolved' : 'dashboard.widget.created',
        points,
        line: this.polyline(points),
      };
    });
  });

  /**
   * The series transposed into one row per bucket. Both the bars and the text
   * table read from it, so the picture and the numbers cannot drift apart.
   */
  protected readonly chartRows = computed<readonly ChartRow[]>(() => {
    const series = this.series();
    const first = series.at(0);

    if (first === undefined) {
      return [];
    }

    return first.points.map((point, index) => ({
      bucket: point.bucket,
      values: series.map((entry) => {
        const at = entry.points.at(index);

        return { count: at?.count ?? 0, share: at?.share ?? 0 };
      }),
    }));
  });

  protected readonly firstBucket = computed(() => this.chartRows().at(0)?.bucket ?? null);
  protected readonly lastBucket = computed(() => this.chartRows().at(-1)?.bucket ?? null);

  /** A legend is only meaningful once a second series exists to tell apart. */
  protected readonly showLegend = computed(() => this.series().length > 1);

  protected readonly description = computed(() => this.text('description'));

  /**
   * A reserved status colour, and never on its own: the number keeps its text
   * colour and the tone rides on a labelled chip beside it.
   */
  protected readonly toneKey = computed<TranslationKey | null>(() => {
    switch (this.text('tone')) {
      case 'INFO':
        return 'dashboard.widget.tone.info';
      case 'SUCCESS':
        return 'dashboard.widget.tone.success';
      case 'WARNING':
        return 'dashboard.widget.tone.warning';
      case 'DANGER':
        return 'dashboard.widget.tone.danger';
      default:
        return null;
    }
  });

  protected readonly toneClass = computed(() => {
    switch (this.text('tone')) {
      case 'INFO':
        return 'text-bg-primary';
      case 'SUCCESS':
        return 'text-bg-success';
      case 'WARNING':
        return 'text-bg-warning';
      case 'DANGER':
        return 'text-bg-danger';
      default:
        return 'text-bg-light';
    }
  });

  /**
   * `DONUT` renders as bars. A ring needs one colour per slice, and the design
   * system ships two chart hues on purpose — ten of them would be invented, not
   * chosen. The bar answers the same question and stays readable, and the stored
   * configuration is left exactly as its author wrote it.
   */
  protected readonly breakdownAsTable = computed(() => this.text('visualization') === 'TABLE');

  protected readonly seriesAsLine = computed(() => this.text('visualization') !== 'BAR');

  protected readonly columns = computed<readonly string[]>(() => {
    const configured = this.widget().configuration['columns'];

    return Array.isArray(configured)
      ? configured.filter((column): column is string => typeof column === 'string')
      : [];
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    const widget = this.widget();

    // An unknown type has no data endpoint worth calling, and an unreachable
    // source is already known to fail: neither is worth a request.
    if (!widget.available || !widget.source_reachable) {
      return;
    }

    this.loading.set(true);
    this.failed.set(false);

    this.workspace
      .widgetData(this.tenantId(), this.dashboardId(), widget.id)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (payload) => this.data.set(payload),
        error: () => {
          this.data.set(null);
          this.failed.set(true);
        },
      });
  }

  protected showsColumn(column: string): boolean {
    return this.columns().includes(column);
  }

  private text(key: string): string {
    const value = this.widget().configuration[key];

    return typeof value === 'string' ? value : '';
  }

  /**
   * Coordinates in the chart's own 100x40 view box. A single point still draws
   * a mark, because a flat line of one value would look like no data at all.
   */
  private polyline(points: readonly SeriesPoint[]): string {
    if (points.length === 0) {
      return '';
    }

    const step = points.length === 1 ? 0 : 100 / (points.length - 1);

    return points
      .map((point, index) => `${(index * step).toFixed(2)},${(40 - point.share * 0.4).toFixed(2)}`)
      .join(' ');
  }
}
