import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  Dashboard,
  DashboardList,
  DashboardTemplateResponse,
  DashboardWidget,
  WidgetData,
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
