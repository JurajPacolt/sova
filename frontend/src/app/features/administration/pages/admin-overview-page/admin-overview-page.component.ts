import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

interface AdminSection {
  readonly descriptionKey: TranslationKey;
  readonly titleKey: TranslationKey;
}

@Component({
  selector: 'app-admin-overview-page',
  standalone: true,
  imports: [PageHeaderComponent, TranslatePipe],
  templateUrl: './admin-overview-page.component.html',
  styleUrl: './admin-overview-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdminOverviewPageComponent {
  protected readonly sections = signal<readonly AdminSection[]>([
    { titleKey: 'admin.membersTitle', descriptionKey: 'admin.membersDescription' },
    { titleKey: 'admin.groupsTitle', descriptionKey: 'admin.groupsDescription' },
    { titleKey: 'admin.projectsTitle', descriptionKey: 'admin.projectsDescription' },
    { titleKey: 'admin.auditTitle', descriptionKey: 'admin.auditDescription' },
  ]);
}
