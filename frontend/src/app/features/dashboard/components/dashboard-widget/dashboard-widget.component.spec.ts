import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { DashboardWidget } from '../../../../core/api/api.models';
import { DashboardWidgetComponent } from './dashboard-widget.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const WIDGET_ID = '019f9f00-0000-7000-8000-000000000003';
const DATA_URL = `/api/v1/tenants/${TENANT_ID}/dashboards/${DASHBOARD_ID}/widgets/${WIDGET_ID}/data`;

function widget(overrides: Partial<DashboardWidget> = {}): DashboardWidget {
  return {
    id: WIDGET_ID,
    dashboard_id: DASHBOARD_ID,
    type_key: 'issue_count',
    available: true,
    schema_version: 1,
    title: 'Reported by me',
    saved_query_id: '019f9f00-0000-7000-8000-0000000000aa',
    source_name: 'Reported by me',
    source_reachable: true,
    configuration: { tone: 'INFO', description: 'Still open', show_link: true },
    x: 0,
    y: 0,
    width: 4,
    height: 2,
    version: 1,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

describe('DashboardWidgetComponent', () => {
  let fixture: ComponentFixture<DashboardWidgetComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardWidgetComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });

    fixture = TestBed.createComponent(DashboardWidgetComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('tenantId', TENANT_ID);
    fixture.componentRef.setInput('dashboardId', DASHBOARD_ID);
  });

  afterEach(() => {
    http.verify();
  });

  function render(instance: DashboardWidget, payload: unknown): HTMLElement {
    fixture.componentRef.setInput('widget', instance);
    fixture.detectChanges();
    http.expectOne(DATA_URL).flush({ data: payload });
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  it('shows a count with a labelled tone rather than colour alone', () => {
    const element = render(widget(), { count: 7 });

    expect(element.textContent).toContain('7');
    // The tone is spelled out, so the meaning survives for a reader who cannot
    // tell the chip colours apart.
    expect(element.textContent).toContain('Information');
  });

  it('sizes breakdown bars against the largest bucket, not the total', () => {
    const element = render(widget({ type_key: 'issue_breakdown', configuration: {} }), {
      buckets: [
        { key: 'OPEN', label: 'Open', count: 10 },
        { key: 'DONE', label: 'Done', count: 5 },
      ],
    });

    const fills = element.querySelectorAll<HTMLElement>('.widget__bar-fill');
    expect(fills.length).toBe(2);
    expect(fills[0].style.width).toBe('100%');
    // Half of the largest bar, which is what a reader compares it against.
    expect(fills[1].style.width).toBe('50%');
  });

  it('always writes the number in a matrix cell, never only its shading', () => {
    const element = render(widget({ type_key: 'issue_matrix', configuration: {} }), {
      cells: [
        { row_key: 'A', row_label: 'Alpha', column_key: 'H', column_label: 'High', count: 4 },
        { row_key: 'A', row_label: 'Alpha', column_key: 'L', column_label: 'Low', count: 0 },
      ],
    });

    const cells = element.querySelectorAll('.widget__matrix tbody td');
    expect(cells.length).toBe(2);
    expect(cells[0].textContent?.trim()).toBe('4');
    expect(cells[1].textContent?.trim()).toBe('0');
  });

  it('offers a legend only once there is a second series to tell apart', () => {
    const single = render(widget({ type_key: 'issue_time_series', configuration: {} }), {
      series: [{ event: 'CREATED', points: [{ bucket: '2026-07-28T00:00:00+00:00', count: 2 }] }],
    });

    expect(single.querySelector('.widget__legend')).toBeNull();
  });

  it('keeps a text table beside the chart so the shape is never the only source', () => {
    const element = render(widget({ type_key: 'issue_time_series', configuration: {} }), {
      series: [
        {
          event: 'CREATED',
          points: [
            { bucket: '2026-07-28T00:00:00+00:00', count: 2 },
            { bucket: '2026-07-29T00:00:00+00:00', count: 4 },
          ],
        },
      ],
    });

    const table = element.querySelector('table.visually-hidden');
    expect(table).not.toBeNull();
    expect(table?.textContent).toContain('4');
  });

  /**
   * The server hands out widget data one widget at a time so a single failure
   * cannot blank a page; the client keeps that promise by drawing the failure
   * inside this card and offering to try again.
   */
  it('reports its own failure and can retry without reloading the page', () => {
    fixture.componentRef.setInput('widget', widget());
    fixture.detectChanges();
    http.expectOne(DATA_URL).flush('nope', { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('This widget could not be loaded.');
    expect(element.textContent).toContain('Try again');

    element.querySelector('button')?.click();
    fixture.detectChanges();
    http.expectOne(DATA_URL).flush({ data: { count: 1 } });
    fixture.detectChanges();

    expect(element.textContent).toContain('1');
  });

  it('does not call the data endpoint for an unknown type', () => {
    fixture.componentRef.setInput('widget', widget({ type_key: 'issue_orbit', available: false }));
    fixture.detectChanges();

    // Nothing is requested at all: `http.verify()` in afterEach fails on a
    // stray call, which is the assertion.
    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('This version does not know this widget type.');
  });

  it('does not call the data endpoint when the saved query is out of reach', () => {
    fixture.componentRef.setInput('widget', widget({ source_reachable: false }));
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('no longer available to you');
  });
});
