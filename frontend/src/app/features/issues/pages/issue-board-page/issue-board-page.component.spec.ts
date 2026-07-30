import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { IssueSearchHit } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { IssueBoardPageComponent } from './issue-board-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const PROJECT_ID = '019f9f00-0000-7000-8000-000000000002';
const ISSUE_ID = '019f9f00-0000-7000-8000-000000000003';
const BASE = `/api/v1/tenants/${TENANT_ID}`;

function hit(overrides: Partial<IssueSearchHit> = {}): IssueSearchHit {
  return {
    id: ISSUE_ID,
    key: 'SOVA-1',
    title: 'Login fails on the second attempt',
    project: { id: PROJECT_ID, code: 'SOVA', name: 'SOVA' },
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

describe('IssueBoardPageComponent', () => {
  let fixture: ComponentFixture<IssueBoardPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [IssueBoardPageComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: TenantStore,
          useValue: { activeTenantId: () => TENANT_ID, hasAnyPermission: () => true },
        },
      ],
    });

    fixture = TestBed.createComponent(IssueBoardPageComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('projectId', PROJECT_ID);
  });

  afterEach(() => {
    http.verify();
  });

  /** Project list → configuration (the columns) → the issues on the board. */
  function initialise(issues: readonly IssueSearchHit[] = [hit()]): HTMLElement {
    fixture.detectChanges();
    http.expectOne(`${BASE}/projects`).flush({
      projects: [{ id: PROJECT_ID, code: 'SOVA', name: 'SOVA', status: 'ACTIVE' }],
    });
    http.expectOne(`${BASE}/projects/${PROJECT_ID}/configuration`).flush({
      revision: 1,
      issue_types: [],
      statuses: [
        {
          id: 's-1',
          code: 'OPEN',
          name: 'Open',
          category: 'TO_DO',
          position: 0,
          status: 'ACTIVE',
        },
        {
          id: 's-2',
          code: 'DOING',
          name: 'In progress',
          category: 'IN_PROGRESS',
          position: 1,
          status: 'ACTIVE',
        },
      ],
    });
    http.expectOne(`${BASE}/issues/search`).flush({ issues, next_cursor: null, total: null });
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  function click(label: string): void {
    const element: HTMLElement = fixture.nativeElement;
    const target = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes(label),
    );

    if (target === undefined) {
      throw new Error(`No button labelled "${label}".`);
    }

    target.click();
    fixture.detectChanges();
  }

  /**
   * The board is operated by buttons, so it already works without a mouse. The
   * other half of that (webflow §13.2) is telling somebody the move happened:
   * a card landing in another column is not an event a screen reader notices.
   */
  it('announces a completed move to assistive technology', () => {
    const element = initialise();

    click('Move');
    http.expectOne(`${BASE}/issues/${ISSUE_ID}/transitions`).flush({
      transitions: [
        {
          id: 't-1',
          name: 'Start work',
          to_status: { id: 's-2', code: 'DOING', name: 'In progress', category: 'IN_PROGRESS' },
          is_primary: true,
          position: 0,
          required_fields: [],
        },
      ],
      issue_version: 4,
    });
    fixture.detectChanges();

    click('Move to In progress');
    const request = http.expectOne(`${BASE}/issues/${ISSUE_ID}/transitions/t-1`);
    expect(request.request.body.expected_issue_version).toBe(4);
    request.flush({ issue: {} });
    // Moving reloads the board, because the card only settles once the server agreed.
    http.expectOne(`${BASE}/issues/search`).flush({ issues: [], next_cursor: null, total: null });
    fixture.detectChanges();

    const live = element.querySelector('[aria-live="polite"]');
    expect(live?.textContent).toContain('SOVA-1 moved to In progress.');
  });

  /**
   * A transition that needs a resolution is not started blind: it would fail as
   * `ISSUE_TRANSITION_INVALID` and read as a bug, so the board sends the reader
   * to the detail screen, where there is room to ask.
   */
  it('does not start a transition that needs more input', () => {
    const element = initialise();

    click('Move');
    http.expectOne(`${BASE}/issues/${ISSUE_ID}/transitions`).flush({
      transitions: [
        {
          id: 't-2',
          name: 'Resolve',
          to_status: { id: 's-3', code: 'DONE', name: 'Done', category: 'DONE' },
          is_primary: true,
          position: 0,
          required_fields: ['resolution'],
        },
      ],
      issue_version: 4,
    });
    fixture.detectChanges();

    click('Move to Done');

    // `http.verify()` in afterEach proves nothing was sent.
    expect(element.textContent).toContain('resolution');
  });
  it('marks a blocked card from the row itself, without a request per card', () => {
    const element = initialise([hit({ blocked: true }), hit({ id: 'second', key: 'SOVA-2' })]);

    // One badge, one word — the flag travelled with the search projection, so
    // the board issued no extra request to find it out.
    expect(element.querySelectorAll('.board-card .badge').length).toBe(1);
    expect(element.textContent).toContain('Blocked');
  });
  /**
   * The gesture rides on the same move: a drop names a column, the card's legal
   * transitions are fetched, and the one that lands there is executed. The
   * button path is untouched and remains the keyboard route.
   */
  it('turns a drop on a column into the transition that lands there', () => {
    const element = initialise();
    const card = element.querySelector('.board-card');
    const column = element.querySelectorAll('.board-column')[1];

    card?.dispatchEvent(new Event('dragstart', { bubbles: true }));
    column?.dispatchEvent(new Event('drop', { bubbles: true }));
    fixture.detectChanges();

    http.expectOne(`${BASE}/issues/${ISSUE_ID}/transitions`).flush({
      issue_version: 4,
      transitions: [
        {
          id: 't-1',
          code: 'START',
          name: 'Start',
          to_status: { id: 's-2', code: 'DOING', name: 'In progress', category: 'IN_PROGRESS' },
          required_fields: [],
        },
      ],
    });
    fixture.detectChanges();

    const execution = http.expectOne(`${BASE}/issues/${ISSUE_ID}/transitions/t-1`);

    // The version the offer was computed against, exactly as the button sends it.
    expect(execution.request.body).toEqual({ expected_issue_version: 4 });
    execution.flush({ issue: {} });

    http.expectOne(`${BASE}/issues/search`).flush({ issues: [], next_cursor: null, total: null });
  });

  it('says so when the workflow has no move into the column that was dropped on', () => {
    const element = initialise();
    const card = element.querySelector('.board-card');
    const column = element.querySelectorAll('.board-column')[1];

    card?.dispatchEvent(new Event('dragstart', { bubbles: true }));
    column?.dispatchEvent(new Event('drop', { bubbles: true }));
    fixture.detectChanges();

    http
      .expectOne(`${BASE}/issues/${ISSUE_ID}/transitions`)
      .flush({ issue_version: 4, transitions: [] });
    fixture.detectChanges();

    expect(element.textContent).toContain('has no move from here into that column');
  });
});
