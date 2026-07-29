import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Dashboard, DashboardWidget } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { DashboardPageComponent } from './dashboard-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const OTHER_ID = '019f9f00-0000-7000-8000-000000000003';
const DASHBOARDS = `/api/v1/tenants/${TENANT_ID}/dashboards`;

function dashboard(overrides: Partial<Dashboard> = {}): Dashboard {
  return {
    id: DASHBOARD_ID,
    name: 'My work',
    position: 0,
    is_default: true,
    is_active: true,
    widget_count: 0,
    version: 1,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

function widget(overrides: Partial<DashboardWidget> = {}): DashboardWidget {
  return {
    id: '019f9f00-0000-7000-8000-0000000000a1',
    dashboard_id: DASHBOARD_ID,
    type_key: 'issue_count',
    available: true,
    schema_version: 1,
    title: 'Widget',
    saved_query_id: '019f9f00-0000-7000-8000-0000000000aa',
    source_name: 'Query',
    source_reachable: false,
    configuration: {},
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

describe('DashboardPageComponent', () => {
  let fixture: ComponentFixture<DashboardPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardPageComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        // Switching navigates to the chosen dashboard, so the test router needs
        // somewhere for that to land.
        provideRouter([{ path: ':dashboardId', children: [] }]),
        {
          provide: TenantStore,
          useValue: {
            activeTenantId: () => TENANT_ID,
            hasAnyPermission: () => true,
          },
        },
      ],
    });

    fixture = TestBed.createComponent(DashboardPageComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('dashboardId', DASHBOARD_ID);
  });

  afterEach(() => {
    http.verify();
  });

  function initialise(
    dashboards: readonly Dashboard[],
    widgets: readonly DashboardWidget[],
  ): HTMLElement {
    fixture.detectChanges();
    http
      .expectOne(DASHBOARDS)
      .flush({ dashboards, active_dashboard_id: dashboards[0]?.id ?? null });
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/widgets`).flush({ widgets });
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  function click(label: string): void {
    const element: HTMLElement = fixture.nativeElement;
    const target = [...element.querySelectorAll('button')].find(
      (button) =>
        button.textContent?.includes(label) === true || button.getAttribute('aria-label') === label,
    );

    if (target === undefined) {
      throw new Error(`No button labelled "${label}".`);
    }

    target.click();
    fixture.detectChanges();
  }

  /** The same click, but on a named widget rather than the first one. */
  function clickIn(cellIndex: number, label: string): void {
    const element: HTMLElement = fixture.nativeElement;
    const cell = element.querySelectorAll('.dashboard__cell')[cellIndex];
    const target = cell?.querySelector<HTMLButtonElement>(`[aria-label="${label}"]`);

    if (target === null || target === undefined) {
      throw new Error(`No "${label}" button in cell ${cellIndex}.`);
    }

    target.click();
    fixture.detectChanges();
  }

  /** Enters edit mode and answers the two catalogue requests it triggers. */
  function startEditing(): void {
    click('Arrange');
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/widget-types`).flush({ widget_types: [] });
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/saved-queries`).flush({ saved_queries: [] });
    fixture.detectChanges();
  }

  /**
   * The server keeps the "last active" write out of `GET` so that a prefetch
   * cannot move where somebody lands next. Opening a dashboard directly must
   * not put that side effect back on the client side.
   */
  it('does not record the active dashboard just because the page was opened', () => {
    initialise([dashboard()], []);

    // `http.verify()` in afterEach fails if the preference was written.
    expect(fixture.componentInstance.dashboardId()).toBe(DASHBOARD_ID);
  });

  it('records the preference when somebody switches, because that is a choice', () => {
    initialise(
      [dashboard(), dashboard({ id: OTHER_ID, name: 'Release watch', is_default: false })],
      [],
    );

    const element: HTMLElement = fixture.nativeElement;
    const target = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes('Release watch'),
    );
    target?.click();
    fixture.detectChanges();

    const request = http.expectOne(`${DASHBOARDS}/${OTHER_ID}/active`);
    expect(request.request.method).toBe('PUT');
    request.flush({ dashboard: dashboard({ id: OTHER_ID }) });
  });

  it('hides the switcher while there is nothing to switch between', () => {
    const element = initialise([dashboard()], []);

    expect(element.querySelector('.dashboard__switcher')).toBeNull();
  });

  /**
   * The single-column fallback follows the document, so the order has to be the
   * stable one from the specification: row, then column, then identifier.
   */
  it('orders widgets by row, then column, then identifier', () => {
    const element = initialise(
      [dashboard()],
      [
        widget({ id: 'c', title: 'Third', x: 8, y: 0 }),
        widget({ id: 'a', title: 'Last', x: 0, y: 4 }),
        widget({ id: 'b', title: 'First', x: 0, y: 0 }),
      ],
    );

    const titles = [...element.querySelectorAll('.widget h3')].map((heading) =>
      heading.textContent?.trim(),
    );
    expect(titles).toEqual(['First', 'Third', 'Last']);
  });

  it('places every widget on the grid by its own stored coordinates', () => {
    const element = initialise(
      [dashboard()],
      [widget({ id: 'b', x: 8, y: 2, width: 4, height: 4 })],
    );

    const cell = element.querySelector<HTMLElement>('.dashboard__cell');
    expect(cell?.style.getPropertyValue('--widget-column')).toBe('9');
    expect(cell?.style.getPropertyValue('--widget-row')).toBe('3');
    expect(cell?.style.getPropertyValue('--widget-span')).toBe('4');
    expect(cell?.style.getPropertyValue('--widget-rows')).toBe('4');
  });

  /**
   * Editing is a mode, not a mood (spec §7.3): nothing on the page can move a
   * widget until somebody turns arranging on.
   */
  it('offers no arranging controls until edit mode is entered', () => {
    const element = initialise([dashboard()], [widget({ id: 'a' })]);

    expect(element.querySelector('[aria-label="Move right"]')).toBeNull();

    startEditing();
    expect(element.querySelector('[aria-label="Move right"]')).not.toBeNull();
  });

  it('collects moves into a draft and writes nothing until they are saved', () => {
    const element = initialise([dashboard({ version: 4 })], [widget({ id: 'a', x: 0, y: 0 })]);
    startEditing();

    click('Move right');
    click('Move down');

    // The grid already shows the move…
    const cell = element.querySelector<HTMLElement>('.dashboard__cell');
    expect(cell?.style.getPropertyValue('--widget-column')).toBe('2');
    expect(cell?.style.getPropertyValue('--widget-row')).toBe('2');
    // …but nothing has been sent; `http.verify()` in afterEach proves it.
  });

  /**
   * Two widgets passing each other is legal only as a pair, so the whole
   * arrangement travels in one request, against the version it was made
   * against.
   */
  it('saves the whole arrangement in one request against the dashboard version', () => {
    initialise(
      [dashboard({ version: 4 })],
      [widget({ id: 'a', x: 0, y: 0, width: 4, height: 2 }), widget({ id: 'b', x: 4, y: 0 })],
    );
    startEditing();

    click('Move down');
    click('Save the arrangement');

    const request = http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/layout`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body.expected_version).toBe(4);
    // Every widget, not only the one that moved.
    expect(request.request.body.widgets).toHaveLength(2);
    request.flush({ widgets: [] });

    // The new version is read back, so the next save is not stale by design.
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}`).flush({ dashboard: dashboard({ version: 5 }) });
  });

  it('refuses to save an overlap and says which rule was broken', () => {
    const element = initialise(
      [dashboard()],
      [widget({ id: 'a', x: 0, y: 0, width: 4, height: 2 }), widget({ id: 'b', x: 4, y: 0 })],
    );
    startEditing();

    // Walk the second widget onto the first.
    clickIn(1, 'Move left');
    clickIn(1, 'Move left');
    clickIn(1, 'Move left');
    clickIn(1, 'Move left');

    expect(element.textContent).toContain('Two widgets overlap.');
    const save = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes('Save the arrangement'),
    );
    expect(save?.disabled).toBe(true);
  });

  /**
   * Another tab's work is not this tab's to discard. A conflict is reported and
   * re-applying is offered, never done quietly.
   */
  it('offers to re-apply after a version conflict instead of overwriting', () => {
    const element = initialise([dashboard({ version: 4 })], [widget({ id: 'a' })]);
    startEditing();

    click('Move right');
    click('Save the arrangement');
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/layout`).flush(
      {
        code: 'DASHBOARD_VERSION_CONFLICT',
        type: '',
        title: '',
        status: 409,
        detail: '',
        instance: '',
        request_id: '',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    expect(element.textContent).toContain('Somebody else changed this dashboard');

    click('Reload and apply my arrangement');
    // It takes the other tab's dashboard as it now is…
    http
      .expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/widgets`)
      .flush({ widgets: [widget({ id: 'a' })] });
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}`).flush({ dashboard: dashboard({ version: 9 }) });

    // …and re-sends against the version that dashboard now carries.
    const retry = http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/layout`);
    expect(retry.request.body.expected_version).toBe(9);
    retry.flush({ widgets: [] });
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}`).flush({ dashboard: dashboard({ version: 9 }) });
  });

  it('drops the draft when arranging is cancelled', () => {
    const element = initialise([dashboard()], [widget({ id: 'a', x: 0 })]);
    startEditing();

    click('Move right');
    click('Cancel');

    const cell = element.querySelector<HTMLElement>('.dashboard__cell');
    expect(cell?.style.getPropertyValue('--widget-column')).toBe('1');
  });

  it('removes a widget straight away, because that is not a layout change', () => {
    initialise([dashboard()], [widget({ id: 'a' })]);
    startEditing();

    click('Remove');

    const request = http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/widgets/a`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}`).flush({ dashboard: dashboard({ version: 5 }) });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelectorAll('.dashboard__cell')).toHaveLength(0);
  });

  it('says a dashboard is gone rather than forbidden when its widgets cannot be read', () => {
    fixture.detectChanges();
    http.expectOne(DASHBOARDS).flush({ dashboards: [dashboard()], active_dashboard_id: null });
    http
      .expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/widgets`)
      .flush('gone', { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('This dashboard is no longer available.');
  });
});
