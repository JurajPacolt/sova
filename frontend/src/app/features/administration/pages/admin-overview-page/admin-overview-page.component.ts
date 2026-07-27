import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

interface AdminSection {
  readonly descriptionKey: TranslationKey;
  readonly titleKey: TranslationKey;
  readonly path: string | null;
}

@Component({
  selector: 'app-admin-overview-page',
  standalone: true,
  imports: [PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './admin-overview-page.component.html',
  styleUrl: './admin-overview-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdminOverviewPageComponent {
  protected readonly sections = signal<readonly AdminSection[]>([
    { titleKey: 'admin.membersTitle', descriptionKey: 'admin.membersDescription', path: 'members' },
    { titleKey: 'admin.rolesTitle', descriptionKey: 'admin.rolesDescription', path: 'roles' },
    {
      titleKey: 'admin.groupsTitle',
      descriptionKey: 'admin.groupsDescription',
      path: 'workgroups',
    },
    { titleKey: 'admin.projectsTitle', descriptionKey: 'admin.projectsDescription', path: null },
    { titleKey: 'admin.auditTitle', descriptionKey: 'admin.auditDescription', path: 'audit' },
  ]);
}
