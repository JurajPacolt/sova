import { inject, Injectable } from '@angular/core';
import { switchMap, tap } from 'rxjs';
import { StartImpersonationRequest } from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantAccessService } from '../tenancy/tenant-access.service';
import { AuthSessionStore } from './auth-session.store';

@Injectable({
  providedIn: 'root',
})
export class ImpersonationSessionService {
  private readonly api = inject(SovaApiClient);
  private readonly session = inject(AuthSessionStore);
  private readonly tenantAccess = inject(TenantAccessService);

  start(request: StartImpersonationRequest) {
    return this.api.startImpersonation(request).pipe(
      tap((response) => {
        this.session.setAuthenticated(response.user, response.impersonation);
        this.tenantAccess.clear();
      }),
    );
  }

  end() {
    return this.api.endCurrentImpersonation().pipe(
      switchMap(() => this.api.getCurrentSession()),
      tap((response) => {
        this.session.setAuthenticated(response.user, response.impersonation);
        this.tenantAccess.clear();
      }),
    );
  }
}
