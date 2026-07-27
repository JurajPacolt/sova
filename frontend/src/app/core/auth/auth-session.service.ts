import { HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import {
  catchError,
  finalize,
  map,
  Observable,
  of,
  shareReplay,
  switchMap,
  tap,
  throwError,
} from 'rxjs';
import { AccessibleTenant, LoginRequest, LoginResponse } from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantAccessService } from '../tenancy/tenant-access.service';
import { AuthSessionStore } from './auth-session.store';
import { isSessionRequiredError } from './session-error';

export interface AuthenticatedSessionResult {
  readonly login: LoginResponse;
  readonly tenants: readonly AccessibleTenant[];
}

@Injectable({
  providedIn: 'root',
})
export class AuthSessionService {
  private readonly api = inject(SovaApiClient);
  private readonly sessionStore = inject(AuthSessionStore);
  private readonly tenantAccess = inject(TenantAccessService);
  private restoreRequest: Observable<boolean> | null = null;

  login(credentials: LoginRequest): Observable<AuthenticatedSessionResult> {
    return this.api.login(credentials).pipe(
      tap((response) => this.sessionStore.setAuthenticated(response.user)),
      switchMap((login) =>
        this.tenantAccess.refresh().pipe(
          map((tenants) => ({ login, tenants })),
          catchError((error: unknown) =>
            this.sessionStore.isAuthenticated()
              ? of({ login, tenants: [] })
              : throwError(() => error),
          ),
        ),
      ),
    );
  }

  logout(): Observable<void> {
    return this.api.logout().pipe(
      tap(() => {
        this.sessionStore.setAnonymous();
        this.tenantAccess.clear();
      }),
    );
  }

  ensureAuthenticated(): Observable<boolean> {
    if (this.sessionStore.status() === 'authenticated') {
      return of(true);
    }

    if (this.sessionStore.status() === 'anonymous') {
      return of(false);
    }

    if (this.restoreRequest !== null) {
      return this.restoreRequest;
    }

    this.restoreRequest = this.api.getCurrentSession().pipe(
      tap((response) => this.sessionStore.setAuthenticated(response.user, response.impersonation)),
      map(() => true),
      catchError((error: unknown) => {
        if (error instanceof HttpErrorResponse && isSessionRequiredError(error)) {
          this.sessionStore.setAnonymous();
          this.tenantAccess.clear();
          return of(false);
        }

        return throwError(() => error);
      }),
      finalize(() => {
        this.restoreRequest = null;
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    return this.restoreRequest;
  }

  invalidate(): void {
    this.sessionStore.setAnonymous();
    this.tenantAccess.clear();
  }
}
