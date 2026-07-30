import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { DashboardWidget, SavedQuery, WidgetTypeDefinition } from '../../../../core/api/api.models';
import { WidgetSettingsComponent } from './widget-settings.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-000000000002';
const WIDGET_ID = '019f9f00-0000-7000-8000-000000000003';
const QUERY_ID = '019f9f00-0000-7000-8000-0000000000aa';
const OTHER_QUERY_ID = '019f9f00-0000-7000-8000-0000000000bb';
const WIDGET = `/api/v1/tenants/${TENANT_ID}/dashboards/${DASHBOARD_ID}/widgets/${WIDGET_ID}`;

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

function widget(overrides: Partial<DashboardWidget> = {}): DashboardWidget {
  return {
    id: WIDGET_ID,
    dashboard_id: DASHBOARD_ID,
    type_key: 'issue_count',
    available: true,
    schema_version: 1,
    title: 'Reported by me',
    saved_query_id: QUERY_ID,
    source_name: 'Assigned to me',
    source_reachable: true,
    configuration: { description: 'Still open', tone: 'INFO', show_link: true },
    x: 0,
    y: 0,
    width: 4,
    height: 2,
    version: 7,
    created_at: '2026-07-29T10:00:00+00:00',
    updated_at: '2026-07-29T10:00:00+00:00',
    ...overrides,
  };
}

