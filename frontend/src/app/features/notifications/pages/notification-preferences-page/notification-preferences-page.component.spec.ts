import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { NotificationPreference } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { NotificationPreferencesPageComponent } from './notification-preferences-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const PREFERENCES = `/api/v1/tenants/${TENANT_ID}/notification-preferences`;

const STORED: readonly NotificationPreference[] = [
  { kind: 'ISSUE_ASSIGNED', in_app: true, email: false, in_app_locked: true },
  { kind: 'ISSUE_COMMENTED', in_app: true, email: false, in_app_locked: false },
];

describe('NotificationPreferencesPageComponent', () => {
  let fixture: ComponentFixture<NotificationPreferencesPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [NotificationPreferencesPageComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: TenantStore,
          useValue: {
            activeTenantId: () => TENANT_ID,
            hasAnyPermission: () => true,
            hasPermission: () => true,
          },
        },
      ],
    });

    fixture = TestBed.createComponent(NotificationPreferencesPageComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    for (const pending of http.match(() => true)) {
      pending.flush({ notifications: [], unread_count: 0, preferences: [] });
    }

    http.verify();
  });

  function initialise(): HTMLElement {
    fixture.detectChanges();
    http.expectOne(PREFERENCES).flush({ preferences: STORED });
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('disables the channel the domain does not let anybody switch off', () => {
    const element = initialise();
    const locked = element.querySelector<HTMLInputElement>('#in-app-ISSUE_ASSIGNED');
    const free = element.querySelector<HTMLInputElement>('#in-app-ISSUE_COMMENTED');

    expect(locked?.disabled).toBe(true);
    expect(free?.disabled).toBe(false);
  });

  it('submits every row, not only the one that was touched', () => {
    const element = initialise();
    const email = element.querySelector<HTMLInputElement>('#email-ISSUE_COMMENTED');

    email?.click();
    fixture.detectChanges();

    const save = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.includes('Save settings'),
    );

    save?.click();

    const request = http.expectOne(PREFERENCES);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      preferences: [
        { kind: 'ISSUE_ASSIGNED', in_app: true, email: false },
        { kind: 'ISSUE_COMMENTED', in_app: true, email: true },
      ],
    });

    request.flush({
      preferences: [
        STORED[0],
        { kind: 'ISSUE_COMMENTED', in_app: true, email: true, in_app_locked: false },
      ],
    });
    fixture.detectChanges();

    expect(element.textContent).toContain('Settings saved.');
  });

  it('keeps the form and says the write failed rather than claiming success', () => {
    const element = initialise();
    const save = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.includes('Save settings'),
    );

    save?.click();
    http.expectOne(PREFERENCES).flush({}, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(element.textContent).toContain('The settings could not be saved.');
    expect(element.querySelector('#in-app-ISSUE_ASSIGNED')).not.toBeNull();
  });
});
