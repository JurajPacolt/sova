import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { finalize, Subscription } from 'rxjs';
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
import { describeApiError } from '../../../../core/errors/api-error';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { DashboardWorkspaceService } from '../../dashboard-workspace.service';

/** A bar with the share of the largest value, so widths compare within one widget. */
interface BreakdownBar {
  readonly label: string;
  readonly count: number;
  readonly share: number;
}

/**
 * One arc of the ring, expressed the way SVG wants it. The circle has a
 * circumference of exactly 100 units, so a percentage *is* a dash length and no
 * trigonometry is needed to place a slice.
 */
interface DonutSlice {
  readonly label: string;
  readonly count: number;
  readonly percent: number;
  readonly dash: string;
  readonly offset: number;
  readonly color: string;
  /** The folded remainder, which the legend names in its own words. */
  readonly rest: boolean;
}

interface MatrixRow {
  readonly label: string;
  readonly cells: readonly { readonly label: string; readonly count: number }[];
}

/** As many hues as the design system defines; the rest share the neutral. */
const DONUT_COLOURS = 6;

/** Kept between neighbouring arcs so two slices never meet as colour alone. */
const DONUT_GAP = 0.8;

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
export class DashboardWidgetComponent {
  readonly tenantId = input.required<string>();
  readonly dashboardId = input.required<string>();
  readonly widget = input.required<DashboardWidget>();

  private readonly workspace = inject(DashboardWorkspaceService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(false);
  protected readonly data = signal<WidgetData | null>(null);

  /** When the numbers on screen were last true (spec §8.1). */
  protected readonly loadedAt = signal<Date | null>(null);

  /**
   * The last failure, kept next to the data rather than instead of it. With an
   * answer already on screen this reads as "out of date"; without one it is the
   * whole state of the card.
   */
  protected readonly failure = signal<unknown>(null);

  protected readonly failureKey = computed<TranslationKey>(() => {
    const failure = this.failure();

    // A widget failing on its own is the ordinary case the server designed for,
    // so the card keeps its own sentence — except where the status says
    // something more useful, like a lost connection.
    return failure === null || !describeApiError(failure).offline
      ? 'dashboard.widget.loadError'
      : 'error.offline';
  });

  private request: Subscription | null = null;

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

  /**
   * The ring, drawn from the same buckets the bars use.
   *
   * Only the first six buckets get a hue, because the palette defines six and a
   * seventh would be invented rather than chosen — and a twenty-slice ring is
   * unreadable whatever it is coloured with. Everything past the sixth is folded
   * into one neutral arc that the legend names and counts, and the table below
   * the chart still lists **every** bucket on its own line, so folding changes
   * the picture and never the data.
   */
  protected readonly donutSlices = computed<readonly DonutSlice[]>(() => {
    const buckets = this.bars().filter((bar) => bar.count > 0);
    const total = buckets.reduce((sum, bar) => sum + bar.count, 0);

    if (total === 0) {
      return [];
    }

    const named = buckets.slice(0, DONUT_COLOURS);
    const remainder = buckets.slice(DONUT_COLOURS);
    const restCount = remainder.reduce((sum, bar) => sum + bar.count, 0);
    const drawn = [
      ...named.map((bar, index) => ({
        label: bar.label,
        count: bar.count,
        color: `var(--sova-color-chart-categorical-${index + 1})`,
        rest: false,
      })),
      ...(restCount > 0
        ? [
            {
              label: '',
              count: restCount,
              color: 'var(--sova-color-chart-categorical-rest)',
              rest: true,
            },
          ]
        : []),
    ];

    let travelled = 0;

    return drawn.map((slice) => {
      const percent = (slice.count / total) * 100;
      // The gap comes out of the arc, but never out of a sliver that would then
      // vanish: an unreadably thin slice is still better than a missing one.
      const length = percent > DONUT_GAP * 2 ? percent - DONUT_GAP : percent;
      const offset = -travelled;
      travelled += percent;

      return {
        ...slice,
        percent: Math.round(percent),
        dash: `${length.toFixed(2)} ${(100 - length).toFixed(2)}`,
        offset: Number(offset.toFixed(2)),
      };
    });
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

  protected readonly breakdownAsTable = computed(() => this.text('visualization') === 'TABLE');

  /**
   * `DONUT` now draws a ring, because the design system has a categorical scale
   * to draw it with. A bar carries its label beside it; an arc does not, which
   * is exactly why the ring waited for colours that are 3:1 against the surface
   * and for a legend that says what each one means.
   */
  protected readonly breakdownAsDonut = computed(() => this.text('visualization') === 'DONUT');

  protected readonly seriesAsLine = computed(() => this.text('visualization') !== 'BAR');

  protected readonly columns = computed<readonly string[]>(() => {
    const configured = this.widget().configuration['columns'];

    return Array.isArray(configured)
      ? configured.filter((column): column is string => typeof column === 'string')
      : [];
  });

  /**
   * What this widget asks the server for: its source and its settings. A move
   * or a resize changes the widget's version but not its question, so a saved
   * arrangement does not send every card back for data it already has.
   */
  private readonly question = computed(() => {
    const widget = this.widget();

    return `${widget.saved_query_id} ${JSON.stringify(widget.configuration)}`;
  });

  /**
   * The data follows the question. Reconfiguring a widget therefore replaces
   * the numbers rather than leaving the previous answer under a new heading —
   * which would be the one wrong thing to show.
   */
  private readonly reload = effect(() => {
    this.question();
    untracked(() => {
      // A new question makes the previous answer wrong rather than merely old,
      // so it goes before the request rather than surviving as "stale".
      this.data.set(null);
      this.load();
    });
  });

  protected load(): void {
    const widget = this.widget();

    // A request for the previous question must not land on top of a newer one:
    // configuring a widget twice in quick succession would otherwise settle on
    // whichever answer happened to be slower.
    this.request?.unsubscribe();

    // An unknown type has no data endpoint worth calling, and an unreachable
    // source is already known to fail: neither is worth a request.
    if (!widget.available || !widget.source_reachable) {
      this.data.set(null);

      return;
    }

    this.loading.set(true);
    this.failure.set(null);

    this.request = this.workspace
      .widgetData(this.tenantId(), this.dashboardId(), widget.id)
      .pipe(
        finalize(() => this.loading.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (payload) => {
          this.data.set(payload);
          this.loadedAt.set(new Date());
          this.failure.set(null);
        },
        // The previous answer stays: a failed refresh makes it old, not wrong.
        error: (error: unknown) => this.failure.set(error),
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
