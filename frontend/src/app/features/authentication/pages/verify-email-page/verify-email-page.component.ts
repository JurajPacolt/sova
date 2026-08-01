import { Location } from '@angular/common';
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
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { isProblemDetails } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PublicAuthLayoutComponent } from '../../components/public-auth-layout/public-auth-layout.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

type VerificationState = 'loading' | 'verified' | 'already-verified' | 'invalid' | 'error';

@Component({
  selector: 'app-verify-email-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    PublicAuthLayoutComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './verify-email-page.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VerifyEmailPageComponent implements OnInit {
  private readonly api = inject(SovaApiClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  protected readonly state = signal<VerificationState>('loading');
  protected readonly resendSubmitting = signal(false);
  protected readonly resendCompleted = signal(false);
  protected readonly resendError = signal<TranslationKey | null>(null);
  protected readonly resendForm = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
  });

  constructor() {
    if (this.token !== '') {
      this.location.replaceState('/verify-email');
    }
  }

  ngOnInit(): void {
    if (this.token === '') {
      this.state.set('invalid');
      return;
    }

    this.api
      .verifyEmail({ token: this.token })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) =>
          this.state.set(response.status === 'VERIFIED' ? 'verified' : 'already-verified'),
        error: (error: unknown) => {
          this.state.set(this.isInvalidToken(error) ? 'invalid' : 'error');
        },
      });
  }

  protected requestAgain(): void {
    this.resendError.set(null);

    if (this.resendForm.invalid) {
      this.resendForm.markAllAsTouched();
      return;
    }

    this.resendSubmitting.set(true);
    this.api
      .requestEmailVerification(this.resendForm.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.resendSubmitting.set(false)),
      )
      .subscribe({
        next: () => this.resendCompleted.set(true),
        error: (error: unknown) =>
          this.resendError.set(
            error instanceof HttpErrorResponse && (error.status === 0 || error.status >= 500)
              ? 'access.serviceUnavailable'
              : 'access.unexpectedError',
          ),
      });
  }

  private isInvalidToken(error: unknown): boolean {
    return (
      error instanceof HttpErrorResponse &&
      isProblemDetails(error.error) &&
      error.error.code === 'EMAIL_VERIFICATION_TOKEN_INVALID'
    );
  }
}
