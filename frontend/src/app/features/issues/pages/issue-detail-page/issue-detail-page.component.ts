import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import {
  IssueStatus,
  StatusBadgeComponent,
} from '../../../../shared/components/status-badge/status-badge.component';

@Component({
  selector: 'app-issue-detail-page',
  standalone: true,
  imports: [PageHeaderComponent, StatusBadgeComponent, TranslatePipe],
  templateUrl: './issue-detail-page.component.html',
  styleUrl: './issue-detail-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IssueDetailPageComponent {
  readonly issueKey = input.required<string>();

  protected readonly status = signal<IssueStatus>('open');
  protected readonly nextActionKey = computed<TranslationKey>(() =>
    this.status() === 'open' ? 'issue.startWork' : 'issue.resolve',
  );

  protected transition(): void {
    this.status.update((status) => (status === 'open' ? 'in-progress' : 'resolved'));
  }
}
