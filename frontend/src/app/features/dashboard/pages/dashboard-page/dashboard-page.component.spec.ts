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
