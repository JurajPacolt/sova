import { AfterViewInit, Directive, ElementRef, inject } from '@angular/core';

/**
 * Moves focus to an error summary the moment it appears after a failed submit
 * (webflow `05-STAVY-ROZHRANIA.md` §8.3).
 *
 * A form that refuses to save while the reason sits somewhere above appears to
 * do nothing — for somebody reading with a screen reader, quite literally.
 *
 * It fires **on appearance**, which is why it belongs on an element the template
 * only renders while there is something to say: the same rules (§13.1) forbid
 * chasing focus while somebody types, and an error that merely rewords itself
 * leaves the element in place and takes nothing.
 */
@Directive({
  selector: '[appFocusSummary]',
  standalone: true,
  // Focusable, but never a stop on the way through the form.
  host: { tabindex: '-1' },
})
export class FocusSummaryDirective implements AfterViewInit {
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  ngAfterViewInit(): void {
    // The summary sits in the form somebody is already looking at, so the page
    // does not jump; only the reading position moves.
    this.element.nativeElement.focus({ preventScroll: true });
  }
}
