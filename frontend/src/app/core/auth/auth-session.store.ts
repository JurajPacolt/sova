import { computed, Injectable, signal } from '@angular/core';
import { AuthenticatedUser, ImpersonationContext, MfaStatus } from '../api/api.models';

export type AuthenticationStatus = 'unknown' | 'authenticated' | 'anonymous';

@Injectable({
  providedIn: 'root',
})
export class AuthSessionStore {
  private readonly currentStatus = signal<AuthenticationStatus>('unknown');
  private readonly currentUser = signal<AuthenticatedUser | null>(null);
  private readonly currentImpersonation = signal<ImpersonationContext | null>(null);
  private readonly currentMfa = signal<MfaStatus | null>(null);

  readonly status = this.currentStatus.asReadonly();
  readonly user = this.currentUser.asReadonly();
  readonly impersonation = this.currentImpersonation.asReadonly();
  readonly mfa = this.currentMfa.asReadonly();
  readonly isAuthenticated = computed(() => this.currentStatus() === 'authenticated');
  readonly isSuperadmin = computed(() => this.currentUser()?.is_superadmin === true);
  readonly isImpersonating = computed(() => this.currentImpersonation() !== null);
  readonly mfaEnrollmentRequired = computed(() => this.currentMfa()?.enrollment_required === true);

  setAuthenticated(
    user: AuthenticatedUser | null,
    impersonation: ImpersonationContext | null = null,
    mfa?: MfaStatus,
  ): void {
    this.currentUser.set(user);
    this.currentImpersonation.set(impersonation);
    if (mfa !== undefined) {
      this.currentMfa.set(mfa);
    }
    this.currentStatus.set('authenticated');
  }

  setMfa(mfa: MfaStatus): void {
    this.currentMfa.set(mfa);
  }

  setAnonymous(): void {
    this.currentUser.set(null);
    this.currentImpersonation.set(null);
    this.currentMfa.set(null);
    this.currentStatus.set('anonymous');
  }

  reset(): void {
    this.currentUser.set(null);
    this.currentImpersonation.set(null);
    this.currentMfa.set(null);
    this.currentStatus.set('unknown');
  }
}
