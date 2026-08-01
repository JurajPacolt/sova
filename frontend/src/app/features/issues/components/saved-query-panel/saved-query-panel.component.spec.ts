import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { SavedQuery } from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { SavedQueryPanelComponent } from './saved-query-panel.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const SAVED_QUERIES = `/api/v1/tenants/${TENANT_ID}/saved-queries`;

function savedQuery(overrides: Partial<SavedQuery> = {}): SavedQuery {
  return {
    id: '019f9f00-0000-7000-8000-0000000000aa',
    name: 'My open work',
    description: '',
    raw_query: 'project = app and priority = high',
    canonical_query: 'project = APP AND priority = HIGH',
    language_version: 1,
    default_columns: [],
    visibility: 'PRIVATE',
    version: 1,
    archived: false,
    owner: { membership_id: 'm-1', display_name: 'Owner' },
    viewer_access: 'EDIT',
    viewer_is_owner: true,
    favourite: false,
    created_at: '2026-07-28T10:00:00+00:00',
    updated_at: '2026-07-28T10:00:00+00:00',
    ...overrides,
  };
}

describe('SavedQueryPanelComponent', () => {
  let fixture: ComponentFixture<SavedQueryPanelComponent>;
  let http: HttpTestingController;
  let permissions: Set<string>;

  beforeEach(() => {
    permissions = new Set(['saved-query.create', 'saved-query.share']);

    TestBed.configureTestingModule({
      imports: [SavedQueryPanelComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: TenantStore,
          useValue: {
            activeTenantId: () => TENANT_ID,
            hasPermission: (code: string) => permissions.has(code),
          },
        },
      ],
    });

    fixture = TestBed.createComponent(SavedQueryPanelComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  function clickButton(label: string): void {
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
   * jsdom does not submit forms on its own, so the event is dispatched where a
   * browser would raise it — the component still reacts through `ngSubmit`.
   */
  function submitForm(): void {
    const form: HTMLFormElement | null = fixture.nativeElement.querySelector('form');

    if (form === null) {
      throw new Error('No form on screen.');
    }

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  function initialise(queries: readonly SavedQuery[]): void {
    fixture.detectChanges();
    http.expectOne(SAVED_QUERIES).flush({ saved_queries: queries });
    fixture.detectChanges();
  }

  it('loads a saved query as its raw text, not the canonical form', () => {
    initialise([savedQuery()]);

    clickButton('My open work');

    // Reopening shows what the author typed; the server normalises again on the
    // next save, so nothing is lost by not echoing its canonical form back.
    expect(fixture.componentInstance.query()).toBe('project = app and priority = high');
  });

  it('sends only the name and the raw query when saving', () => {
    initialise([]);
    fixture.componentInstance.query.set('project = APP');

    clickButton('Save this query');
    (fixture.componentInstance as unknown as { name: { set(value: string): void } }).name.set(
      '  Open work  ',
    );
    submitForm();

    const request = http.expectOne(SAVED_QUERIES);
    expect(request.request.method).toBe('POST');
    // No canonical form and no visibility: both are the server's to decide.
    expect(request.request.body).toEqual({ name: 'Open work', query: 'project = APP' });
    request.flush({ saved_query: savedQuery() }, { status: 201, statusText: 'Created' });

    http.expectOne(SAVED_QUERIES).flush({ saved_queries: [savedQuery()] });
  });

  it('reports a name collision in its own words rather than as a generic failure', () => {
    initialise([]);
    fixture.componentInstance.query.set('project = APP');

    clickButton('Save this query');
    (fixture.componentInstance as unknown as { name: { set(value: string): void } }).name.set(
      'Open work',
    );
    submitForm();

    http.expectOne(SAVED_QUERIES).flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: 'A query of that name already exists.',
        instance: SAVED_QUERIES,
        request_id: 'r-1',
        code: 'SAVED_QUERY_NAME_TAKEN',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('You already have a query with that name.');
  });

  it('carries the version it saw when overwriting a loaded query', () => {
    initialise([savedQuery({ version: 4 })]);

    clickButton('My open work');
    fixture.componentInstance.query.set('project = APP AND priority = HIGH');
    fixture.detectChanges();

    clickButton('Update');

    const request = http.expectOne(`${SAVED_QUERIES}/019f9f00-0000-7000-8000-0000000000aa`);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toMatchObject({
      expected_version: 4,
      query: 'project = APP AND priority = HIGH',
    });
    // Visibility is not in the body at all: an editor must not be able to
    // publish somebody else's query through a content edit.
    expect(request.request.body).not.toHaveProperty('visibility');
    request.flush({ saved_query: savedQuery({ version: 5 }) });

    http.expectOne(SAVED_QUERIES).flush({ saved_queries: [savedQuery({ version: 5 })] });
  });

  /**
   * `viewer_access` describes the caller, not the row, so a grant holder sees a
   * different set of affordances on the very same query.
   */
  it('offers editing but neither archiving nor sharing to a grant holder', () => {
    initialise([
      savedQuery({
        viewer_is_owner: false,
        viewer_access: 'EDIT',
        visibility: 'SHARED',
        owner: { membership_id: 'm-2', display_name: 'Somebody else' },
      }),
    ]);

    clickButton('My open work');

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('Update');
    // Retiring the query and changing who holds it stay with its owner.
    expect(element.textContent).not.toContain('Archive');
    expect(element.textContent).not.toContain('Sharing');
    expect(element.textContent).toContain('Somebody else');
  });

  it('hides saving entirely without the permission to create', () => {
    permissions = new Set();
    initialise([]);

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).not.toContain('Save this query');
  });

  /**
   * A saved query is owned by a tenant membership, so a caller acting purely on
   * system power has nothing to own or be granted. That is not a failure to
   * report at them — the panel does not apply.
   */
  it('disappears rather than reporting an error when the caller has no membership', () => {
    fixture.detectChanges();
    http.expectOne(SAVED_QUERIES).flush(
      {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
        detail: 'Only a tenant member can work with saved queries.',
        instance: SAVED_QUERIES,
        request_id: 'r-1',
        code: 'SAVED_QUERY_MEMBERSHIP_REQUIRED',
      },
      { status: 403, statusText: 'Forbidden' },
    );
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).not.toContain('Saved queries');
    expect(element.textContent).not.toContain('could not be loaded');
  });

  it('keeps archived queries out of the everyday list but does not pretend they are gone', () => {
    initialise([savedQuery(), savedQuery({ id: 'archived-1', name: 'Old work', archived: true })]);

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).not.toContain('Old work');
    expect(element.textContent).toContain('Archived (1)');

    clickButton('Archived (1)');
    expect(fixture.nativeElement.textContent).toContain('Old work');
  });
  it('renames without touching the stored query text', () => {
    initialise([savedQuery()]);
    fixture.componentInstance.query.set('project = SOMETHING ELSE');

    clickButton('Rename');
    (
      fixture.componentInstance as unknown as { renameName: { set(value: string): void } }
    ).renameName.set('  Weekly review  ');
    submitForm();

    const request = http.expectOne(`${SAVED_QUERIES}/019f9f00-0000-7000-8000-0000000000aa`);

    expect(request.request.method).toBe('PATCH');
    // The stored raw text, never what happens to be in the editor: a rename
    // that quietly rewrote the query would be the one change nobody asked for.
    expect(request.request.body).toEqual({
      expected_version: 1,
      name: 'Weekly review',
      description: '',
      query: 'project = app and priority = high',
    });

    request.flush({ saved_query: savedQuery({ name: 'Weekly review', version: 2 }) });
    http.expectOne(SAVED_QUERIES).flush({ saved_queries: [savedQuery({ name: 'Weekly review' })] });
  });

  it('blames the owner, not the editor, when a foreign rename hits a taken name', () => {
    initialise([savedQuery({ viewer_is_owner: false, viewer_access: 'EDIT' })]);

    clickButton('Rename');
    (
      fixture.componentInstance as unknown as { renameName: { set(value: string): void } }
    ).renameName.set('Their name');
    submitForm();

    http.expectOne(`${SAVED_QUERIES}/019f9f00-0000-7000-8000-0000000000aa`).flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: 'The name is taken.',
        instance: SAVED_QUERIES,
        request_id: 'req-9',
        code: 'SAVED_QUERY_NAME_TAKEN',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain(
      'The owner already has a query with that name.',
    );
  });
});
