import { Directive, inject } from '@angular/core';
import { NgControl, Validators } from '@angular/forms';

/**
 * Tells assistive technology which reactive-forms controls must be filled in
 * (webflow `05-STAVY-ROZHRANIA.md` §13).
 *
 * Reactive forms keep their validators in TypeScript and put nothing in the DOM,
 * so a field that refuses to be empty looks entirely optional to a screen
 * reader. This reads the validator the control actually carries, which means it
 * cannot drift from the rule: there is no second list of "required fields" to
 * keep in step.
 *
 * It reflects `aria-required`, never the native `required` attribute. The native
 * one hands validation back to the browser — with its own bubbles, its own
 * wording and its own language — and this application answers in six.
 */
@Directive({
  selector: '[formControlName],[formControl]',
  standalone: true,
  host: { '[attr.aria-required]': 'ariaRequired' },
})
export class AriaRequiredDirective {
  private readonly control = inject(NgControl, { self: true, optional: true });

  protected get ariaRequired(): 'true' | null {
    const control = this.control?.control ?? null;

    return control !== null && control.hasValidator(Validators.required) ? 'true' : null;
  }
}
