import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { SecurityAuditPage, SecurityAuditQuery } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class SystemSecurityAuditService {
  private readonly api = inject(SovaApiClient);

  list(query: SecurityAuditQuery): Observable<SecurityAuditPage> {
    return this.api.listSystemSecurityAudit(query);
  }
}
