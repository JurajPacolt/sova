import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { vi } from 'vitest';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { QueryEditorComponent } from './query-editor.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';

const METADATA = {
  fields: [
    {
      name: 'project',
      type: 'ProjectCode',
      operators: ['=', '!='],
      supports_set: true,
      supports_empty: false,
      sortable: true,
    },
  ],
  limits: {
    max_query_bytes: 8192,
    max_ast_nodes: 100,
    max_paren_depth: 10,
    max_in_values: 100,
    max_sort_fields: 3,
    default_page_size: 50,
    max_page_size: 100,
    statement_timeout_ms: 3000,
  },
};

describe('QueryEditorComponent', () => {
  let fixture: ComponentFixture<QueryEditorComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [QueryEditorComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: TenantStore,
          useValue: { activeTenantId: () => TENANT_ID },
        },
      ],
    });

    fixture = TestBed.createComponent(QueryEditorComponent);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  function clickButton(element: HTMLElement, label: string): void {
    const target = [...element.querySelectorAll('button')].find((button) =>
      button.textContent?.includes(label),
    );

    if (target === undefined) {
      throw new Error(`No button labelled "${label}".`);
    }

    target.click();
  }

  function initialise(): void {
    fixture.detectChanges();
    // The reference is loaded once when the editor appears.
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/metadata`).flush(METADATA);
  }

  it('loads the field reference and the active limits once', () => {
    initialise();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).not.toContain('project');

    // Found by its label rather than by position, so adding a button elsewhere
    // does not quietly point this test at something else.
    clickButton(element, 'Show available fields');
    fixture.detectChanges();

    expect(element.textContent).toContain('project');
    // The limits shown are the ones the server reports, not hard-coded here.
    expect(element.textContent).toContain('8192');
  });

  it('asks the server to validate instead of judging the query itself', () => {
    vi.useFakeTimers();
    initialise();

    (fixture.componentInstance as unknown as { changed(value: string): void }).changed('nope = 1');

    // Nothing goes out until typing settles.
    http.expectNone(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`);
    vi.advanceTimersByTime(400);

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ query: 'nope = 1' });
    request.flush({
      valid: false,
      canonical_query: null,
      errors: [
        {
          code: 'QUERY_FIELD_UNKNOWN',
          message_key: 'query.errors.fieldUnknown',
          start: 0,
          end: 4,
          arguments: { field: 'nope' },
        },
      ],
    });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    // The message comes from the catalog via the server's message_key, and the
    // range is cut out of the query the user typed.
    expect(element.textContent).toContain('That field does not exist.');
    expect(element.textContent).toContain('nope');
    vi.useRealTimers();
  });

  it('treats an empty query as legal and does not ask about it', () => {
    vi.useFakeTimers();
    initialise();

    (fixture.componentInstance as unknown as { changed(value: string): void }).changed('   ');
    vi.advanceTimersByTime(400);

    // An empty query means "everything I may see"; there is nothing to check.
    http.expectNone(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`);
    vi.useRealTimers();
  });
  it('draws a conjunction in the basic mode from the server projection', () => {
    vi.useFakeTimers();
    initialise();

    (fixture.componentInstance as unknown as { changed(value: string): void }).changed(
      'project = SOVA AND priority = HIGH',
    );
    vi.advanceTimersByTime(400);

    http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`).flush({
      valid: true,
      canonical_query: 'project = SOVA AND priority = HIGH',
      errors: [],
      basic_form: {
        representable: true,
        conditions: [
          { field: 'project', operator: '=', values: ['SOVA'] },
          { field: 'priority', operator: '=', values: ['HIGH'] },
        ],
        sort: [],
      },
    });

    (fixture.componentInstance as unknown as { toggleMode(): void }).toggleMode();
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('project = SOVA');
    expect(element.textContent).toContain('priority = HIGH');
    vi.useRealTimers();
  });

  /**
   * The specification is explicit: a query the basic editor cannot express is
   * shown read-only with a way back to the text mode, never silently
   * simplified.
   */
  it('shows a non-representable query read-only instead of simplifying it', () => {
    vi.useFakeTimers();
    initialise();

    (fixture.componentInstance as unknown as { changed(value: string): void }).changed(
      'project = SOVA OR type = BUG',
    );
    vi.advanceTimersByTime(400);

    http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`).flush({
      valid: true,
      canonical_query: 'project = SOVA OR type = BUG',
      errors: [],
      basic_form: { representable: false, conditions: [], sort: [] },
    });

    (fixture.componentInstance as unknown as { toggleMode(): void }).toggleMode();
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('Switch to SovaQL');
    // The original text is still on screen, untouched.
    expect(element.textContent).toContain('project = SOVA OR type = BUG');
    // And no editable condition rows were invented for it.
    expect(element.textContent).not.toContain('Remove condition');
    vi.useRealTimers();
  });

  it('rebuilds the text from the server pieces when a condition is removed', () => {
    vi.useFakeTimers();
    initialise();

    (fixture.componentInstance as unknown as { changed(value: string): void }).changed(
      'project = SOVA AND priority = HIGH ORDER BY updated DESC',
    );
    vi.advanceTimersByTime(400);

    http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`).flush({
      valid: true,
      canonical_query: 'project = SOVA AND priority = HIGH ORDER BY updated DESC',
      errors: [],
      basic_form: {
        representable: true,
        conditions: [
          { field: 'project', operator: '=', values: ['SOVA'] },
          { field: 'priority', operator: '=', values: ['HIGH'] },
        ],
        sort: [{ field: 'updated', direction: 'DESC', nulls: null }],
      },
    });

    (
      fixture.componentInstance as unknown as { removeCondition(index: number): void }
    ).removeCondition(0);

    // The sort is kept and the remaining condition is re-emitted verbatim; the
    // editor never reasons about what the result means.
    expect(fixture.componentInstance.query()).toBe('priority = HIGH ORDER BY updated DESC');

    vi.advanceTimersByTime(400);
    const recheck = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issue-query/validate`);
    expect(recheck.request.body).toEqual({ query: 'priority = HIGH ORDER BY updated DESC' });
    recheck.flush({
      valid: true,
      canonical_query: 'priority = HIGH ORDER BY updated DESC',
      errors: [],
      basic_form: { representable: true, conditions: [], sort: [] },
    });
    vi.useRealTimers();
  });
});
