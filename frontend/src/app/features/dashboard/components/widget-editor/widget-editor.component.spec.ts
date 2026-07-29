import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { SavedQuery, WidgetTypeDefinition } from '../../../../core/api/api.models';
import { WidgetEditorComponent } from './widget-editor.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const QUERY_ID = '019f9f00-0000-7000-8000-0000000000aa';
const WIDGETS = `/api/v1/tenants/${TENANT_ID}/dashboards/${DASHBOARD_ID}/widgets`;

function type(overrides: Partial<WidgetTypeDefinition> = {}): WidgetTypeDefinition {
  return {
    type_key: 'issue_count',
    schema_version: 1,
    label_key: 'widget.type.count.label',
    description_key: 'widget.type.count.description',
    min_width: 2,
    min_height: 1,
    default_width: 3,
    default_height: 2,
    max_width: 6,
    max_height: 3,
    dimensions: [],
    ...overrides,
  };
}

function query(overrides: Partial<SavedQuery> = {}): SavedQuery {
  return {
    id: QUERY_ID,
    name: 'Assigned to me',
    description: '',
    raw_query: 'assignee = currentUser()',
    canonical_query: 'assignee = currentUser()',
    language_version: 1,
    default_columns: [],
    visibility: 'PRIVATE',
    version: 1,
    archived: false,
    owner: { membership_id: 'm-1', display_name: 'Owner' },
    viewer_access: 'EDIT',
    viewer_is_owner: true,
    favourite: false,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

describe('WidgetEditorComponent', () => {
  let fixture: ComponentFixture<WidgetEditorComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WidgetEditorComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    fixture = TestBed.createComponent(WidgetEditorComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('tenantId', TENANT_ID);
    fixture.componentRef.setInput('dashboardId', DASHBOARD_ID);
  });

  afterEach(() => {
    http.verify();
  });

  /**
   * `ngModel` finishes wiring a control asynchronously, so the form is only
   * driveable from the DOM once the fixture has settled.
   */
  async function render(
    types: readonly WidgetTypeDefinition[],
    queries: readonly SavedQuery[],
  ): Promise<HTMLElement> {
    fixture.componentRef.setInput('types', types);
    fixture.componentRef.setInput('queries', queries);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  /**
   * A select that appears only after the type is chosen registers its own
   * control asynchronously, so each change settles before the next one.
   */
  async function choose(id: string, value: string): Promise<void> {
    const select: HTMLSelectElement = fixture.nativeElement.querySelector(`#${id}`);
    select.value = value;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function submit(): void {
    const form: HTMLFormElement = fixture.nativeElement.querySelector('form');
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  /** The registry ships keys, not wording; the catalogs own the text. */
  it('renders a widget type through its localisation key', async () => {
    const element = await render([type()], [query()]);

    expect(element.querySelector('#widget-type')?.textContent).toContain('Count');
  });

  it('cannot be submitted before a type and a source are chosen', async () => {
    const element = await render([type()], [query()]);

    const add = element.querySelector<HTMLButtonElement>('button[type="submit"]');
    expect(add?.disabled).toBe(true);

    await choose('widget-type', 'issue_count');
    await choose('widget-query', QUERY_ID);
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(false);
  });

  it('sends only the pointer and lets the server fill in its own defaults', async () => {
    await render([type()], [query()]);

    await choose('widget-type', 'issue_count');
    await choose('widget-query', QUERY_ID);
    submit();

    const request = http.expectOne(WIDGETS);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      saved_query_id: QUERY_ID,
      type_key: 'issue_count',
      title: '',
      // Nothing else: a form repeating the whole schema would be a second copy
      // of it, free to drift from the real one.
      configuration: {},
    });
    request.flush({ widget: { id: 'w-1' } });
  });

  it('asks a breakdown what to group by, because the server cannot guess', async () => {
    const element = await render(
      [type({ type_key: 'issue_breakdown', dimensions: ['status', 'priority'] })],
      [query()],
    );

    await choose('widget-type', 'issue_breakdown');
    await choose('widget-query', QUERY_ID);
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);

    await choose('widget-group', 'status');
    submit();

    const request = http.expectOne(WIDGETS);
    expect(request.request.body.configuration).toEqual({ group_by: 'status' });
    request.flush({ widget: { id: 'w-2' } });
  });

  /**
   * The same field on both axes draws a diagonal and nothing else, so the
   * server refuses it — there is no reason to let anybody send it.
   */
  it('will not send a matrix with the same field on both axes', async () => {
    const element = await render(
      [type({ type_key: 'issue_matrix', dimensions: ['status', 'priority'] })],
      [query()],
    );

    await choose('widget-type', 'issue_matrix');
    await choose('widget-query', QUERY_ID);
    await choose('widget-group', 'status');
    await choose('widget-columns', 'status');

    expect(element.textContent).toContain('The two axes must use different fields.');
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);

    await choose('widget-columns', 'priority');
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(false);
  });

  it('clears the axes when the type changes, so no field survives into a type that lacks it', async () => {
    await render(
      [
        type({ type_key: 'issue_breakdown', dimensions: ['status'] }),
        type({ type_key: 'issue_count', dimensions: [] }),
      ],
      [query()],
    );

    await choose('widget-type', 'issue_breakdown');
    await choose('widget-query', QUERY_ID);
    await choose('widget-group', 'status');
    await choose('widget-type', 'issue_count');
    submit();

    const request = http.expectOne(WIDGETS);
    expect(request.request.body.configuration).toEqual({});
    request.flush({ widget: { id: 'w-3' } });
  });
});
