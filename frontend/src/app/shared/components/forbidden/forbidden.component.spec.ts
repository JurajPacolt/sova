import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AccessibleTenant } from '../../../core/api/api.models';
import { TenantStore } from '../../../core/tenancy/tenant.store';
import { ForbiddenComponent } from './forbidden.component';

const TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  slug: 'acme',
  name: 'Acme',
  status: 'ACTIVE',
  access: { type: 'MEMBERSHIP', membership_id: '019f9f00-0000-7000-8000-0000000000m1' },
};

describe('ForbiddenComponent', () => {
  let fixture: ComponentFixture<ForbiddenComponent>;
  let store: TenantStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ForbiddenComponent],
      providers: [provideRouter([])],
    });

    store = TestBed.inject(TenantStore);
    fixture = TestBed.createComponent(ForbiddenComponent);
  });

  /**
   * It explains the refusal and says nothing about what is behind the door:
   * which members, projects or audit entries exist is exactly what the missing
   * permission was withholding.
   */
  it('names the refusal and offers somewhere that certainly exists', () => {
    store.setActiveTenant(TENANT, ['tenant.view']);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('You do not have access to this screen');
    expect(element.querySelector('a')?.getAttribute('href')).toBe('/t/acme/dashboards');
  });

  it('falls back to tenant selection when there is no tenant to go back to', () => {
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('a')?.getAttribute('href')).toBe('/select-tenant');
  });
});
