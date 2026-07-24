import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { I18nService } from '../../../core/i18n/i18n.service';

@Component({
  selector: 'app-loading-indicator',
  standalone: true,
  imports: [],
  templateUrl: './loading-indicator.component.html',
  styleUrl: './loading-indicator.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoadingIndicatorComponent {
  private readonly i18n = inject(I18nService);

  readonly label = input<string>();
  protected readonly resolvedLabel = computed(
    () => this.label() ?? this.i18n.translate('common.loading'),
  );
}
