import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { isProblemDetails } from '../../../../core/api/api.models';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { destinationAfterLogin, sanitizeReturnUrl } from '../../../../core/navigation/return-url';
import { LanguageSwitcherComponent } from '../../../../shared/components/language-switcher/language-switcher.component';
import { ThemeSwitcherComponent } from '../../../../shared/components/theme-switcher/theme-switcher.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-login-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    LanguageSwitcherComponent,
    ReactiveFormsModule,
    RouterLink,
    ThemeSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './login-page.component.html',
  styleUrl: './login-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginPageComponent {
  private readonly auth = inject(AuthSessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly returnUrl = sanitizeReturnUrl(
    this.route.snapshot.queryParamMap.get('returnUrl'),
  );

  protected readonly submitting = signal(false);
  protected readonly mfaChallenge = signal(false);
  protected readonly formError = signal<TranslationKey | null>(
    this.route.snapshot.queryParamMap.get('serviceUnavailable') === '1'
      ? 'auth.serviceUnavailable'
      : null,
  );

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(8)],
    }),
    mfa_code: new FormControl('', {
      nonNullable: true,
    }),
  });

  protected submit(): void {
    this.formError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    const value = this.form.getRawValue();
    const request = this.mfaChallenge()
      ? value
      : {
          email: value.email,
          password: value.password,
        };
    this.auth
      .login(request)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: ({ login, tenants }) => {
          if (login.mfa.enrollment_required) {
            void this.router.navigateByUrl('/mfa/setup');
            return;
          }

          const destination = destinationAfterLogin(
            this.returnUrl,
            tenants,
            login.user.is_superadmin,
          );
          void this.router.navigateByUrl(destination);
        },
        error: (error: unknown) => {
          this.formError.set(this.errorKey(error));
        },
      });
  }

  private errorKey(error: unknown): TranslationKey {
    if (error instanceof HttpErrorResponse) {
      if (isProblemDetails(error.error)) {
        if (error.error.code === 'INVALID_CREDENTIALS') {
          return 'auth.invalidCredentials';
        }

        if (error.error.code === 'LOGIN_RATE_LIMITED') {
          return 'auth.rateLimited';
        }

        if (error.error.code === 'MFA_CODE_REQUIRED') {
          this.enableMfaChallenge();
          return 'auth.mfaRequired';
        }

        if (error.error.code === 'MFA_CODE_INVALID') {
          this.enableMfaChallenge();
          return 'auth.mfaInvalid';
        }
      }

      if (error.status === 0 || error.status >= 500) {
        return 'auth.serviceUnavailable';
      }
    }

    return 'auth.unexpectedError';
  }

  private enableMfaChallenge(): void {
    this.mfaChallenge.set(true);
    this.form.controls.mfa_code.setValidators([Validators.required, Validators.maxLength(64)]);
    this.form.controls.mfa_code.updateValueAndValidity();
  }
}
