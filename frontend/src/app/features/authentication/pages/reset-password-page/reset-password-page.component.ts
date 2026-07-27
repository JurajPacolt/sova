import { Location } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { isProblemDetails } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PublicAuthLayoutComponent } from '../../components/public-auth-layout/public-auth-layout.component';

@Component({
  selector: 'app-reset-password-page',
  standalone: true,
  imports: [PublicAuthLayoutComponent, ReactiveFormsModule, RouterLink, TranslatePipe],
  templateUrl: './reset-password-page.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResetPasswordPageComponent {
  private readonly api = inject(SovaApiClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);
  protected readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  protected readonly submitting = signal(false);
  protected readonly completed = signal(false);
  protected readonly error = signal<TranslationKey | null>(
    this.token === '' ? 'access.invalidResetLink' : null,
  );
  protected readonly form = new FormGroup({
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(15), Validators.maxLength(1024)],
    }),
    password_confirmation: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required],
    }),
  });

  constructor() {
    if (this.token !== '') {
      this.location.replaceState('/reset-password');
    }
  }

  protected submit(): void {
    this.error.set(null);

    if (
      this.token === '' ||
      this.form.invalid ||
      this.form.controls.password.value !== this.form.controls.password_confirmation.value
    ) {
      this.form.markAllAsTouched();
      this.error.set(
        this.token === '' ? 'access.invalidResetLink' : 'access.passwordConfirmationMismatch',
      );
      return;
    }

    this.submitting.set(true);
    this.api
      .resetPassword({
        token: this.token,
        ...this.form.getRawValue(),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: () => {
          this.form.reset();
          this.completed.set(true);
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  private errorKey(error: unknown): TranslationKey {
    if (error instanceof HttpErrorResponse) {
      if (isProblemDetails(error.error)) {
        if (error.error.code === 'PASSWORD_RESET_TOKEN_INVALID') {
          return 'access.invalidResetLink';
        }

        if (error.error.code === 'PASSWORD_POLICY_VIOLATION') {
          return 'access.passwordPolicy';
        }
      }

      if (error.status === 0 || error.status >= 500) {
        return 'access.serviceUnavailable';
      }
    }

    return 'access.unexpectedError';
  }
}
