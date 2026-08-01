import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DashboardWorkspaceService } from './dashboard-workspace.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const WIDGET_ID = '019f9f00-0000-7000-8000-000000000003';
const DASHBOARDS = `/api/v1/tenants/${TENANT_ID}/dashboards`;

describe('DashboardWorkspaceService', () => {
  let service: DashboardWorkspaceService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(DashboardWorkspaceService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  it('reads the caller own dashboards and the one to open', () => {
    let activeId: string | null = null;
    service.list(TENANT_ID).subscribe((response) => (activeId = response.active_dashboard_id));

    const request = http.expectOne(DASHBOARDS);
    expect(request.request.method).toBe('GET');
    request.flush({ dashboards: [], active_dashboard_id: DASHBOARD_ID });

    expect(activeId).toBe(DASHBOARD_ID);
  });

  /**
   * The preference has its own endpoint precisely so that reading a dashboard
   * cannot move it. Recording it must therefore be a write the client makes on
   * purpose, never a by-product of loading.
   */
  it('records the last active dashboard through its own request', () => {
    service.markActive(TENANT_ID, DASHBOARD_ID).subscribe();

    const request = http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/active`);
    expect(request.request.method).toBe('PUT');
    request.flush({ dashboard: { id: DASHBOARD_ID } });
  });

  it('asks for each widget data separately', () => {
    service.widgetData(TENANT_ID, DASHBOARD_ID, WIDGET_ID).subscribe();

    const request = http.expectOne(`${DASHBOARDS}/${DASHBOARD_ID}/widgets/${WIDGET_ID}/data`);
    expect(request.request.method).toBe('GET');
    request.flush({ data: { count: 3 } });
  });

  it('restores from the template without naming one, so the server decides', () => {
    service.restoreFromTemplate(TENANT_ID).subscribe();

    const request = http.expectOne(`${DASHBOARDS}/from-template`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});
    request.flush({ dashboard: { id: DASHBOARD_ID }, widgets: [] });
  });

  it('passes a chosen name to the template endpoint', () => {
    service.restoreFromTemplate(TENANT_ID, 'Release watch').subscribe();

    const request = http.expectOne(`${DASHBOARDS}/from-template`);
    expect(request.request.body).toEqual({ name: 'Release watch' });
    request.flush({ dashboard: { id: DASHBOARD_ID }, widgets: [] });
  });
});
