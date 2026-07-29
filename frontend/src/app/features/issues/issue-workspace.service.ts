import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  CreateIssueLinkRequest,
  CreateIssueRequest,
  CreateSavedQueryRequest,
  ExecuteIssueTransitionRequest,
  IssueAttachmentList,
  IssueAttachmentResponse,
  IssueCommentList,
  IssueCommentResponse,
  IssueHistoryList,
  IssueLinkList,
  IssueQueryMetadata,
  IssueQueryValidationResponse,
  ProjectConfiguration,
  ProjectListItem,
  IssueResponse,
  IssueSearchRequest,
  IssueSearchResponse,
  IssueTransitionList,
  IssueWatcherList,
  IssueWatchState,
  ReplaceSavedQueryGrantsRequest,
  SavedQuery,
  SavedQueryGrant,
  TenantMembership,
  UpdateSavedQueryRequest,
  Workgroup,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

/**
 * The issue feature's single door to the API.
 *
 * Pages depend on this rather than on the HTTP client, so the tenant identifier
 * is applied in one place and a screen can never accidentally address another
 * tenant. Guards and this service are user-interface affordances only — the
 * backend authorises every call again.
 */
@Injectable({ providedIn: 'root' })
export class IssueWorkspaceService {
  private readonly api = inject(SovaApiClient);

  search(tenantId: string, request: IssueSearchRequest): Observable<IssueSearchResponse> {
    return this.api.searchIssues(tenantId, request);
  }

  /**
   * Validates without running. The response carries the stable error codes and
   * their exact ranges in the text, plus a `message_key` the catalogs resolve —
   * that is the whole point of the endpoint existing separately from search.
   */
  validate(tenantId: string, query: string): Observable<IssueQueryValidationResponse> {
    return this.api.validateIssueQuery(tenantId, query);
  }

  /** The fields this deployment supports and the limits currently in force. */
  queryMetadata(tenantId: string): Observable<IssueQueryMetadata> {
    return this.api.getIssueQueryMetadata(tenantId);
  }

  /**
   * Issue keys are what people read and share, but the API addresses issues by
   * identifier. SovaQL already resolves one to the other, so the key stays in
   * the URL and the lookup costs a single search.
   */
  findByKey(tenantId: string, issueKey: string): Observable<IssueSearchResponse> {
    return this.api.searchIssues(tenantId, {
      query: `key = ${issueKey.trim().toUpperCase()}`,
      page_size: 1,
    });
  }

  /**
   * Projects the caller may see. Read through this service rather than through
   * the projects feature: a feature must not reach into another feature's
   * internals, and only the shared API client sits below both.
   */
  projects(tenantId: string): Observable<readonly ProjectListItem[]> {
    return this.api.listProjects(tenantId).pipe(map((response) => response.projects));
  }

  /**
   * The issue types a project offers. The client never picks the workflow or
   * the initial status — the project configuration decides both.
   */
  configuration(tenantId: string, projectId: string): Observable<ProjectConfiguration> {
    return this.api.getProjectConfiguration(tenantId, projectId);
  }

  create(
    tenantId: string,
    projectId: string,
    request: CreateIssueRequest,
  ): Observable<IssueResponse> {
    return this.api.createIssue(tenantId, projectId, request);
  }

  get(tenantId: string, issueId: string): Observable<IssueResponse> {
    return this.api.getIssue(tenantId, issueId);
  }

  transitions(tenantId: string, issueId: string): Observable<IssueTransitionList> {
    return this.api.listIssueTransitions(tenantId, issueId);
  }

  executeTransition(
    tenantId: string,
    issueId: string,
    transitionId: string,
    request: ExecuteIssueTransitionRequest,
  ): Observable<IssueResponse> {
    return this.api.executeIssueTransition(tenantId, issueId, transitionId, request);
  }

  comments(tenantId: string, issueId: string): Observable<IssueCommentList> {
    return this.api.listIssueComments(tenantId, issueId);
  }

  addComment(tenantId: string, issueId: string, body: string): Observable<IssueCommentResponse> {
    return this.api.createIssueComment(tenantId, issueId, { body });
  }

  removeComment(tenantId: string, issueId: string, commentId: string): Observable<void> {
    return this.api.deleteIssueComment(tenantId, issueId, commentId);
  }

  attachments(tenantId: string, issueId: string): Observable<IssueAttachmentList> {
    return this.api.listIssueAttachments(tenantId, issueId);
  }