describe('WidgetSettingsComponent', () => {
  let fixture: ComponentFixture<WidgetSettingsComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WidgetSettingsComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    fixture = TestBed.createComponent(WidgetSettingsComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('tenantId', TENANT_ID);
    fixture.componentRef.setInput('dashboardId', DASHBOARD_ID);
  });

  afterEach(() => {
    http.verify();
  });

  /** `ngModel` wires its controls asynchronously, so the form settles first. */
  async function render(
    instance: DashboardWidget,
    types: readonly WidgetTypeDefinition[] = [type()],
    queries: readonly SavedQuery[] = [query()],
  ): Promise<HTMLElement> {
    fixture.componentRef.setInput('widget', instance);
    fixture.componentRef.setInput('types', types);
    fixture.componentRef.setInput('queries', queries);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture.nativeElement;
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function choose(id: string, value: string): Promise<void> {
    const select: HTMLSelectElement = fixture.nativeElement.querySelector(`#${id}`);
    select.value = value;
    select.dispatchEvent(new Event('change'));
    await settle();
  }

  async function fill(id: string, value: string): Promise<void> {
    const field: HTMLInputElement = fixture.nativeElement.querySelector(`#${id}`);
    field.value = value;
    field.dispatchEvent(new Event('input'));
    await settle();
  }

  async function toggle(id: string): Promise<void> {
    const box: HTMLInputElement = fixture.nativeElement.querySelector(`#${id}`);
    box.click();
    await settle();
  }

  function submit(): void {
    const form: HTMLFormElement = fixture.nativeElement.querySelector('form');
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  function disabled(): boolean {
    const element: HTMLElement = fixture.nativeElement;

    return element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled === true;
  }

  /**
   * `PATCH` replaces the whole configuration, so a form that sent only its own
   * fields would quietly reset every setting it does not show — including one
   * a later version added.
   */
  it('carries settings it does not show through an edit instead of dropping them', async () => {
    await render(widget({ configuration: { tone: 'INFO', show_link: true, refresh_seconds: 60 } }));

    await fill('widget-name', 'Still open');
    submit();

    const request = http.expectOne(WIDGET);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body.title).toBe('Still open');
    expect(request.request.body.configuration.refresh_seconds).toBe(60);
    request.flush({ widget: widget() });
  });

  /** The same optimistic lock as the layout: the version the form was filled against. */
  it('sends the version the form was shown, so a parallel edit is reported', async () => {
    await render(widget({ version: 7 }));

    await fill('widget-name', 'New name');
    submit();

    const request = http.expectOne(WIDGET);
    expect(request.request.body.expected_version).toBe(7);
    request.flush({ widget: widget({ version: 8 }) });
  });

  it('says the widget was changed elsewhere rather than reporting a generic failure', async () => {
    const element = await render(widget());

    await fill('widget-name', 'New name');
    submit();

    http.expectOne(WIDGET).flush(
      {
        code: 'WIDGET_VERSION_CONFLICT',
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

    expect(element.textContent).toContain('Somebody else changed this widget');
  });

  /**
   * The type is fixed for the life of the instance: changing it would
   * reinterpret a configuration written against a different schema.
   */
  it('states the type instead of offering to change it', async () => {
    const element = await render(widget());

    expect(element.querySelector('#widget-type')).toBeNull();
    expect(element.textContent).toContain('The type cannot be changed');
  });

  /**
   * The widget's own source may have been archived or unshared since. Dropping
   * it from the list would make merely opening this form swap the source for
   * whatever happens to be first.
   */
  it('keeps the current source in the list even when it is no longer offered', async () => {
    const element = await render(
      widget({ saved_query_id: OTHER_QUERY_ID, source_name: null, source_reachable: false }),
      [type()],
      [query()],
    );

    const options = [...element.querySelectorAll<HTMLOptionElement>('#widget-source option')];
    expect(options.map((option) => option.value)).toEqual([OTHER_QUERY_ID, QUERY_ID]);
    expect(element.textContent).toContain('no longer available');
  });

  it('refuses a matrix with the same field on both axes', async () => {
    const element = await render(
      widget({
        type_key: 'issue_matrix',
        configuration: { rows: 'status', columns: 'priority' },
      }),
      [type({ type_key: 'issue_matrix', dimensions: ['status', 'priority'] })],
    );

    expect(disabled()).toBe(false);

    await choose('widget-matrix-columns', 'status');

    expect(element.textContent).toContain('The two axes must use different fields.');
    expect(disabled()).toBe(true);
  });

  /** The server's rule, said while the choice is being made rather than after. */
  it('will not save a list narrowed below three columns', async () => {
    const element = await render(
      widget({
        type_key: 'issue_list',
        configuration: { columns: ['title', 'status', 'priority'] },
      }),
      [type({ type_key: 'issue_list' })],
    );

    expect(disabled()).toBe(false);

    await toggle('widget-column-priority');

    expect(element.textContent).toContain('Choose between 3 and 10 columns.');
    expect(disabled()).toBe(true);
  });

  /**
   * A column this build cannot draw is not offered — and not thrown away
   * either, because the form did not show it to be removed.
   */
  it('keeps a stored column it cannot draw', async () => {
    await render(
      widget({
        type_key: 'issue_list',
        configuration: { columns: ['title', 'status', 'assignee'], limit: 10 },
      }),
      [type({ type_key: 'issue_list' })],
    );

    await toggle('widget-column-priority');
    submit();

    const request = http.expectOne(WIDGET);
    expect(request.request.body.configuration.columns).toContain('assignee');
    expect(request.request.body.configuration.columns).toContain('priority');
    request.flush({ widget: widget() });
  });

  /**
   * An emptied number box means "use the server's default": the key leaves the
   * payload rather than travelling as something the schema would reject.
   */
  it('drops an emptied number instead of sending an empty one', async () => {
    await render(
      widget({
        type_key: 'issue_list',
        configuration: { columns: ['title', 'status', 'priority'], limit: 25 },
      }),
      [type({ type_key: 'issue_list' })],
    );

    await fill('widget-limit', '');
    submit();

    const request = http.expectOne(WIDGET);
    expect(JSON.stringify(request.request.body)).not.toContain('limit');
    request.flush({ widget: widget() });
  });

  it('refuses a row count outside the range the server accepts', async () => {
    const element = await render(
      widget({
        type_key: 'issue_list',
        configuration: { columns: ['title', 'status', 'priority'], limit: 10 },
      }),
      [type({ type_key: 'issue_list' })],
    );

    await fill('widget-limit', '400');

    expect(element.textContent).toContain('Choose a number between 5 and 50.');
    expect(disabled()).toBe(true);
  });

  /**
   * The ring is drawn now that the design system has a categorical scale, so
   * every stored form is offered — nothing here promises a shape that will not
   * appear, which is what used to keep `DONUT` hidden.
   */
  it('offers every breakdown form, the ring included', async () => {
    const element = await render(
      widget({
        type_key: 'issue_breakdown',
        configuration: { group_by: 'status', visualization: 'BAR' },
      }),
      [type({ type_key: 'issue_breakdown', dimensions: ['status', 'priority'] })],
    );

    const values = [
      ...element.querySelectorAll<HTMLOptionElement>('#widget-visualization option'),
    ].map((option) => option.value);

    expect(values).toEqual(['BAR', 'TABLE', 'DONUT']);
  });

  /** The registry ships field keys; the wording is the catalog's. */
  it('names a grouping field through the catalog rather than as its raw key', async () => {
    const element = await render(
      widget({ type_key: 'issue_breakdown', configuration: { group_by: 'status' } }),
      [type({ type_key: 'issue_breakdown', dimensions: ['statusCategory'] })],
    );

    expect(element.querySelector('#widget-group-by')?.textContent).toContain('Status category');
  });
});
