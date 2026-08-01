import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { SystemSecurityAuditService } from './system-security-audit.service';

describe('SystemSecurityAuditService', () => {
  const api = {
    listSystemSecurityAudit: vi.fn(),
  };

  beforeEach(() => {
    api.listSystemSecurityAudit.mockReset();
    TestBed.configureTestingModule({
      providers: [
        SystemSecurityAuditService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
  });

  it('keeps keyset and filter parameters in the dedicated system request', async () => {
    const query = {
      limit: 25,
      cursor: 'opaque-cursor',
      event_type: 'SYSTEM_TENANT_CREATED',
      outcome: 'SUCCESS' as const,
    };
    const page = {
      events: [],
      next_cursor: null,
    };
    api.listSystemSecurityAudit.mockReturnValue(of(page));

    await expect(
      firstValueFrom(TestBed.inject(SystemSecurityAuditService).list(query)),
    ).resolves.toEqual(page);
    expect(api.listSystemSecurityAudit).toHaveBeenCalledWith(query);
  });
});
