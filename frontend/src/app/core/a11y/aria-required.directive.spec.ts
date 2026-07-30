import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AriaRequiredDirective } from './aria-required.directive';

@Component({
  selector: 'app-host',
  standalone: true,
  imports: [AriaRequiredDirective, ReactiveFormsModule],
  template: `
    <form [formGroup]="form">
      <input id="name" formControlName="name" />
      <input id="note" formControlName="note" />
      <input id="code" formControlName="code" />
    </form>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class HostComponent {
  private readonly formBuilder = inject(FormBuilder);

  readonly form = this.formBuilder.nonNullable.group({
    name: ['', Validators.required],
    note: [''],
    // The rule still holds when the control carries more than one validator.
    code: ['', [Validators.required, Validators.maxLength(5)]],
  });
}

describe('AriaRequiredDirective', () => {
  /**
   * Reactive forms keep their validators in TypeScript, so without this a field
   * that refuses to be empty looks entirely optional to a screen reader.
   */
  it('marks the controls that actually carry the validator', () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('#name')?.getAttribute('aria-required')).toBe('true');
    expect(element.querySelector('#code')?.getAttribute('aria-required')).toBe('true');
    expect(element.querySelector('#note')?.getAttribute('aria-required')).toBeNull();
  });

  /**
   * `aria-required`, never the native attribute: the native one hands validation
   * back to the browser, with its own wording in its own language.
   */
  it('leaves native validation to nobody', () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('#name')?.hasAttribute('required')).toBe(false);
  });
});
