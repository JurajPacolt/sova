import { computed, Injectable, signal } from '@angular/core';
import { AuthenticatedUser, ImpersonationContext } from '../api/api.models';

export type AuthenticationStatus = 'unknown' | 'authenticated' | 'anonymous';

@Injectable({
  providedIn: 'root',
})
export class AuthSessionStore {
  private readonly currentStatus = signal<AuthenticationStatus>('unknown');
  private readonly currentUser = signal<AuthenticatedUser | null>(null);
  private readonly currentImpersonation = signal<ImpersonationContext | null>(null);

  readonly status = this.currentStatus.asReadonly();
  readonly user = this.currentUser.asReadonly();
  readonly impersonation = this.currentImpersonation.asReadonly();
  readonly isAuthenticated = computed(() => this.currentStatus() === 'authenticated');
  readonly isSuperadmin = computed(() => this.currentUser()?.is_superadmin === true);
  readonly isImpersonating = computed(() => this.currentImpersonation() !== null);

  setAuthenticated(
    user: AuthenticatedUser | null,
    impersonation: ImpersonationContext | null = null,
  ): void {
    this.currentUser.set(user);
    this.currentImpersonation.set(impersonation);
    this.currentStatus.set('authenticated');
  }

  setAnonymous(): void {
    this.currentUser.set(null);
    this.currentImpersonation.set(null);
    this.currentStatus.set('anonymous');
  }

  reset(): void {
    this.currentUser.set(null);
    this.currentImpersonation.set(null);
    this.currentStatus.set('unknown');
  }
}
