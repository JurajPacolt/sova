import { HttpHeaders, HttpResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { TenantSecurityAuditService } from './tenant-security-audit.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';

describe('TenantSecurityAuditService', () => {
  const api = {
    listTenantSecurityAudit: vi.fn(),
    exportTenantSecurityAudit: vi.fn(),
  };
  let service: TenantSecurityAuditService;

  beforeEach(() => {
    api.listTenantSecurityAudit.mockReset();
    api.exportTenantSecurityAudit.mockReset();
    TestBed.configureTestingModule({
      providers: [
        TenantSecurityAuditService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(TenantSecurityAuditService);
  });

  it('keeps the tenant ID and filter query for the list request', async () => {
    const query = { limit: 25, event_type: 'TENANT_MEMBERSHIP_DISABLED' };
    const page = { events: [], next_cursor: null };
    api.listTenantSecurityAudit.mockReturnValue(of(page));

    await expect(firstValueFrom(service.list(TENANT_ID, query))).resolves.toEqual(page);
    expect(api.listTenantSecurityAudit).toHaveBeenCalledWith(TENANT_ID, query);
  });

  it('downloads the exported CSV using the filename from Content-Disposition', async () => {
    const blob = new Blob(['id,event_type\n'], { type: 'text/csv' });
    api.exportTenantSecurityAudit.mockReturnValue(
      of(
        new HttpResponse({
          body: blob,
          headers: new HttpHeaders({
            'Content-Disposition': 'attachment; filename="tenant-audit-abc.csv"',
          }),
        }),
      ),
    );

    const createObjectURL = vi.fn().mockReturnValue('blob:mock-url');
    const revokeObjectURL = vi.fn();
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL });
    const link = document.createElement('a');
    const click = vi.spyOn(link, 'click').mockImplementation(() => {});
    vi.spyOn(document, 'createElement').mockReturnValue(link);

    await firstValueFrom(service.export(TENANT_ID, {}));

    expect(createObjectURL).toHaveBeenCalledWith(blob);
    expect(link.download).toBe('tenant-audit-abc.csv');
    expect(click).toHaveBeenCalledOnce();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:mock-url');

    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });
});
