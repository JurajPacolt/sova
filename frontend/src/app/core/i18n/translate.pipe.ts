import { inject, Pipe, PipeTransform } from '@angular/core';
import { I18nService, TranslationParams } from './i18n.service';
import { TranslationKey } from './translations';

@Pipe({
  name: 'translate',
  standalone: true,
  pure: false,
})
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);

  transform(key: TranslationKey, params: TranslationParams = {}): string {
    return this.i18n.translate(key, params);
  }
}
