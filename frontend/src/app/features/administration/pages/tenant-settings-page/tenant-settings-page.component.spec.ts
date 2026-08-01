import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { AccessibleTenant, TenantSettings } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { TenantSettingsPageComponent } from './tenant-settings-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const SETTINGS_URL = `/api/v1/tenants/${TENANT_ID}/settings`;
const TENANT: AccessibleTenant = {
  id: TENANT_ID,
  name: 'Original tenant',
  slug: 'original',
  status: 'ACTIVE',
  access: { type: 'MEMBERSHIP', membership_id: 'membership-1' },
};
const SETTINGS: TenantSettings = {
  tenant_id: TENANT_ID,
  name: 'Original tenant',
  slug: 'original',
  default_locale: 'sk',
  timezone: 'Europe/Bratislava',
  revision: 4,
};

describe('TenantSettingsPageComponent', () => {
  let fixture: ComponentFixture<TenantSettingsPageComponent>;
  let http: HttpTestingController;
  let store: TenantStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [TenantSettingsPageComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    store = TestBed.inject(TenantStore);
    store.setActiveTenant(TENANT, ['tenant.settings.manage']);
    fixture = TestBed.createComponent(TenantSettingsPageComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    store.clear();
  });

  function initialise(): HTMLElement {
    fixture.detectChanges();
    http.expectOne(SETTINGS_URL).flush({ settings: SETTINGS });
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function enter(element: HTMLElement, selector: string, value: string): void {
    const input = element.querySelector<HTMLInputElement>(selector);

    expect(input).not.toBeNull();
    input!.value = value;
    input!.dispatchEvent(new Event('input'));
  }

  function submit(element: HTMLElement, sectionId: string): void {
    const form = element
      .querySelector<HTMLElement>(`#${sectionId}`)
      ?.closest('section')
      ?.querySelector<HTMLFormElement>('form');

    expect(form).toBeTruthy();
    form!.dispatchEvent(new Event('submit'));
  }

  it('saves general information against the loaded revision and updates the shell tenant', () => {
    const element = initialise();

    enter(element, '#tenant-settings-name', 'Renamed tenant');
    submit(element, 'general-settings-title');

    const request = http.expectOne(`${SETTINGS_URL}/general`);

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({
      name: 'Renamed tenant',
      expected_revision: 4,
    });
    request.flush({
      settings: { ...SETTINGS, name: 'Renamed tenant', revision: 5 },
    });
    fixture.detectChanges();

    expect(store.activeTenant()?.name).toBe('Renamed tenant');
    expect(element.textContent).toContain('General information saved.');
  });

  it('saves localization as a separate section', () => {
    const element = initialise();
    const locale = element.querySelector<HTMLSelectElement>('#tenant-settings-locale');

    expect(locale).not.toBeNull();
    locale!.value = 'en';
    locale!.dispatchEvent(new Event('change'));
    enter(element, '#tenant-settings-timezone', 'Europe/London');
    submit(element, 'localization-settings-title');

    const request = http.expectOne(`${SETTINGS_URL}/localization`);

    expect(request.request.body).toEqual({
      default_locale: 'en',
      timezone: 'Europe/London',
      expected_revision: 4,
    });
    request.flush({
      settings: {
        ...SETTINGS,
        default_locale: 'en',
        timezone: 'Europe/London',
        revision: 5,
      },
    });
    fixture.detectChanges();

    expect(element.textContent).toContain('Language and time zone saved.');
  });

  it('reports an optimistic conflict without changing the other form', () => {
    const element = initialise();

    enter(element, '#tenant-settings-name', 'Conflicting name');
    submit(element, 'general-settings-title');
    http.expectOne(`${SETTINGS_URL}/general`).flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: 'The tenant changed.',
        instance: `${SETTINGS_URL}/general`,
        request_id: 'req-1',
        code: 'TENANT_REVISION_CONFLICT',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    expect(element.textContent).toContain('Another administrator changed the tenant.');
    expect(element.querySelector<HTMLInputElement>('#tenant-settings-timezone')?.value).toBe(
      'Europe/Bratislava',
    );
  });
});
