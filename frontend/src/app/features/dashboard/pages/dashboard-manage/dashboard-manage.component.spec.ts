import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Dashboard } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { DashboardManageComponent } from './dashboard-manage.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const FIRST_ID = '019f9f00-0000-7000-8000-000000000002';
const SECOND_ID = '019f9f00-0000-7000-8000-000000000003';
const DASHBOARDS = `/api/v1/tenants/${TENANT_ID}/dashboards`;

function dashboard(overrides: Partial<Dashboard> = {}): Dashboard {
  return {
    id: FIRST_ID,
    name: 'My work',
    position: 0,
    is_default: true,
    is_active: true,
    widget_count: 4,
    version: 3,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

describe('DashboardManageComponent', () => {
  let fixture: ComponentFixture<DashboardManageComponent>;
  let http: HttpTestingController;
  let permissions: Set<string>;

  beforeEach(() => {
    permissions = new Set(['dashboard.create', 'dashboard.update-own', 'dashboard.delete-own']);

    TestBed.configureTestingModule({
      imports: [DashboardManageComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: TenantStore,
          useValue: {
            activeTenantId: () => TENANT_ID,
            hasAnyPermission: (codes: readonly string[]) =>
              codes.some((code) => permissions.has(code)),
          },
        },
      ],
    });

    fixture = TestBed.createComponent(DashboardManageComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  function initialise(dashboards: readonly Dashboard[]): HTMLElement {
    fixture.detectChanges();
    http.expectOne(DASHBOARDS).flush({ dashboards, active_dashboard_id: null });
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  function click(label: string, index = 0): void {
    const element: HTMLElement = fixture.nativeElement;
    const matches = [...element.querySelectorAll('button')].filter(
      (button) =>
        button.textContent?.trim() === label || button.getAttribute('aria-label') === label,
    );
    const target = matches[index];

    if (target === undefined) {
      throw new Error(`No button "${label}" at index ${index}.`);
    }

    target.click();
    fixture.detectChanges();
  }

  /** jsdom does not submit a form when a submit button is clicked. */
  function submitCreateForm(): void {
    const form: HTMLFormElement = fixture.nativeElement.querySelector('form');
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  function type(selector: string, value: string): void {
    const input: HTMLInputElement = fixture.nativeElement.querySelector(selector);
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  /** The order the switcher uses, so the two screens agree on what is first. */
  it('lists dashboards by position, with the identifier settling ties', () => {
    const element = initialise([
      dashboard({ id: SECOND_ID, name: 'Release watch', position: 1, is_default: false }),
      dashboard({ name: 'My work', position: 0 }),
    ]);

    const names = [...element.querySelectorAll('tbody tr td:first-child')].map((cell) =>
      cell.textContent?.trim().split('\n')[0].trim(),
    );
    expect(names[0]).toContain('My work');
    expect(names[1]).toContain('Release watch');
  });

  it('creates a dashboard under the name that was typed', () => {
    initialise([dashboard()]);

    type('#dashboard-name', 'Release watch');
    submitCreateForm();

    const request = http.expectOne(DASHBOARDS);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Release watch' });
    request.flush({ dashboard: dashboard({ id: SECOND_ID }) });
    // The list is read back rather than patched locally, so positions and
    // versions come from the server that owns them.
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  it('renames against the version it was shown, so a stale edit is refused', () => {
    initialise([dashboard({ version: 7 })]);

    click('Rename');
    type('tbody input[type="text"]', 'Daily work');
    click('Confirm');

    const request = http.expectOne(`${DASHBOARDS}/${FIRST_ID}`);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ expected_version: 7, name: 'Daily work' });
    request.flush({ dashboard: dashboard() });
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  /**
   * The server refuses a name the owner already uses, and a conflict is the
   * wrong answer to "make me a copy" — the button exists so that nobody has to
   * invent a name first.
   */
  it('duplicates under a name the owner does not already hold', () => {
    initialise([dashboard({ name: 'My work' }), dashboard({ id: SECOND_ID, name: 'My work 2' })]);

    click('Duplicate');

    const request = http.expectOne(`${DASHBOARDS}/${FIRST_ID}/copy`);
    expect(request.request.method).toBe('POST');
    // "My work 2" is taken, so the copy counts on.
    expect(request.request.body).toEqual({ name: 'My work 3' });
    request.flush({ dashboard: dashboard() });
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  it('moves the default flag with one request', () => {
    initialise([
      dashboard(),
      dashboard({ id: SECOND_ID, name: 'Release watch', position: 1, is_default: false }),
    ]);

    click('Make default');

    const request = http.expectOne(`${DASHBOARDS}/${SECOND_ID}/default`);
    expect(request.request.method).toBe('PUT');
    request.flush({ dashboard: dashboard({ id: SECOND_ID }) });
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  /**
   * The endpoint moves one dashboard at a time, so a swap is two writes; each
   * carries its own version, and the list is re-read afterwards.
   */
  it('reorders by swapping the two positions', () => {
    initialise([
      dashboard({ position: 0, version: 2 }),
      dashboard({ id: SECOND_ID, name: 'Release watch', position: 1, version: 5 }),
    ]);

    click('Move up', 1);

    const first = http.expectOne(`${DASHBOARDS}/${SECOND_ID}`);
    expect(first.request.body).toEqual({
      expected_version: 5,
      name: 'Release watch',
      position: 0,
    });
    first.flush({ dashboard: dashboard({ id: SECOND_ID }) });

    const second = http.expectOne(`${DASHBOARDS}/${FIRST_ID}`);
    expect(second.request.body).toEqual({ expected_version: 2, name: 'My work', position: 1 });
    second.flush({ dashboard: dashboard() });

    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  it('asks before deleting and only then removes', () => {
    initialise([dashboard(), dashboard({ id: SECOND_ID, name: 'Release watch', position: 1 })]);

    click('Delete');
    // Nothing has been sent yet; the row asks first.
    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('Delete this dashboard?');

    click('Confirm');
    const request = http.expectOne(`${DASHBOARDS}/${FIRST_ID}`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    http.expectOne(DASHBOARDS).flush({ dashboards: [], active_dashboard_id: null });
  });

  it('explains that the last dashboard stays instead of blaming the request', () => {
    const element = initialise([dashboard()]);

    click('Delete');
    click('Confirm');
    http.expectOne(`${DASHBOARDS}/${FIRST_ID}`).flush(
      {
        code: 'LAST_DASHBOARD_REQUIRED',
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

    expect(element.textContent).toContain('This is your last dashboard');
  });

  it('reports a name clash as a name clash', () => {
    const element = initialise([dashboard()]);

    type('#dashboard-name', 'My work');
    submitCreateForm();
    http.expectOne(DASHBOARDS).flush(
      {
        code: 'DASHBOARD_NAME_TAKEN',
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

    expect(element.textContent).toContain('You already have a dashboard with that name.');
  });

  /**
   * Buttons that would only produce a `403` the backend was always going to
   * send are not offered at all.
   */
  it('offers nothing the caller may not do', () => {
    permissions = new Set();
    const element = initialise([dashboard()]);

    expect(element.querySelector('#dashboard-name')).toBeNull();
    const labels = [...element.querySelectorAll('button')].map((button) =>
      button.textContent?.trim(),
    );
    expect(labels).not.toContain('Delete');
    expect(labels).not.toContain('Rename');
    expect(labels).not.toContain('Duplicate');
  });
});
