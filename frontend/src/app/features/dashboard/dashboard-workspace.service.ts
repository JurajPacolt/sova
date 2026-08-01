import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  CreateWidgetRequest,
  Dashboard,
  DashboardLayoutRequest,
  DashboardList,
  DashboardTemplateResponse,
  DashboardWidget,
  SavedQuery,
  UpdateWidgetRequest,
  WidgetData,
  WidgetTypeDefinition,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

/**
 * The dashboard feature's single door to the API.
 *
 * Pages depend on this rather than on the HTTP client, so the tenant identifier
 * is applied in one place and a screen can never accidentally address another
 * tenant. Guards and this service are user-interface affordances only — the
 * backend authorises every call again, and a dashboard that is not the
 * caller's own answers `404` there regardless of what this layer asks for.
 */
@Injectable({ providedIn: 'root' })
export class DashboardWorkspaceService {
  private readonly api = inject(SovaApiClient);

  /**
   * The caller's dashboards, plus which one to open when none was named. A
   * member who has never opened this screen is given the starter dashboard by
   * this very call, so the list is empty only for somebody who may not own one.
   */
  list(tenantId: string): Observable<DashboardList> {
    return this.api.listDashboards(tenantId);
  }

  /**
   * Records that the caller moved to this dashboard. Called when somebody
   * **chooses** one, never on plain navigation: the server deliberately keeps
   * this out of `GET`, and the client must not put it back in by writing on
   * every load.
   */
  markActive(tenantId: string, dashboardId: string): Observable<Dashboard> {
    return this.api
      .setActiveDashboard(tenantId, dashboardId)
      .pipe(map((response) => response.dashboard));
  }

  /**
   * A fresh dashboard from the system template. It adds; nothing existing is
   * overwritten, and the default stays where the member put it.
   */
  restoreFromTemplate(tenantId: string, name?: string): Observable<DashboardTemplateResponse> {
    return this.api.restoreDashboardFromTemplate(tenantId, name === undefined ? {} : { name });
  }

  create(tenantId: string, name: string): Observable<Dashboard> {
    return this.api.createDashboard(tenantId, { name }).pipe(map((response) => response.dashboard));
  }

  /**
   * Renaming and reordering share one endpoint and one optimistic lock, so a
   * caller has to say which version it saw either way.
   */
  update(
    tenantId: string,
    dashboardId: string,
    expectedVersion: number,
    name: string,
    position?: number,
  ): Observable<Dashboard> {
    return this.api
      .updateDashboard(tenantId, dashboardId, {
        expected_version: expectedVersion,
        name,
        ...(position === undefined ? {} : { position }),
      })
      .pipe(map((response) => response.dashboard));
  }

  /**
   * A copy carries the same widgets pointed at the **same** saved queries, and
   * never inherits the default flag.
   */
  duplicate(tenantId: string, dashboardId: string, name: string): Observable<Dashboard> {
    return this.api
      .copyDashboard(tenantId, dashboardId, { name })
      .pipe(map((response) => response.dashboard));
  }

  makeDefault(tenantId: string, dashboardId: string): Observable<Dashboard> {
    return this.api
      .makeDashboardDefault(tenantId, dashboardId)
      .pipe(map((response) => response.dashboard));
  }

  /** The last dashboard stays: a member must always have one to land on. */
  remove(tenantId: string, dashboardId: string): Observable<void> {
    return this.api.deleteDashboard(tenantId, dashboardId);
  }

  /** One dashboard, for the version a layout has to be written against. */
  get(tenantId: string, dashboardId: string): Observable<Dashboard> {
    return this.api.getDashboard(tenantId, dashboardId).pipe(map((response) => response.dashboard));
  }

  /**
   * The widget catalogue. Its labels are localisation keys, so the wording
   * belongs to the six catalogs rather than to the server.
   */
  widgetTypes(tenantId: string): Observable<readonly WidgetTypeDefinition[]> {
    return this.api.listWidgetTypes(tenantId).pipe(map((response) => response.widget_types));
  }

  /**
   * Saved queries the caller can already open — read through the shared API
   * client rather than through the issues feature, because a feature must not
   * reach into another feature's internals. Archived ones are left out: a
   * widget may not point at a query that has been retired.
   */
  savedQueries(tenantId: string): Observable<readonly SavedQuery[]> {
    return this.api
      .listSavedQueries(tenantId)
      .pipe(map((response) => response.saved_queries.filter((query) => !query.archived)));
  }

  addWidget(
    tenantId: string,
    dashboardId: string,
    request: CreateWidgetRequest,
  ): Observable<DashboardWidget> {
    return this.api
      .createDashboardWidget(tenantId, dashboardId, request)
      .pipe(map((response) => response.widget));
  }

  updateWidget(
    tenantId: string,
    dashboardId: string,
    widgetId: string,
    request: UpdateWidgetRequest,
  ): Observable<DashboardWidget> {
    return this.api
      .updateDashboardWidget(tenantId, dashboardId, widgetId, request)
      .pipe(map((response) => response.widget));
  }

  removeWidget(tenantId: string, dashboardId: string, widgetId: string): Observable<void> {
    return this.api.deleteDashboardWidget(tenantId, dashboardId, widgetId);
  }

  /**
   * The whole arrangement in one request. Two widgets moving past each other is
   * legal only as a pair, so this is never split per widget.
   */
  applyLayout(
    tenantId: string,
    dashboardId: string,
    request: DashboardLayoutRequest,
  ): Observable<readonly DashboardWidget[]> {
    return this.api
      .applyDashboardLayout(tenantId, dashboardId, request)
      .pipe(map((response) => response.widgets));
  }

  widgets(tenantId: string, dashboardId: string): Observable<readonly DashboardWidget[]> {
    return this.api
      .listDashboardWidgets(tenantId, dashboardId)
      .pipe(map((response) => response.widgets));
  }

  /**
   * The data behind one widget. The saved query runs as the caller, so the same
   * widget legitimately shows different numbers to different people.
   */
  widgetData(tenantId: string, dashboardId: string, widgetId: string): Observable<WidgetData> {
    return this.api
      .getWidgetData(tenantId, dashboardId, widgetId)
      .pipe(map((response) => response.data));
  }
}
