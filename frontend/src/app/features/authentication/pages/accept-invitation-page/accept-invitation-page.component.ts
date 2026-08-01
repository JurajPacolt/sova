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
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, finalize, forkJoin, of } from 'rxjs';
import { InvitationPreview, isProblemDetails, LocaleCode } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionService } from '../../../../core/auth/auth-session.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { LANGUAGE_OPTIONS } from '../../../../core/i18n/language';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PublicAuthLayoutComponent } from '../../components/public-auth-layout/public-auth-layout.component';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';

@Component({
  selector: 'app-accept-invitation-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    PublicAuthLayoutComponent,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './accept-invitation-page.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AcceptInvitationPageComponent implements OnInit {
  private readonly api = inject(SovaApiClient);
  private readonly auth = inject(AuthSessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);
  private readonly location = inject(Location);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  protected readonly languageOptions = LANGUAGE_OPTIONS;
  protected readonly loading = signal(true);
  protected readonly accepting = signal(false);
  protected readonly authenticated = signal(false);
  protected readonly invitation = signal<InvitationPreview | null>(null);
  protected readonly acceptedNewAccount = signal(false);
  protected readonly error = signal<TranslationKey | null>(null);
  protected readonly form = new FormGroup({
    display_name: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(160)],
    }),
    preferred_locale: new FormControl<LocaleCode>(this.i18n.language(), {
      nonNullable: true,
    }),
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
      this.location.replaceState('/accept-invitation');
    }
  }

  ngOnInit(): void {
    if (this.token === '') {
      this.loading.set(false);
      this.error.set('access.invalidInvitation');
      return;
    }

    this.loading.set(true);
    forkJoin({
      invitation: this.api.inspectInvitation({ token: this.token }),
      // Whether somebody is already signed in only decides which form to
      // offer, so a failed session probe is answered with the safe "no"
      // rather than taken as an answer about the invitation. Losing the
      // invitation over an unrelated request would be the wrong screen for
      // the right reason.
      authenticated: this.auth.ensureAuthenticated().pipe(catchError(() => of(false))),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ invitation, authenticated }) => {
          this.invitation.set(invitation.invitation);
          this.authenticated.set(authenticated);
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected acceptNewAccount(): void {
    this.error.set(null);

    if (
      this.form.invalid ||
      this.form.controls.password.value !== this.form.controls.password_confirmation.value
    ) {
      this.form.markAllAsTouched();
      this.error.set('access.passwordConfirmationMismatch');
      return;
    }

    this.accepting.set(true);
    this.api
      .acceptNewAccountInvitation({
        token: this.token,
        ...this.form.getRawValue(),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.accepting.set(false)),
      )
      .subscribe({
        next: () => {
          this.form.reset();
          this.acceptedNewAccount.set(true);
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  protected acceptExistingAccount(): void {
    this.error.set(null);
    this.accepting.set(true);
    this.api
      .acceptExistingAccountInvitation({ token: this.token })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.accepting.set(false)),
      )
      .subscribe({
        next: (accepted) => {
          void this.router.navigate(['/t', accepted.tenant_slug, 'dashboard']);
        },
        error: (error: unknown) => this.error.set(this.errorKey(error)),
      });
  }

  private errorKey(error: unknown): TranslationKey {
    if (error instanceof HttpErrorResponse) {
      if (isProblemDetails(error.error)) {
        switch (error.error.code) {
          case 'INVITATION_TOKEN_INVALID':
            return 'access.invalidInvitation';
          case 'INVITATION_ACCOUNT_EXISTS':
            return 'access.invitationAccountExists';
          case 'INVITATION_ACCOUNT_MISMATCH':
            return 'access.invitationAccountMismatch';
          case 'INVITATION_MEMBERSHIP_BLOCKED':
            return 'access.invitationMembershipBlocked';
          case 'PASSWORD_POLICY_VIOLATION':
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
