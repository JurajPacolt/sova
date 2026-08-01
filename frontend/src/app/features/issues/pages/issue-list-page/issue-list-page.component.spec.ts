import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { IssueSearchHit } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { IssueListPageComponent } from './issue-list-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const SEARCH = `/api/v1/tenants/${TENANT_ID}/issues/search`;

function hit(overrides: Partial<IssueSearchHit> = {}): IssueSearchHit {
  return {
    id: '019f9f00-0000-7000-8000-0000000000c1',
    key: 'SOVA-1',
    title: 'Login fails on the second attempt',
    project: { id: 'p-1', code: 'SOVA', name: 'SOVA' },
    issue_type: { code: 'BUG', name: 'Bug', hierarchy_level: 0 },
    status: { code: 'OPEN', name: 'Open', category: 'TO_DO' },
    priority: 'NORMAL',
    assignee: null,
    assignee_workgroup: null,
    parent_key: null,
    blocked: false,
    resolution: null,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    resolved_at: null,
    ...overrides,
  };
}

describe('IssueListPageComponent', () => {
  let fixture: ComponentFixture<IssueListPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [IssueListPageComponent],
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

    fixture = TestBed.createComponent(IssueListPageComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    // The panels around the search box fetch their own catalogues; this screen's
    // behaviour is the search, so those are answered and set aside.
    for (const pending of http.match(() => true)) {
      pending.flush({ saved_queries: [], projects: [], fields: [], functions: [], limits: {} });
    }

    http.verify();
  });

  function initialise(hits: readonly IssueSearchHit[], nextCursor: string | null): HTMLElement {
    fixture.detectChanges();
    http.expectOne(SEARCH).flush({ issues: hits, next_cursor: nextCursor, total: null });
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  function click(label: string): void {
    const element: HTMLElement = fixture.nativeElement;
    const target = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes(label),
    );

    target?.click();
    fixture.detectChanges();
  }

  /**
   * A failed *next page* is not a failed search: the rows that already arrived
   * are still the answer to the same query, and dropping them would cost the
   * reader their place for nothing.
   */
  it('keeps the rows already loaded when the next page fails', () => {
    const element = initialise([hit()], 'cursor-2');

    click('Load more');
    http.expectOne(SEARCH).flush('boom', { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(element.textContent).toContain('SOVA-1');
    expect(element.textContent).toContain('The service failed to answer.');
  });

  /**
   * A rejected query is the reader's to fix and reads that way; a service that
   * could not answer is not, and only that one is worth trying again.
   */
  it('separates a refused query from a service that could not answer', () => {
    fixture.detectChanges();
    http.expectOne(SEARCH).flush(
      {
        code: 'SOVAQL_INVALID',
        type: '',
        title: '',
        status: 422,
        detail: '',
        instance: '',
        request_id: '',
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('The query could not be run.');
    // Sending the same rejected query again would be refused the same way.
    expect(
      [...element.querySelectorAll('button')].some((button) =>
        button.textContent?.includes('Try again'),
      ),
    ).toBe(false);
  });
});
