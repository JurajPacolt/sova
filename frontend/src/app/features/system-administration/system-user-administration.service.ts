import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ChangeSystemUserStatusRequest, SystemUser } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class SystemUserAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(): Observable<readonly SystemUser[]> {
    return this.api.listSystemUsers().pipe(map((response) => response.users));
  }

  changeStatus(
    userId: string,
    status: ChangeSystemUserStatusRequest['status'],
  ): Observable<SystemUser> {
    return this.api
      .changeSystemUserStatus(userId, { status })
      .pipe(map((response) => response.user));
  }

  grantSuperadmin(userId: string): Observable<SystemUser> {
    return this.api.grantSystemSuperadmin(userId).pipe(map((response) => response.user));
  }

  revokeSuperadmin(userId: string): Observable<SystemUser> {
    return this.api.revokeSystemSuperadmin(userId).pipe(map((response) => response.user));
  }
}
