import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import {
  ActivatedRoute,
  Router,
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';
import { map, timer } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { AuthSessionService } from '../../auth/auth-session.service';
import { AuthSessionStore } from '../../auth/auth-session.store';
import { ImpersonationSessionService } from '../../auth/impersonation-session.service';
import { LanguageSwitcherComponent } from '../../../shared/components/language-switcher/language-switcher.component';
import { ThemeSwitcherComponent } from '../../../shared/components/theme-switcher/theme-switcher.component';
import { TranslatePipe } from '../../i18n/translate.pipe';
import { TranslationKey } from '../../i18n/translations';
import { TenantStore } from '../../tenancy/tenant.store';

interface NavigationItem {
  readonly labelKey: TranslationKey;
  readonly path: string;
}

@Component({
  selector: 'app-tenant-shell',
  standalone: true,
  imports: [
    LanguageSwitcherComponent,
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    ThemeSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './tenant-shell.component.html',
  styleUrl: './tenant-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantShellComponent {
  private readonly auth = inject(AuthSessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly tenantStore = inject(TenantStore);
  private readonly impersonationSession = inject(ImpersonationSessionService);

  protected readonly session = inject(AuthSessionStore);
  protected readonly sidebarOpen = signal(false);
  protected readonly loggingOut = signal(false);
  protected readonly logoutError = signal(false);
  protected readonly endingImpersonation = signal(false);
  protected readonly impersonationEndError = signal(false);
  protected readonly navigation = signal<readonly NavigationItem[]>([
    { labelKey: 'nav.dashboard', path: 'dashboard' },
    { labelKey: 'nav.projects', path: 'projects' },
    { labelKey: 'nav.administration', path: 'admin' },
  ]);

  protected readonly tenantSlug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('tenantSlug') ?? 'tenant')),
    { initialValue: 'tenant' },
  );

  protected readonly tenantName = computed(
    () =>
      this.tenantStore.activeTenant()?.name ?? this.tenantSlug().replaceAll('-', ' ').toUpperCase(),
  );
  private readonly clock = toSignal(timer(0, 1_000).pipe(map(() => Date.now())), {
    initialValue: Date.now(),
  });
  protected readonly impersonationRemaining = computed(() => {
    const impersonation = this.session.impersonation();

    if (impersonation === null) {
      return 0;
    }

    return Math.max(0, Math.ceil((Date.parse(impersonation.expires_at) - this.clock()) / 1_000));
  });

  protected toggleSidebar(): void {
    this.sidebarOpen.update((isOpen) => !isOpen);
  }

  protected closeSidebar(): void {
    this.sidebarOpen.set(false);
  }

  protected logout(): void {
    if (this.loggingOut()) {
      return;
    }

    this.logoutError.set(false);
    this.loggingOut.set(true);
    this.auth
      .logout()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loggingOut.set(false)),
      )
      .subscribe({
        next: () => {
          void this.router.navigate(['/login']);
        },
        error: () => this.logoutError.set(true),
      });
  }

  protected endImpersonation(): void {
    if (this.endingImpersonation()) {
      return;
    }

    this.impersonationEndError.set(false);
    this.endingImpersonation.set(true);
    this.impersonationSession
      .end()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.endingImpersonation.set(false)),
      )
      .subscribe({
        next: () => {
          void this.router.navigate(['/system/tenants']);
        },
        error: () => this.impersonationEndError.set(true),
      });
  }

  protected remainingTime(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
  }
}
