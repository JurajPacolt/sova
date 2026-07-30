import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { TenantStore } from '../../../core/tenancy/tenant.store';

/**
 * The whole-page `403` (webflow `05-STAVY-ROZHRANIA.md` §5).
 *
 * A route the caller may not open used to redirect quietly to the dashboard,
 * which reads as a broken link: the page they asked for is simply not the page
 * they got. Saying "you do not have access to this" is both true and something
 * they can act on — ask an administrator, or switch to an account that does.
 *
 * It says nothing about what is behind the door. Which projects, members or
 * audit entries exist is exactly what the permission was withholding.
 */
@Component({
  selector: 'app-forbidden',
  standalone: true,
  imports: [RouterLink, TranslatePipe],
  templateUrl: './forbidden.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForbiddenComponent {
  private readonly tenantStore = inject(TenantStore);

  /** Somewhere to go that certainly exists: every member has a dashboard. */
  protected readonly home = computed<readonly string[]>(() => {
    const tenant = this.tenantStore.activeTenant();

    return tenant === null ? ['/select-tenant'] : ['/t', tenant.slug, 'dashboards'];
  });
}
