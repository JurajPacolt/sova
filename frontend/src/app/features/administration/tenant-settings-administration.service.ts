import { inject, Injectable } from '@angular/core';
import { map, Observable, tap } from 'rxjs';
import {
  TenantSettings,
  UpdateTenantGeneralSettingsRequest,
  UpdateTenantLocalizationSettingsRequest,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { TenantStore } from '../../core/tenancy/tenant.store';

@Injectable({ providedIn: 'root' })
export class TenantSettingsAdministrationService {
  private readonly api = inject(SovaApiClient);
  private readonly tenantStore = inject(TenantStore);

  get(tenantId: string): Observable<TenantSettings> {
    return this.api.getTenantSettings(tenantId).pipe(map((response) => response.settings));
  }

  updateGeneral(
    tenantId: string,
    request: UpdateTenantGeneralSettingsRequest,
  ): Observable<TenantSettings> {
    return this.api.updateTenantGeneralSettings(tenantId, request).pipe(
      map((response) => response.settings),
      tap((settings) => this.tenantStore.updateActiveTenantName(settings.name)),
    );
  }

  updateLocalization(
    tenantId: string,
    request: UpdateTenantLocalizationSettingsRequest,
  ): Observable<TenantSettings> {
    return this.api
      .updateTenantLocalizationSettings(tenantId, request)
      .pipe(map((response) => response.settings));
  }
}
