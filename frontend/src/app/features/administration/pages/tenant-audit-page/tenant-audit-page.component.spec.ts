import { HttpResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AccessibleTenant, SecurityAuditEvent } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { TenantAuditPageComponent } from './tenant-audit-page.component';

const TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme',
  slug: 'acme',
  status: 'ACTIVE',
  access: {
    type: 'MEMBERSHIP',
    membership_id: '019f9f00-0000-7000-8000-000000000002',
  },
};

const EVENT: SecurityAuditEvent = {
  id: '019f9f00-0000-7000-8000-000000000003',
  actor: {
    id: '019f9f00-0000-7000-8000-000000000004',
    email: 'owner@example.test',
    display_name: 'Owner',
  },
  effective_user: null,
  tenant: {
    id: TENANT.id,
    name: TENANT.name,
    slug: TENANT.slug,
  },
  event_type: 'TENANT_MEMBERSHIP_DISABLED',
  outcome: 'SUCCESS',
  reason_code: 'TENANT_MEMBERSHIP_DISABLED',
  request_id: 'req-1',
  ip_address: null,
  metadata: {},
  occurred_at: '2026-07-27T00:00:00+00:00',
};

describe('TenantAuditPageComponent', () => {
  const api = {
    listTenantSecurityAudit: vi.fn(),
    exportTenantSecurityAudit: vi.fn(),
  };

  beforeEach(async () => {
    api.listTenantSecurityAudit.mockReset();
    api.exportTenantSecurityAudit.mockReset();
    api.listTenantSecurityAudit.mockReturnValue(of({ events: [EVENT], next_cursor: null }));

    await TestBed.configureTestingModule({
      imports: [TenantAuditPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    }).compileComponents();

    const tenantStore = TestBed.inject(TenantStore);
    tenantStore.setTenants([TENANT]);
    tenantStore.setActiveTenant(TENANT);
  });

  it('loads the tenant-scoped audit page on init and renders the events', () => {
    const fixture = TestBed.createComponent(TenantAuditPageComponent);
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(api.listTenantSecurityAudit).toHaveBeenCalledWith(
      TENANT.id,
      expect.objectContaining({ limit: 50 }),
    );
    expect(text).toContain('TENANT_MEMBERSHIP_DISABLED');
    expect(text).toContain('Owner');
  });

  it('triggers a CSV export for the active tenant with the current filters', () => {
    api.exportTenantSecurityAudit.mockReturnValue(
      of(new HttpResponse({ body: new Blob(['csv']) })),
    );

    const fixture = TestBed.createComponent(TenantAuditPageComponent);
    fixture.detectChanges();

    const anchor = document.createElement('a');
    vi.spyOn(anchor, 'click').mockImplementation(() => {});
    const originalCreateElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation(((tagName: string) =>
      tagName === 'a' ? anchor : originalCreateElement(tagName)) as typeof document.createElement);
    vi.stubGlobal('URL', {
      ...URL,
      createObjectURL: vi.fn().mockReturnValue('blob:mock'),
      revokeObjectURL: vi.fn(),
    });

    const exportButton = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((button) => button.textContent?.trim() === 'Export CSV');
    exportButton?.dispatchEvent(new Event('click'));

    expect(api.exportTenantSecurityAudit).toHaveBeenCalledWith(
      TENANT.id,
      expect.objectContaining({ limit: 50 }),
    );

    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });
});
