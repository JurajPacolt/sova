import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  ProjectConfiguration,
  ProjectIssueType,
  WorkflowVersion,
} from '../../../../core/api/api.models';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ProjectConfigurationPageComponent } from './project-configuration-page.component';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const PROJECT_ID = '019f9f00-0000-7000-8000-0000000000b1';
const WORKFLOW_ID = '019f9f00-0000-7000-8000-0000000000e1';
const BASE = `/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}`;
const CONFIGURATION = `${BASE}/configuration`;
const ISSUE_TYPES = `${BASE}/issue-types`;
const DRAFT = `${BASE}/workflows/${WORKFLOW_ID}/draft`;
const PUBLISH = `${BASE}/workflows/${WORKFLOW_ID}/publish`;

const OPEN = '019f9f00-0000-7000-8000-0000000000f1';
const DONE = '019f9f00-0000-7000-8000-0000000000f2';
const TYPE_ID = '019f9f00-0000-7000-8000-0000000000a1';

function version(overrides: Partial<WorkflowVersion> = {}): WorkflowVersion {
  return {
    id: '019f9f00-0000-7000-8000-0000000000d1',
    workflow_id: WORKFLOW_ID,
    version_number: 2,
    state: 'DRAFT',
    version: 3,
    initial_status_id: OPEN,
    statuses: [
      {
        status_id: OPEN,
        code: 'OPEN',
        name: 'Open',
        category: 'TO_DO',
        color_token: null,
        position: 0,
      },
      {
        status_id: DONE,
        code: 'DONE',
        name: 'Done',
        category: 'DONE',
        color_token: null,
        position: 1,
      },
    ],
    transitions: [
      {
        id: '019f9f00-0000-7000-8000-0000000000c9',
        code: 'RESOLVE',
        name: 'Resolve',
        from_status_id: OPEN,
        to_status_id: DONE,
        permission_code: null,
        is_primary: true,
        position: 0,
        rules: [
          {
            id: '019f9f00-0000-7000-8000-0000000000ca',
            type: 'REQUIRED_FIELD',
            key: 'resolution',
            configuration: {},
            position: 0,
          },
        ],
      },
    ],
    ...overrides,
  };
}

function issueType(overrides: Partial<ProjectIssueType> = {}): ProjectIssueType {
  return {
    id: TYPE_ID,
    project_id: PROJECT_ID,
    code: 'TASK',
    name: 'Task',
    description: '',
    hierarchy_level: 0,
    position: 30,
    icon: 'check-square',
    color_token: 'blue',
    status: 'ACTIVE',
    version: 1,
    workflow_id: WORKFLOW_ID,
    ...overrides,
  };
}

function configuration(
  draft: WorkflowVersion | null,
  issueTypes: readonly ProjectIssueType[] = [],
): ProjectConfiguration {
  return {
    revision: 7,
    issue_types: issueTypes,
    statuses: [
      { id: OPEN, code: 'OPEN', name: 'Open', category: 'TO_DO', position: 0, status: 'ACTIVE' },
      { id: DONE, code: 'DONE', name: 'Done', category: 'DONE', position: 1, status: 'ACTIVE' },
    ],
    workflows: [
      {
        id: WORKFLOW_ID,
        name: 'Default',
        description: '',
        status: 'ACTIVE',
        active_version_id: '019f9f00-0000-7000-8000-0000000000d0',
        published_version: version({ state: 'PUBLISHED', version_number: 1 }),
        draft_version: draft,
      },
    ],
  };
}

