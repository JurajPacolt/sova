import { Directive, ElementRef, inject } from '@angular/core';

/**
 * Makes a skip link actually skip (webflow `05-STAVY-ROZHRANIA.md` §13).
 *
 * Left to the browser, a fragment link does two things this application cannot
 * use. It does not move focus, because the router claims the URL change first
 * and only scrolls — so the next Tab goes back to the navigation the link was
 * supposed to skip. And the URL change is a navigation like any other: guards
 * re-run, and a tenant route that re-resolves can land somewhere else entirely.
 *
 * So the fragment stays out of the URL and this moves focus itself. The `href`
 * is kept: it names the target, and without JavaScript the browser still does
 * the old thing.
 */
@Directive({
  selector: 'a[appSkipLink]',
  standalone: true,
  host: { '(click)': 'moveFocus($event)' },
})
export class SkipLinkDirective {
  private readonly element = inject<ElementRef<HTMLAnchorElement>>(ElementRef);

  protected moveFocus(event: Event): void {
    const href = this.element.nativeElement.getAttribute('href') ?? '';

    if (!href.startsWith('#')) {
      return;
    }

    // The target carries `tabindex="-1"` so it can take focus without being a
    // stop on the way through the page.
    const target = document.getElementById(href.slice(1));

    if (target === null) {
      return;
    }

    event.preventDefault();
    target.focus();
    target.scrollIntoView();
  }
}
