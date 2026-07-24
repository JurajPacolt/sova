import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { map } from 'rxjs';
import { LanguageSwitcherComponent } from '../../../shared/components/language-switcher/language-switcher.component';
import { TranslatePipe } from '../../i18n/translate.pipe';
import { TranslationKey } from '../../i18n/translations';

interface NavigationItem {
  readonly labelKey: TranslationKey;
  readonly path: string;
}

@Component({
  selector: 'app-tenant-shell',
  standalone: true,
  imports: [LanguageSwitcherComponent, RouterLink, RouterLinkActive, RouterOutlet, TranslatePipe],
  templateUrl: './tenant-shell.component.html',
  styleUrl: './tenant-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantShellComponent {
  private readonly route = inject(ActivatedRoute);

  protected readonly sidebarOpen = signal(false);
  protected readonly navigation = signal<readonly NavigationItem[]>([
    { labelKey: 'nav.dashboard', path: 'dashboard' },
    { labelKey: 'nav.projects', path: 'projects' },
    { labelKey: 'nav.administration', path: 'admin' },
  ]);

  protected readonly tenantSlug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('tenantSlug') ?? 'tenant')),
    { initialValue: 'tenant' },
  );

  protected readonly tenantName = computed(() =>
    this.tenantSlug().replaceAll('-', ' ').toUpperCase(),
  );

  protected toggleSidebar(): void {
    this.sidebarOpen.update((isOpen) => !isOpen);
  }

  protected closeSidebar(): void {
    this.sidebarOpen.set(false);
  }
}
