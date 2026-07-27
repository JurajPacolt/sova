import { ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { finalize } from 'rxjs';
import { LanguageSwitcherComponent } from '../../../shared/components/language-switcher/language-switcher.component';
import { ThemeSwitcherComponent } from '../../../shared/components/theme-switcher/theme-switcher.component';
import { AuthSessionService } from '../../auth/auth-session.service';
import { AuthSessionStore } from '../../auth/auth-session.store';
import { TranslatePipe } from '../../i18n/translate.pipe';

@Component({
  selector: 'app-system-shell',
  standalone: true,
  imports: [
    LanguageSwitcherComponent,
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    ThemeSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './system-shell.component.html',
  styleUrl: './system-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SystemShellComponent {
  private readonly auth = inject(AuthSessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  protected readonly session = inject(AuthSessionStore);

  protected readonly loggingOut = signal(false);
  protected readonly logoutError = signal(false);

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
}
