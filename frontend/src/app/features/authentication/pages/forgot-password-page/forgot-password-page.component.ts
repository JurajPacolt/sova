import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { isProblemDetails } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PublicAuthLayoutComponent } from '../../components/public-auth-layout/public-auth-layout.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-forgot-password-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    PublicAuthLayoutComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './forgot-password-page.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForgotPasswordPageComponent {
  private readonly api = inject(SovaApiClient);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly submitting = signal(false);
  protected readonly submitted = signal(false);
  protected readonly error = signal<TranslationKey | null>(null);
  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
  });

  protected submit(): void {
    this.error.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.api
      .requestPasswordReset(this.form.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: () => this.submitted.set(true),
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  private errorKey(error: unknown): TranslationKey {
    if (error instanceof HttpErrorResponse) {
      if (isProblemDetails(error.error) && error.error.code === 'PASSWORD_RESET_REQUEST_INVALID') {
        return 'access.invalidEmail';
      }

      if (error.status === 0 || error.status >= 500) {
        return 'access.serviceUnavailable';
      }
    }

    return 'access.unexpectedError';
  }
}
