import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslationKey } from '../../../../core/i18n/translations';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

interface AdminSection {
  readonly descriptionKey: TranslationKey;
  readonly titleKey: TranslationKey;
  readonly path: readonly string[];
  /**
   * Exactly what the target route's guard asks for. Holding none of these means
   * the card is not shown — offering a door that opens onto the 403 screen is a
   * promise the screen cannot keep.
   */
  readonly permissions?: readonly string[];
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
  private readonly tenantStore = inject(TenantStore);

  private readonly allSections = signal<readonly AdminSection[]>([
    {
      titleKey: 'admin.settingsTitle',
      descriptionKey: 'admin.settingsDescription',
      path: ['settings'],
      permissions: ['tenant.settings.manage'],
    },
    {
      titleKey: 'admin.membersTitle',
      descriptionKey: 'admin.membersDescription',
      path: ['members'],
      permissions: ['tenant.members.view', 'tenant.members.manage'],
    },
    {
      titleKey: 'admin.rolesTitle',
      descriptionKey: 'admin.rolesDescription',
      path: ['roles'],
      permissions: ['tenant.roles.view', 'tenant.roles.manage'],
    },
    {
      titleKey: 'admin.groupsTitle',
      descriptionKey: 'admin.groupsDescription',
      path: ['workgroups'],
      permissions: ['tenant.workgroups.manage'],
    },
    {
      // Projects live outside the administration area, so the card leaves it.
      // Every member may see the list; what they may do there is decided per
      // project, which is not a tenant permission this screen could read.
      titleKey: 'admin.projectsTitle',
      descriptionKey: 'admin.projectsDescription',
      path: ['..', 'projects'],
    },
    {
      titleKey: 'admin.auditTitle',
      descriptionKey: 'admin.auditDescription',
      path: ['audit'],
      permissions: ['tenant.audit.view'],
    },
  ]);

  protected readonly sections = computed(() =>
    this.allSections().filter(
      (section) =>
        section.permissions === undefined || this.tenantStore.hasAnyPermission(section.permissions),
    ),
  );
}
