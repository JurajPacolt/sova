import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { I18nService } from '../../../core/i18n/i18n.service';
import { TranslationKey } from '../../../core/i18n/translations';

export type IssueStatus = 'open' | 'in-progress' | 'resolved' | 'closed';

const STATUS_LABEL_KEYS: Record<IssueStatus, TranslationKey> = {
  open: 'status.open',
  'in-progress': 'status.inProgress',
  resolved: 'status.resolved',
  closed: 'status.closed',
};

const STATUS_CLASSES: Record<IssueStatus, string> = {
  open: 'text-bg-secondary',
  'in-progress': 'text-bg-primary',
  resolved: 'text-bg-success',
  closed: 'text-bg-dark',
};

@Component({
  selector: 'app-status-badge',
  standalone: true,
  imports: [],
  templateUrl: './status-badge.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatusBadgeComponent {
  private readonly i18n = inject(I18nService);

  readonly status = input.required<IssueStatus>();

  protected readonly label = computed(() => this.i18n.translate(STATUS_LABEL_KEYS[this.status()]));
  protected readonly badgeClass = computed(() => STATUS_CLASSES[this.status()]);
}
