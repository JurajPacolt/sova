import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AccessibleTenant } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantSelectionPageComponent } from './tenant-selection-page.component';

const TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme Workspace',
  slug: 'acme',
  status: 'ACTIVE',
  access: {
    type: 'MEMBERSHIP',
    membership_id: '019f9f00-0000-7000-8000-000000000002',
  },
};

describe('TenantSelectionPageComponent', () => {
  const api = {
    listTenants: vi.fn(),
  };

  beforeEach(async () => {
    api.listTenants.mockReset();
    api.listTenants.mockReturnValue(of({ tenants: [TENANT] }));

    await TestBed.configureTestingModule({
      imports: [TenantSelectionPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    }).compileComponents();
  });

  it('renders tenant data loaded from the API instead of demo data', () => {
    const fixture = TestBed.createComponent(TenantSelectionPageComponent);
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    const link = (fixture.nativeElement as HTMLElement).querySelector<HTMLAnchorElement>(
      'a.btn-outline-primary',
    );

    expect(api.listTenants).toHaveBeenCalledOnce();
    expect(text).toContain('Acme Workspace');
    expect(text).not.toContain('SOVA Demo');
    expect(link?.getAttribute('href')).toBe('/t/acme/dashboard');
  });
});
