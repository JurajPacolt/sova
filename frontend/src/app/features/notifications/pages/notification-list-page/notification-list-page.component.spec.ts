import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { NotificationEntry } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { NotificationListPageComponent } from './notification-list-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const INBOX = `/api/v1/tenants/${TENANT_ID}/notifications`;

function entry(overrides: Partial<NotificationEntry> = {}): NotificationEntry {
  return {
    id: '019f9f00-0000-7000-8000-0000000000a1',
    kind: 'ISSUE_COMMENTED',
    project_id: '019f9f00-0000-7000-8000-0000000000b1',
    issue_id: '019f9f00-0000-7000-8000-0000000000c1',
    actor: { user_id: '019f9f00-0000-7000-8000-0000000000d1', display_name: 'Iva Novak' },
    payload: { issue_key: 'SOVA-1', issue_title: 'Login fails on the second attempt' },
    read_at: null,
    created_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

describe('NotificationListPageComponent', () => {
  let fixture: ComponentFixture<NotificationListPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [NotificationListPageComponent],
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

    fixture = TestBed.createComponent(NotificationListPageComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    // The badge in the shell polls the unread endpoint through the same service;
    // this screen's behaviour is the full list, so that one is answered aside.
    for (const pending of http.match(() => true)) {
      pending.flush({ notifications: [], unread_count: 0 });
    }

    http.verify();
  });

  function initialise(entries: readonly NotificationEntry[], unread: number): HTMLElement {
    fixture.detectChanges();
    http
      .expectOne((request) => request.url === INBOX && !request.params.has('unread'))
      .flush({ notifications: entries, unread_count: unread });
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('lists what arrived and says how many are unread', () => {
    const element = initialise(
      [entry(), entry({ id: 'second', read_at: '2026-07-29T11:00:00Z' })],
      1,
    );

    expect(element.querySelectorAll('.notification-list__item')).toHaveLength(2);
    expect(element.querySelectorAll('.notification-list__item--unread')).toHaveLength(1);
    expect(element.textContent).toContain('SOVA-1');
  });

  it('narrows to one kind without asking the server again', () => {
    const element = initialise(
      [entry(), entry({ id: 'second', kind: 'ISSUE_MENTIONED', payload: { issue_key: 'SOVA-2' } })],
      2,
    );
    const mentions = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Mention',
    );

    mentions?.click();
    fixture.detectChanges();

    expect(element.querySelectorAll('.notification-list__item')).toHaveLength(1);
    expect(element.textContent).toContain('SOVA-2');
  });

  it('marks everything read with one request and no identifiers', () => {
    const element = initialise([entry()], 1);
    const markAll = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.includes('Mark all as read'),
    );

    markAll?.click();
    const request = http.expectOne(`${INBOX}/read`);

    expect(request.request.body).toEqual({});

    request.flush({ updated: 1, unread_count: 0 });
    fixture.detectChanges();

    expect(element.querySelectorAll('.notification-list__item--unread')).toHaveLength(0);
  });

  it('offers to repeat a failed load rather than showing an empty inbox', () => {
    fixture.detectChanges();
    http
      .expectOne((request) => request.url === INBOX && !request.params.has('unread'))
      .flush({}, { status: 503, statusText: 'Service Unavailable' });
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('app-error-state')).not.toBeNull();
    expect(element.textContent).not.toContain('Nothing here yet.');
  });
});
