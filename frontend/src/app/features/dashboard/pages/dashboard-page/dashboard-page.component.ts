import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import {
  IssueStatus,
  StatusBadgeComponent,
} from '../../../../shared/components/status-badge/status-badge.component';

interface DashboardIssue {
  readonly key: string;
  readonly status: IssueStatus;
  readonly titleKey: TranslationKey;
}

@Component({
  selector: 'app-dashboard-page',
  standalone: true,
  imports: [PageHeaderComponent, RouterLink, StatusBadgeComponent, TranslatePipe],
  templateUrl: './dashboard-page.component.html',
  styleUrl: './dashboard-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardPageComponent {
  protected readonly assignedIssues = signal<readonly DashboardIssue[]>([
    { key: 'SOVA-1', titleKey: 'dashboard.issueIdentity', status: 'open' },
    { key: 'SOVA-2', titleKey: 'dashboard.issueTenancy', status: 'in-progress' },
    { key: 'SOVA-3', titleKey: 'dashboard.issueTests', status: 'resolved' },
  ]);

  protected readonly openIssueCount = computed(
    () => this.assignedIssues().filter((issue) => issue.status !== 'closed').length,
  );
}
