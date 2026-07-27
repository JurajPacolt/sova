import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { LanguageSwitcherComponent } from '../../../../shared/components/language-switcher/language-switcher.component';
import { ThemeSwitcherComponent } from '../../../../shared/components/theme-switcher/theme-switcher.component';

@Component({
  selector: 'app-public-auth-layout',
  standalone: true,
  imports: [LanguageSwitcherComponent, ThemeSwitcherComponent, TranslatePipe],
  templateUrl: './public-auth-layout.component.html',
  styleUrl: './public-auth-layout.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PublicAuthLayoutComponent {
  readonly title = input.required<TranslationKey>();
  readonly subtitle = input.required<TranslationKey>();
  readonly wide = input(false);
}