  uploadAttachment(
    tenantId: string,
    issueId: string,
    file: File,
  ): Observable<IssueAttachmentResponse> {
    return this.api.uploadIssueAttachment(tenantId, issueId, file);
  }

  downloadAttachment(tenantId: string, issueId: string, attachmentId: string): Observable<Blob> {
    return this.api.downloadIssueAttachment(tenantId, issueId, attachmentId);
  }

  removeAttachment(tenantId: string, issueId: string, attachmentId: string): Observable<void> {
    return this.api.deleteIssueAttachment(tenantId, issueId, attachmentId);
  }

  links(tenantId: string, issueId: string): Observable<IssueLinkList> {
    return this.api.listIssueLinks(tenantId, issueId);
  }

  addLink(
    tenantId: string,
    issueId: string,
    request: CreateIssueLinkRequest,
  ): Observable<IssueLinkList> {
    return this.api.createIssueLink(tenantId, issueId, request);
  }

  removeLink(tenantId: string, issueId: string, linkId: string): Observable<void> {
    return this.api.deleteIssueLink(tenantId, issueId, linkId);
  }

  history(tenantId: string, issueId: string): Observable<IssueHistoryList> {
    return this.api.listIssueHistory(tenantId, issueId);
  }

  watchers(tenantId: string, issueId: string): Observable<IssueWatcherList> {
    return this.api.listIssueWatchers(tenantId, issueId);
  }

  setWatching(tenantId: string, issueId: string, watching: boolean): Observable<IssueWatchState> {
    return watching
      ? this.api.watchIssue(tenantId, issueId)
      : this.api.unwatchIssue(tenantId, issueId);
  }

  /**
   * The queries this caller may reach. `viewer_access` and `favourite` describe
   * the caller, not the row, so the list is never shared between identities.
   */
  savedQueries(tenantId: string): Observable<readonly SavedQuery[]> {
    return this.api.listSavedQueries(tenantId).pipe(map((response) => response.saved_queries));
  }

  /**
   * Only the raw text is sent. The canonical form is the server's, because two
   * spellings of one query must not produce two different cursor bindings.
   */
  saveQuery(tenantId: string, request: CreateSavedQueryRequest): Observable<SavedQuery> {
    return this.api
      .createSavedQuery(tenantId, request)
      .pipe(map((response) => response.saved_query));
  }

  updateSavedQuery(
    tenantId: string,
    savedQueryId: string,
    request: UpdateSavedQueryRequest,
  ): Observable<SavedQuery> {
    return this.api
      .updateSavedQuery(tenantId, savedQueryId, request)
      .pipe(map((response) => response.saved_query));
  }

  archiveSavedQuery(
    tenantId: string,
    savedQueryId: string,
    expectedVersion: number,
  ): Observable<SavedQuery> {
    return this.api
      .archiveSavedQuery(tenantId, savedQueryId, { expected_version: expectedVersion })
      .pipe(map((response) => response.saved_query));
  }

  setSavedQueryFavourite(
    tenantId: string,
    savedQueryId: string,
    favourite: boolean,
  ): Observable<boolean> {
    return (
      favourite
        ? this.api.addSavedQueryFavourite(tenantId, savedQueryId)
        : this.api.removeSavedQueryFavourite(tenantId, savedQueryId)
    ).pipe(map((state) => state.favourite));
  }

  savedQueryGrants(tenantId: string, savedQueryId: string): Observable<readonly SavedQueryGrant[]> {
    return this.api
      .listSavedQueryGrants(tenantId, savedQueryId)
      .pipe(map((response) => response.grants));
  }

  /**
   * Sends the complete set. A principal left out really loses access, which a
   * partial update could not guarantee.
   */
  replaceSavedQueryGrants(
    tenantId: string,
    savedQueryId: string,
    request: ReplaceSavedQueryGrantsRequest,
  ): Observable<readonly SavedQueryGrant[]> {
    return this.api
      .replaceSavedQueryGrants(tenantId, savedQueryId, request)
      .pipe(map((response) => response.grants));
  }

  /**
   * Candidates for a grant. Read through this service for the same reason as
   * `projects()`: only the shared API client sits below both features.
   */
  members(tenantId: string): Observable<readonly TenantMembership[]> {
    return this.api.listTenantMemberships(tenantId).pipe(map((response) => response.memberships));
  }

  workgroups(tenantId: string): Observable<readonly Workgroup[]> {
    return this.api.listWorkgroups(tenantId).pipe(map((response) => response.workgroups));
  }
}
