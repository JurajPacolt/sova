import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

interface ProjectSummary {
  readonly code: string;
  readonly nameKey: TranslationKey;
  readonly openIssues: number;
  readonly status: 'active' | 'archived';
}

@Component({
  selector: 'app-project-list-page',
  standalone: true,
  imports: [PageHeaderComponent, TranslatePipe],
  templateUrl: './project-list-page.component.html',
  styleUrl: './project-list-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectListPageComponent {
  protected readonly projects = signal<readonly ProjectSummary[]>([
    { code: 'SOVA', nameKey: 'projects.sovaName', openIssues: 12, status: 'active' },
    { code: 'DOC', nameKey: 'projects.docsName', openIssues: 3, status: 'active' },
  ]);

  protected readonly activeProjectCount = computed(
    () => this.projects().filter((project) => project.status === 'active').length,
  );
}
