import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { finalize, map, switchMap } from 'rxjs';
import { isProblemDetails, MfaEnrollmentResponse } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PublicAuthLayoutComponent } from '../../components/public-auth-layout/public-auth-layout.component';

@Component({
  selector: 'app-mfa-setup-page',
  standalone: true,
  imports: [PublicAuthLayoutComponent, ReactiveFormsModule, TranslatePipe],
  templateUrl: './mfa-setup-page.component.html',
  styleUrl: './mfa-setup-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MfaSetupPageComponent implements OnInit {
  private readonly api = inject(SovaApiClient);
  private readonly auth = inject(AuthSessionService);
  private readonly session = inject(AuthSessionStore);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);

  protected readonly mfa = this.session.mfa;
  protected readonly loading = signal(true);
  protected readonly submitting = signal(false);
  protected readonly enrollment = signal<MfaEnrollmentResponse | null>(null);
  protected readonly recoveryCodes = signal<readonly string[]>([]);
  protected readonly error = signal<TranslationKey | null>(null);

  protected readonly passwordForm = new FormGroup({
    current_password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(8)],
    }),
  });
  protected readonly confirmationForm = new FormGroup({
    code: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.pattern(/^[0-9]{6}$/)],
    }),
  });
  protected readonly recoveryForm = new FormGroup({
    current_password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(8)],
    }),
    code: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(64)],
    }),
  });

  ngOnInit(): void {
    this.api
      .getMfaStatus()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ mfa }) => this.session.setMfa(mfa),
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected beginEnrollment(): void {
    this.error.set(null);

    if (this.passwordForm.invalid) {
      this.passwordForm.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.api
      .beginMfaEnrollment(this.passwordForm.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: (enrollment) => {
          this.enrollment.set(enrollment);
          this.passwordForm.reset();
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected confirmEnrollment(): void {
    this.error.set(null);

    if (this.confirmationForm.invalid) {
      this.confirmationForm.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.api
      .confirmMfaEnrollment(this.confirmationForm.getRawValue())
      .pipe(
        switchMap((confirmation) => {
          this.session.setMfa(confirmation.mfa);

          return this.auth.refreshCurrentSession().pipe(map(() => confirmation));
        }),
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: (confirmation) => {
          this.recoveryCodes.set(confirmation.recovery_codes);
          this.enrollment.set(null);
          this.confirmationForm.reset();
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected regenerateRecoveryCodes(): void {
    this.error.set(null);

    if (this.recoveryForm.invalid) {
      this.recoveryForm.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.api
      .regenerateMfaRecoveryCodes(this.recoveryForm.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: (response) => {
          this.recoveryCodes.set(response.recovery_codes);
          this.recoveryForm.reset();
          const status = this.session.mfa();

          if (status !== null) {
            this.session.setMfa({
              ...status,
              recovery_codes_remaining: response.recovery_codes.length,
            });
          }
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected continue(): void {
    const destination = this.session.isSuperadmin() ? '/system/tenants' : '/select-tenant';
    void this.router.navigateByUrl(destination);
  }

  protected logout(): void {
    this.submitting.set(true);
    this.auth
      .logout()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: () => void this.router.navigateByUrl('/login'),
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  private errorKey(error: unknown): TranslationKey {
    if (error instanceof HttpErrorResponse) {
      if (isProblemDetails(error.error)) {
        if (error.error.code === 'MFA_REAUTHENTICATION_FAILED') {
          return 'mfa.passwordInvalid';
        }

        if (error.error.code === 'MFA_CODE_INVALID') {
          return 'mfa.codeInvalid';
        }

        if (error.error.code === 'MFA_ALREADY_ENABLED') {
          return 'mfa.alreadyEnabled';
        }
      }

      if (error.status === 0 || error.status >= 500) {
        return 'mfa.serviceUnavailable';
      }
    }

    return 'mfa.unexpectedError';
  }
}
