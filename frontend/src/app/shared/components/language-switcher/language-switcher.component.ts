import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { I18nService } from '../../../core/i18n/i18n.service';
import { isLanguageCode, LANGUAGE_OPTIONS } from '../../../core/i18n/language';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';

@Component({
  selector: 'app-language-switcher',
  standalone: true,
  imports: [TranslatePipe],
  templateUrl: './language-switcher.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LanguageSwitcherComponent {
  protected readonly i18n = inject(I18nService);
  protected readonly languages = LANGUAGE_OPTIONS;

  protected selectLanguage(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    if (isLanguageCode(value)) {
      this.i18n.setLanguage(value);
    }
  }
}
