import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { SecurityAuditPage, SecurityAuditQuery } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

const FILENAME_PATTERN = /filename="?([^";]+)"?/;

@Injectable({
  providedIn: 'root',
})
export class TenantSecurityAuditService {
  private readonly api = inject(SovaApiClient);

  list(tenantId: string, query: SecurityAuditQuery): Observable<SecurityAuditPage> {
    return this.api.listTenantSecurityAudit(tenantId, query);
  }

  export(tenantId: string, query: SecurityAuditQuery): Observable<void> {
    return this.api.exportTenantSecurityAudit(tenantId, query).pipe(
      map((response) => {
        const body = response.body;

        if (body === null) {
          throw new Error('The audit export response had no body.');
        }

        this.download(body, this.filename(response.headers.get('Content-Disposition')));
      }),
    );
  }

  private filename(contentDisposition: string | null): string {
    const match = contentDisposition === null ? null : FILENAME_PATTERN.exec(contentDisposition);

    return match?.[1] ?? 'tenant-audit-export.csv';
  }

  private download(body: Blob, filename: string): void {
    const url = URL.createObjectURL(body);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
  }
}