describe('ProjectConfigurationPageComponent', () => {
  let fixture: ComponentFixture<ProjectConfigurationPageComponent>;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ProjectConfigurationPageComponent],
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

    fixture = TestBed.createComponent(ProjectConfigurationPageComponent);
    fixture.componentRef.setInput('projectId', PROJECT_ID);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    for (const pending of http.match(() => true)) {
      pending.flush({ history: [] });
    }

    http.verify();
  });

  function initialise(
    draft: WorkflowVersion | null,
    issueTypes: readonly ProjectIssueType[] = [],
  ): HTMLElement {
    fixture.detectChanges();
    http.expectOne(CONFIGURATION).flush(configuration(draft, issueTypes));
    http.expectOne(`${CONFIGURATION}/history`).flush({ history: [] });
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function click(element: HTMLElement, label: string): void {
    Array.from(element.querySelectorAll('button'))
      .find((button) => button.textContent?.includes(label))
      ?.click();
  }

  function clickExact(element: HTMLElement, label: string): void {
    Array.from(element.querySelectorAll('button'))
      .find((button) => button.textContent?.trim() === label)
      ?.click();
  }

  function enter(element: HTMLElement, selector: string, value: string): void {
    const input = element.querySelector<HTMLInputElement>(selector);

    expect(input).not.toBeNull();
    input!.value = value;
    input!.dispatchEvent(new Event('input'));
  }

  it('offers to create a draft when there is none', () => {
    const element = initialise(null);

    expect(element.textContent).toContain('There is no draft.');

    click(element, 'Create draft');
    http
      .expectOne(DRAFT)
      .flush({ draft_version: version() }, { status: 201, statusText: 'Created' });
    http.expectOne(CONFIGURATION).flush(configuration(version()));
    http.expectOne(`${CONFIGURATION}/history`).flush({ history: [] });
    fixture.detectChanges();

    expect(element.textContent).toContain('OPEN');
  });

  it('sends the rules back untouched, because the write replaces the whole set', () => {
    const element = initialise(version());

    click(element, 'Save draft');

    const request = http.expectOne(DRAFT);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      expected_version: 3,
      initial_status_code: 'OPEN',
      statuses: [
        { code: 'OPEN', name: 'Open', category: 'TO_DO', position: 0 },
        { code: 'DONE', name: 'Done', category: 'DONE', position: 1 },
      ],
      transitions: [
        {
          code: 'RESOLVE',
          name: 'Resolve',
          from: 'OPEN',
          to: 'DONE',
          permission_code: null,
          is_primary: true,
          position: 0,
          rules: [{ type: 'REQUIRED_FIELD', key: 'resolution', configuration: {}, position: 0 }],
        },
      ],
    });

    request.flush({ draft_version: version({ version: 4 }) });
  });

  it('drops the transitions of a status that is removed', () => {
    const element = initialise(version());

    Array.from(element.querySelectorAll('button'))
      .filter((button) => button.textContent?.includes('Remove'))[1]
      ?.click();
    fixture.detectChanges();

    click(element, 'Save draft');

    const request = http.expectOne(DRAFT);
    const body = request.request.body as { statuses: unknown[]; transitions: unknown[] };

    expect(body.statuses).toHaveLength(1);
    expect(body.transitions).toHaveLength(0);

    request.flush({ draft_version: version({ version: 4 }) });
  });

  it('offers the stored draft after a lost race instead of overwriting it', () => {
    const element = initialise(version());

    click(element, 'Save draft');
    http.expectOne(DRAFT).flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: 'The draft changed in the meantime.',
        instance: DRAFT,
        request_id: 'req-1',
        code: 'WORKFLOW_DRAFT_CONFLICT',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    expect(element.textContent).toContain('Somebody else changed the draft');
    expect(element.textContent).toContain('Load the stored draft');
  });

  it('publishes against the configuration revision and reports a version conflict', () => {
    const element = initialise(version());

    click(element, 'Publish');

    const request = http.expectOne(PUBLISH);

    expect(request.request.body).toEqual({ expected_config_version: 7 });

    request.flush(
      {
        type: 'about:blank',
        title: 'Conflict',
        status: 409,
        detail: 'The configuration changed.',
        instance: PUBLISH,
        request_id: 'req-2',
        code: 'PROJECT_CONFIG_VERSION_CONFLICT',
      },
      { status: 409, statusText: 'Conflict' },
    );
    fixture.detectChanges();

    expect(element.textContent).toContain('The configuration changed in the meantime.');
  });

  it('keeps publishing out of reach while the draft has unsaved changes', () => {
    const element = initialise(version());

    Array.from(element.querySelectorAll('button'))
      .filter((button) => button.textContent?.includes('Remove'))[1]
      ?.click();
    fixture.detectChanges();

    const publish = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Publish',
    );

    expect(publish?.disabled).toBe(true);
    expect(element.textContent).toContain('Save the draft first.');
  });

  it('creates an issue type against the current configuration revision', () => {
    const element = initialise(version());

    clickExact(element, 'Add issue type');
    fixture.detectChanges();
    enter(element, '#issue-type-code', 'INCIDENT');
    enter(element, '#issue-type-name', 'Incident');
    clickExact(element, 'Save issue type');

    const request = http.expectOne(ISSUE_TYPES);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      code: 'INCIDENT',
      name: 'Incident',
      description: '',
      hierarchy_level: 0,
      position: 0,
      icon: '',
      color_token: '',
      workflow_id: WORKFLOW_ID,
      expected_config_version: 7,
    });

    request.flush({ issue_type: issueType({ code: 'INCIDENT', name: 'Incident' }) });
    http.expectOne(CONFIGURATION).flush({ ...configuration(version()), revision: 8 });
    http.expectOne(`${CONFIGURATION}/history`).flush({ history: [] });
  });

  it('archives an issue type using both optimistic versions', () => {
    const task = issueType({ version: 4 });
    const element = initialise(version(), [task]);

    clickExact(element, 'Archive');
    fixture.detectChanges();

    expect(element.textContent).toContain('Existing issues keep their type.');
    clickExact(element, 'Archive issue type');

    const request = http.expectOne(`${ISSUE_TYPES}/${TYPE_ID}/archive`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      expected_config_version: 7,
      expected_type_version: 4,
    });

    request.flush({ issue_type: issueType({ status: 'ARCHIVED', version: 5 }) });
    http.expectOne(CONFIGURATION).flush({
      ...configuration(version(), [issueType({ status: 'ARCHIVED', version: 5 })]),
      revision: 8,
    });
    http.expectOne(`${CONFIGURATION}/history`).flush({ history: [] });
  });

  it('renders the hierarchy in the same direction as the domain model', () => {
    const element = initialise(version(), [
      issueType({ id: `${TYPE_ID.slice(0, -1)}2`, code: 'EPIC', hierarchy_level: 1 }),
      issueType({ id: `${TYPE_ID.slice(0, -1)}3`, code: 'SUBTASK', hierarchy_level: -1 }),
    ]);

    expect(element.textContent).toContain('Container');
    expect(element.textContent).toContain('Sub-task');
  });
});
