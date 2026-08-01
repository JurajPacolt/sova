import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { AccessibleTenant, ProjectListItem } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ProjectListPageComponent } from './project-list-page.component';

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

const PUBLIC_PROJECT: ProjectListItem = {
  id: '019f9f00-0000-7000-8000-000000000003',
  tenant_id: TENANT.id,
  code: 'APP',
  name: 'Application',
  description: 'Customer facing application.',
  visibility: 'TENANT',
  status: 'ACTIVE',
  lead: null,
  member_count: 2,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
  viewer_roles: [],
};

const PRIVATE_PROJECT: ProjectListItem = {
  ...PUBLIC_PROJECT,
  id: '019f9f00-0000-7000-8000-000000000004',
  code: 'SEC',
  name: 'Security',
  description: '',
  visibility: 'PRIVATE',
  status: 'ARCHIVED',
  viewer_roles: ['PROJECT_MANAGER'],
};

const MEMBERSHIP = {
  id: '019f9f00-0000-7000-8000-000000000005',
  user: {
    id: '019f9f00-0000-7000-8000-000000000006',
    email: 'petra@example.test',
    display_name: 'Petra Member',
  },
  status: 'ACTIVE' as const,
  joined_at: '2026-07-01T00:00:00+00:00',
  roles: [],
};

describe('ProjectListPageComponent', () => {
  const api = {
    listProjects: vi.fn(),
    createProject: vi.fn(),
    listTenantMemberships: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listProjects.mockReturnValue(of({ projects: [PUBLIC_PROJECT, PRIVATE_PROJECT] }));
    api.listTenantMemberships.mockReturnValue(of({ memberships: [MEMBERSHIP] }));

    await TestBed.configureTestingModule({
      imports: [ProjectListPageComponent],
      providers: [provideRouter([]), { provide: SovaApiClient, useValue: api }],
    }).compileComponents();

    const tenantStore = TestBed.inject(TenantStore);
    tenantStore.setTenants([TENANT]);
    tenantStore.setActiveTenant(TENANT);
  });

  it('shows only active projects until the status filter is widened', () => {
    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(api.listProjects).toHaveBeenCalledWith(TENANT.id);
    expect(element.textContent ?? '').toContain('Application');
    expect(element.textContent ?? '').not.toContain('Security');

    const statusFilter = element.querySelector<HTMLSelectElement>('#project-status');
    statusFilter!.value = 'ALL';
    statusFilter!.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(element.textContent ?? '').toContain('Security');
  });

  it('renders the private badge and the viewer roles of a project', () => {
    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    const statusFilter = element.querySelector<HTMLSelectElement>('#project-status');
    statusFilter!.value = 'ALL';
    statusFilter!.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const text = element.textContent ?? '';
    expect(text).toContain('Private');
    expect(text).toContain('PROJECT_MANAGER');
  });

  it('filters the list by code or name', () => {
    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    const search = element.querySelector<HTMLInputElement>('#project-search');
    search!.value = 'zzz';
    search!.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(element.textContent ?? '').not.toContain('Application');
    expect(element.textContent ?? '').toContain('No project matches the filter.');
  });

  it('refuses to submit a private project without a lead', () => {
    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    const newButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'New project',
    );
    newButton!.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    setInputValue(element, '#project-code', 'SEC');
    setInputValue(element, '#project-name', 'Security');
    const visibility = element.querySelector<HTMLSelectElement>('#project-visibility');
    visibility!.value = 'PRIVATE';
    visibility!.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    element.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    fixture.detectChanges();

    expect(api.createProject).not.toHaveBeenCalled();
    expect(element.textContent ?? '').toContain('A private project requires a lead.');
  });

  it('creates a tenant-visible project and reloads the list', () => {
    api.createProject.mockReturnValue(of({ project: PUBLIC_PROJECT }));

    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    const newButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'New project',
    );
    newButton!.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    setInputValue(element, '#project-code', 'APP');
    setInputValue(element, '#project-name', 'Application');
    element.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

    expect(api.createProject).toHaveBeenCalledWith(TENANT.id, {
      code: 'APP',
      name: 'Application',
      description: '',
      visibility: 'TENANT',
    });
    expect(api.listProjects).toHaveBeenCalledTimes(2);
  });

  it('explains a rejected creation permission', () => {
    api.createProject.mockReturnValue(throwError(() => ({ status: 403 })));

    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    const newButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'New project',
    );
    newButton!.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    setInputValue(element, '#project-code', 'APP');
    setInputValue(element, '#project-name', 'Application');
    element.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    fixture.detectChanges();

    expect(element.textContent ?? '').toContain(
      'You are not allowed to create projects in this tenant.',
    );
  });

  /**
   * The failure carries what `HttpClient` really emits, because the shared
   * error state reads the status off it: a stub shaped like `{ status }` would
   * pass a test that production never runs.
   */
  it('offers a retry when the listing fails, in the same words as every screen', () => {
    api.listProjects.mockReturnValue(
      throwError(() => new HttpErrorResponse({ status: 500, statusText: 'Server Error' })),
    );

    const fixture = TestBed.createComponent(ProjectListPageComponent);
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent ?? '').toContain('The service failed to answer.');
    expect(
      Array.from(element.querySelectorAll('button')).some((button) =>
        button.textContent?.includes('Try again'),
      ),
    ).toBe(true);
  });
});

function setInputValue(element: HTMLElement, selector: string, value: string): void {
  const input = element.querySelector<HTMLInputElement>(selector);
  input!.value = value;
  input!.dispatchEvent(new Event('input'));
}
