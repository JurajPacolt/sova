import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { DashboardEntryComponent } from './dashboard-entry.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const DASHBOARDS = `/api/v1/tenants/${TENANT_ID}/dashboards`;

describe('DashboardEntryComponent', () => {
  let fixture: ComponentFixture<DashboardEntryComponent>;
  let http: HttpTestingController;
  let permitted: boolean;

  beforeEach(() => {
    permitted = true;

    TestBed.configureTestingModule({
      imports: [DashboardEntryComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([{ path: ':dashboardId', children: [] }]),
        {
          provide: TenantStore,
          useValue: {
            activeTenantId: () => TENANT_ID,
            hasAnyPermission: () => permitted,
          },
        },
      ],
    });

    fixture = TestBed.createComponent(DashboardEntryComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  /**
   * The bare path is a redirect, so it replaces itself: Back should leave the
   * dashboards rather than bounce between the entry and the dashboard.
   */
  it('replaces itself with the dashboard the server says to open', async () => {
    const router = TestBed.inject(Router);
    fixture.detectChanges();
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: DASHBOARD_ID });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(router.url).toContain(DASHBOARD_ID);
  });

  it('offers the template when the caller has no dashboard at all', () => {
    fixture.detectChanges();
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('You do not have a dashboard yet.');

    element.querySelector('button')?.click();
    fixture.detectChanges();

    const request = http.expectOne(`${DASHBOARDS}/from-template`);
    expect(request.request.method).toBe('POST');
    request.flush({ dashboard: { id: DASHBOARD_ID }, widgets: [] });
  });

  /**
   * Someone who may not create a dashboard is not offered one. The button would
   * only produce a `403` the backend was always going to send.
   */
  it('does not offer the template without permission to create one', () => {
    permitted = false;
    fixture.detectChanges();
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('button')).toBeNull();
  });
});
