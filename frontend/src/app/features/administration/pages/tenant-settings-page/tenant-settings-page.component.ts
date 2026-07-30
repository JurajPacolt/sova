import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';
import { LocaleCode, TenantSettings } from '../../../../core/api/api.models';
import { AriaRequiredDirective } from '../../../../core/a11y/aria-required.directive';
import { FocusSummaryDirective } from '../../../../core/a11y/focus-summary.directive';
import { problemCode } from '../../../../core/errors/api-error';
import { LANGUAGE_OPTIONS } from '../../../../core/i18n/language';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ErrorStateComponent } from '../../../../shared/components/error-state/error-state.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { TenantSettingsAdministrationService } from '../../tenant-settings-administration.service';

@Component({
  selector: 'app-tenant-settings-page',
  standalone: true,
  imports: [
    AriaRequiredDirective,
    ErrorStateComponent,
    FocusSummaryDirective,
    PageHeaderComponent,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  templateUrl: './tenant-settings-page.component.html',
  styleUrl: './tenant-settings-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSettingsPageComponent implements OnInit {
  private readonly administration = inject(TenantSettingsAdministrationService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private readonly tenantStore = inject(TenantStore);

  private readonly tenantId = computed(() => this.tenantStore.activeTenantId());

  protected readonly languageOptions = LANGUAGE_OPTIONS;
  protected readonly settings = signal<TenantSettings | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadFailure = signal<unknown>(null);
  protected readonly savingGeneral = signal(false);
  protected readonly savingLocalization = signal(false);
  protected readonly generalError = signal<TranslationKey | null>(null);
  protected readonly localizationError = signal<TranslationKey | null>(null);
  protected readonly generalSaved = signal(false);
  protected readonly localizationSaved = signal(false);

  protected readonly generalForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(200)]],
  });

  protected readonly localizationForm = this.formBuilder.nonNullable.group({
    default_locale: ['en' as LocaleCode, Validators.required],
    timezone: ['', [Validators.required, Validators.maxLength(64)]],
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    const tenantId = this.tenantId();

    if (tenantId === null || this.loading()) {
      return;
    }

    this.loadFailure.set(null);
    this.loading.set(true);
    this.administration
      .get(tenantId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (settings) => this.adopt(settings),
        error: (failure: unknown) => this.loadFailure.set(failure),
      });
  }

  protected saveGeneral(): void {
    const tenantId = this.tenantId();
    const settings = this.settings();

    if (tenantId === null || settings === null || this.savingGeneral()) {
      return;
    }

    if (this.generalForm.invalid) {
      this.generalForm.markAllAsTouched();
      this.generalError.set('tenantSettings.formInvalid');
      return;
    }

    this.generalError.set(null);
    this.generalSaved.set(false);
    this.savingGeneral.set(true);
    this.administration
      .updateGeneral(tenantId, {
        name: this.generalForm.getRawValue().name.trim(),
        expected_revision: settings.revision,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.savingGeneral.set(false)),
      )
      .subscribe({
        next: (updated) => {
          this.settings.set(updated);
          this.generalForm.reset({ name: updated.name });
          this.generalSaved.set(true);
        },
        error: (failure: unknown) => this.generalError.set(this.saveMessage(failure)),
      });
  }

  protected saveLocalization(): void {
    const tenantId = this.tenantId();
    const settings = this.settings();

    if (tenantId === null || settings === null || this.savingLocalization()) {
      return;
    }

    if (this.localizationForm.invalid) {
      this.localizationForm.markAllAsTouched();
      this.localizationError.set('tenantSettings.formInvalid');
      return;
    }

    const value = this.localizationForm.getRawValue();
    this.localizationError.set(null);
    this.localizationSaved.set(false);
    this.savingLocalization.set(true);
    this.administration
      .updateLocalization(tenantId, {
        default_locale: value.default_locale,
        timezone: value.timezone.trim(),
        expected_revision: settings.revision,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.savingLocalization.set(false)),
      )
      .subscribe({
        next: (updated) => {
          this.settings.set(updated);
          this.localizationForm.reset({
            default_locale: updated.default_locale,
            timezone: updated.timezone,
          });
          this.localizationSaved.set(true);
        },
        error: (failure: unknown) => this.localizationError.set(this.saveMessage(failure)),
      });
  }

  private adopt(settings: TenantSettings): void {
    this.settings.set(settings);
    this.generalForm.reset({ name: settings.name });
    this.localizationForm.reset({
      default_locale: settings.default_locale,
      timezone: settings.timezone,
    });
    this.generalError.set(null);
    this.localizationError.set(null);
    this.generalSaved.set(false);
    this.localizationSaved.set(false);
  }

  private saveMessage(failure: unknown): TranslationKey {
    return problemCode(failure) === 'TENANT_REVISION_CONFLICT'
      ? 'tenantSettings.conflict'
      : 'tenantSettings.saveError';
  }
}
