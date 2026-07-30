import { ApplicationRef, EnvironmentProviders, inject, provideAppInitializer } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { filter, first } from 'rxjs';

/**
 * Moves focus to the heading of the screen that was just opened
 * (webflow `05-STAVY-ROZHRANIA.md` §13.1).
 *
 * A single-page application changes the whole screen without changing the
 * document, so a keyboard or screen-reader user is left where they were: usually
 * on the link they followed, in a navigation they have already left behind.
 * Reading from the new heading is what a browser does on a real page load, and
 * this restores it.
 *
 * The **first** navigation is left alone. Nothing has moved yet at that point,
 * and taking focus during a cold load would fight the browser over scroll
 * restoration and any autofocus the landing screen legitimately wants.
 */
export function provideRouteFocus(): EnvironmentProviders {
  return provideAppInitializer(() => {
    const router = inject(Router);
    const applicationRef = inject(ApplicationRef);
    let previousScreen: string | null = null;

    router.events.pipe(filter((event) => event instanceof NavigationEnd)).subscribe((event) => {
      const screen = event.urlAfterRedirects.split('#')[0] ?? '';
      const firstScreen = previousScreen === null;
      // A fragment is a place on the screen, not another screen. Re-announcing
      // the heading would undo exactly what a skip link just did.
      const sameScreen = previousScreen === screen;
      previousScreen = screen;

      if (firstScreen || sameScreen) {
        return;
      }

      // The new screen is written during the change detection that follows this
      // event, so the heading to focus does not exist yet. Waiting for the
      // application to settle is the honest way to say "once it is drawn".
      applicationRef.isStable.pipe(first(Boolean)).subscribe(() => focusHeading());
    });
  });
}

/**
 * The page heading, or the main landmark when a screen has none. Both are given
 * `tabindex="-1"` so they can take focus without joining the tab order — a
 * heading that could be tabbed to would add a stop nobody asked for.
 */
function focusHeading(): void {
  if (typeof document === 'undefined') {
    return;
  }

  const main = document.querySelector('main');
  const target = main?.querySelector('h1') ?? main;

  if (target === null || target === undefined) {
    return;
  }

  if (!target.hasAttribute('tabindex')) {
    target.setAttribute('tabindex', '-1');
  }

  // Without this the browser scrolls the heading into view, undoing the scroll
  // position the router just restored.
  target.focus({ preventScroll: true });
}
