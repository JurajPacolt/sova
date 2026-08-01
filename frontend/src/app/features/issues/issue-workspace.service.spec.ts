import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { IssueWorkspaceService } from './issue-workspace.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const ISSUE_ID = '019f9f00-0000-7000-8000-000000000002';
const TRANSITION_ID = '019f9f00-0000-7000-8000-000000000003';
const COMMENT_ID = '019f9f00-0000-7000-8000-000000000004';
const ATTACHMENT_ID = '019f9f00-0000-7000-8000-000000000005';
const LINK_ID = '019f9f00-0000-7000-8000-000000000006';
const TARGET_ID = '019f9f00-0000-7000-8000-000000000007';
const PROJECT_ID = '019f9f00-0000-7000-8000-000000000008';
const TYPE_ID = '019f9f00-0000-7000-8000-000000000009';

describe('IssueWorkspaceService', () => {
  let service: IssueWorkspaceService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(IssueWorkspaceService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  it('searches through POST so the query never reaches a URL or a proxy log', () => {
    service.search(TENANT_ID, { query: 'type = BUG' }).subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/search`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ query: 'type = BUG' });
    request.flush({ issues: [], canonical_query: 'type = BUG', page_size: 50, next_cursor: null });
  });

  it('resolves a readable issue key into an identifier with SovaQL', () => {
    service.findByKey(TENANT_ID, ' sova-1 ').subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/search`);
    // The key is normalised, and only one row is asked for.
    expect(request.request.body).toEqual({ query: 'key = SOVA-1', page_size: 1 });
    request.flush({ issues: [], canonical_query: '', page_size: 1, next_cursor: null });
  });

  it('sends the expected version with a transition', () => {
    service
      .executeTransition(TENANT_ID, ISSUE_ID, TRANSITION_ID, {
        expected_issue_version: 3,
        fields: { resolution: 'FIXED' },
      })
      .subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/transitions/${TRANSITION_ID}`,
    );
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      expected_issue_version: 3,
      fields: { resolution: 'FIXED' },
    });
    request.flush({ issue: {} });
  });

  it('posts a comment body as CommonMark source', () => {
    service.addComment(TENANT_ID, ISSUE_ID, 'A **note**.').subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/comments`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ body: 'A **note**.' });
    request.flush({ comment: {} });
  });

  it('removes a comment through the nested route', () => {
    service.removeComment(TENANT_ID, ISSUE_ID, COMMENT_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/comments/${COMMENT_ID}`,
    );
    expect(request.request.method).toBe('DELETE');
    request.flush(null);
  });

  it('uses the verb that matches the intent when watching', () => {
    service.setWatching(TENANT_ID, ISSUE_ID, true).subscribe();
    const watch = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/watchers/me`);
    expect(watch.request.method).toBe('PUT');
    watch.flush({ watching: true });

    service.setWatching(TENANT_ID, ISSUE_ID, false).subscribe();
    const unwatch = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/watchers/me`);
    expect(unwatch.request.method).toBe('DELETE');
    unwatch.flush({ watching: false });
  });

  it('uploads a file as multipart without overriding the content type', () => {
    const file = new File(['payload'], 'diagram.png', { type: 'image/png' });
    service.uploadAttachment(TENANT_ID, ISSUE_ID, file).subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/attachments`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    // The browser must set the multipart boundary itself.
    expect(request.request.headers.has('Content-Type')).toBe(false);
    request.flush({ attachment: {} });
  });

  it('downloads an attachment through the authenticated client as a blob', () => {
    service.downloadAttachment(TENANT_ID, ISSUE_ID, ATTACHMENT_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/attachments/${ATTACHMENT_ID}`,
    );
    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['payload']));
  });

  it('links issues by identifier and relation', () => {
    service
      .addLink(TENANT_ID, ISSUE_ID, { target_issue_id: TARGET_ID, link_type: 'BLOCKS' })
      .subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/links`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      target_issue_id: TARGET_ID,
      link_type: 'BLOCKS',
    });
    request.flush({ links: [] });
  });

  it('removes a link through the nested route', () => {
    service.removeLink(TENANT_ID, ISSUE_ID, LINK_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/links/${LINK_ID}`,
    );
    expect(request.request.method).toBe('DELETE');
    request.flush(null);
  });
  it('creates an issue under its project without choosing a workflow or status', () => {
    service
      .create(TENANT_ID, PROJECT_ID, {
        issue_type_id: TYPE_ID,
        title: 'Login times out',
        description: '',
        priority: 'HIGH',
      })
      .subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}/issues`);
    expect(request.request.method).toBe('POST');
    // The initial status and the workflow version come from the project
    // configuration; the client must not send either.
    expect(request.request.body).toEqual({
      issue_type_id: TYPE_ID,
      title: 'Login times out',
      description: '',
      priority: 'HIGH',
    });
    request.flush({ issue: {} });
  });

  it('reads the issue types from the project configuration', () => {
    service.configuration(TENANT_ID, PROJECT_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}/configuration`,
    );
    expect(request.request.method).toBe('GET');
    request.flush({ revision: 1, issue_types: [], statuses: [], workflows: [] });
  });
  it('reads the project list through its own service, not another feature', () => {
    let codes: readonly string[] = [];
    service.projects(TENANT_ID).subscribe((projects) => {
      codes = projects.map((project) => project.code);
    });

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/projects`);
    expect(request.request.method).toBe('GET');
    request.flush({ projects: [{ code: 'APP' }] });

    expect(codes).toEqual(['APP']);
  });
  it('scopes the board query to one project, since columns are its statuses', () => {
    service
      .search(TENANT_ID, {
        query: 'project = APP ORDER BY priority DESC, updated DESC',
        page_size: 100,
      })
      .subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/search`);
    expect(request.request.body).toEqual({
      query: 'project = APP ORDER BY priority DESC, updated DESC',
      page_size: 100,
    });
    request.flush({ issues: [], canonical_query: '', page_size: 100, next_cursor: null });
  });

  it('reads the available moves of one issue rather than of every card', () => {
    service.transitions(TENANT_ID, ISSUE_ID).subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/issues/${ISSUE_ID}/transitions`);
    expect(request.request.method).toBe('GET');
    request.flush({ issue_version: 4, transitions: [] });
  });
});
