import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../core/i18n/translations';
import { isThemePreference, ThemePreference } from '../../../core/theme/theme';
import { ThemeService } from '../../../core/theme/theme.service';

interface ThemeOption {
  readonly labelKey: TranslationKey;
  readonly preference: ThemePreference;
}

@Component({
  selector: 'app-theme-switcher',
  standalone: true,
  imports: [TranslatePipe],
  templateUrl: './theme-switcher.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ThemeSwitcherComponent {
  protected readonly theme = inject(ThemeService);
  protected readonly options: readonly ThemeOption[] = [
    { preference: 'system', labelKey: 'theme.system' },
    { preference: 'light', labelKey: 'theme.light' },
    { preference: 'dark', labelKey: 'theme.dark' },
  ];

  protected selectTheme(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    if (isThemePreference(value)) {
      this.theme.setPreference(value);
    }
  }
}
