import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../../core/i18n/translations';
import { LanguageSwitcherComponent } from '../../../../shared/components/language-switcher/language-switcher.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

interface TenantSummary {
  readonly nameKey: TranslationKey;
  readonly role: string;
  readonly slug: string;
  readonly status: 'active' | 'suspended';
}

@Component({
  selector: 'app-tenant-selection-page',
  standalone: true,
  imports: [LanguageSwitcherComponent, PageHeaderComponent, RouterLink, TranslatePipe],
  templateUrl: './tenant-selection-page.component.html',
  styleUrl: './tenant-selection-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSelectionPageComponent {
  protected readonly tenants = signal<readonly TenantSummary[]>([
    { nameKey: 'tenant.demoName', role: 'TENANT_OWNER', slug: 'demo', status: 'active' },
    {
      nameKey: 'tenant.suspendedDemoName',
      role: 'MEMBER',
      slug: 'paused',
      status: 'suspended',
    },
  ]);

  protected readonly activeTenants = computed(() =>
    this.tenants().filter((tenant) => tenant.status === 'active'),
  );
}
