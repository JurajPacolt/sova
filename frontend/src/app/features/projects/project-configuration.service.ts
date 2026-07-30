import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  ArchiveProjectIssueTypeRequest,
  ConfigurationHistoryEntry,
  CreateProjectIssueTypeRequest,
  ProjectConfiguration,
  ProjectIssueType,
  PublishWorkflowRequest,
  UpdateProjectIssueTypeRequest,
  UpdateWorkflowDraftRequest,
  WorkflowImpact,
  WorkflowValidationResponse,
  WorkflowVersion,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

/**
 * The authoring lifecycle of `WORKFLOW-A-TYPY-ULOH.md` §8, seen from the client.
 *
 * Every method unwraps the single-key envelope the API uses, so the screen deals
 * in versions and reports rather than in response shapes.
 */
@Injectable({ providedIn: 'root' })
export class ProjectConfigurationService {
  private readonly api = inject(SovaApiClient);

  configuration(tenantId: string, projectId: string): Observable<ProjectConfiguration> {
    return this.api.getProjectConfiguration(tenantId, projectId);
  }

  history(tenantId: string, projectId: string): Observable<readonly ConfigurationHistoryEntry[]> {
    return this.api
      .getProjectConfigurationHistory(tenantId, projectId)
      .pipe(map((response) => response.history));
  }

  createIssueType(
    tenantId: string,
    projectId: string,
    request: CreateProjectIssueTypeRequest,
  ): Observable<ProjectIssueType> {
    return this.api
      .createProjectIssueType(tenantId, projectId, request)
      .pipe(map((response) => response.issue_type));
  }

  updateIssueType(
    tenantId: string,
    projectId: string,
    issueTypeId: string,
    request: UpdateProjectIssueTypeRequest,
  ): Observable<ProjectIssueType> {
    return this.api
      .updateProjectIssueType(tenantId, projectId, issueTypeId, request)
      .pipe(map((response) => response.issue_type));
  }

  archiveIssueType(
    tenantId: string,
    projectId: string,
    issueTypeId: string,
    request: ArchiveProjectIssueTypeRequest,
  ): Observable<ProjectIssueType> {
    return this.api
      .archiveProjectIssueType(tenantId, projectId, issueTypeId, request)
      .pipe(map((response) => response.issue_type));
  }

  createDraft(
    tenantId: string,
    projectId: string,
    workflowId: string,
  ): Observable<WorkflowVersion> {
    return this.api
      .createWorkflowDraft(tenantId, projectId, workflowId)
      .pipe(map((response) => response.draft_version));
  }

  saveDraft(
    tenantId: string,
    projectId: string,
    workflowId: string,
    request: UpdateWorkflowDraftRequest,
  ): Observable<WorkflowVersion> {
    return this.api
      .updateWorkflowDraft(tenantId, projectId, workflowId, request)
      .pipe(map((response) => response.draft_version));
  }

  validate(
    tenantId: string,
    projectId: string,
    workflowId: string,
  ): Observable<WorkflowValidationResponse> {
    return this.api.validateWorkflowDraft(tenantId, projectId, workflowId);
  }

  impact(tenantId: string, projectId: string, workflowId: string): Observable<WorkflowImpact> {
    return this.api
      .getWorkflowImpact(tenantId, projectId, workflowId)
      .pipe(map((response) => response.impact));
  }

  publish(
    tenantId: string,
    projectId: string,
    workflowId: string,
    request: PublishWorkflowRequest,
  ): Observable<WorkflowVersion> {
    return this.api
      .publishWorkflow(tenantId, projectId, workflowId, request)
      .pipe(map((response) => response.published_version));
  }
}
