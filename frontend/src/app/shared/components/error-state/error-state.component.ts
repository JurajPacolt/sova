import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { ApiError, describeApiError } from '../../../core/errors/api-error';
import { TranslatePipe } from '../../../core/i18n/translate.pipe';
import { TranslationKey } from '../../../core/i18n/translations';

/**
 * One failed request, said in one place.
 *
 * It takes the error itself rather than a message, so every screen tells the
 * same story about the same status: a refusal reads differently from a dropped
 * connection, and a server fault carries the correlation identifier — the only
 * thing that lets somebody's report be found in the logs afterwards.
 *
 * `Try again` appears only where repeating the request could plausibly work
 * (a network drop, a rate limit, a server fault). Offering it after a `403`
 * would invite somebody to hammer a door that is not going to open.
 *
 * The alert is an `alert` region and says what happened in words: colour is
 * never the only carrier (webflow §8.3).
 */
@Component({
  selector: 'app-error-state',
  standalone: true,
  imports: [TranslatePipe],
  templateUrl: './error-state.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ErrorStateComponent {
  readonly error = input.required<unknown>();

  /** Overrides the status-based wording where a screen knows something better. */
  readonly messageKey = input<TranslationKey | null>(null);

  /** Suppresses the button on screens that have no way to repeat the request. */
  readonly retryable = input(true);

  readonly retry = output<void>();

  protected readonly described = computed<ApiError>(() => describeApiError(this.error()));

  protected readonly text = computed<TranslationKey>(
    () => this.messageKey() ?? this.described().messageKey,
  );

  protected readonly canRetry = computed(() => this.retryable() && this.described().retryable);

  /** A server fault is the case where the identifier is worth the space. */
  protected readonly correlationId = computed(() =>
    this.described().status >= 500 ? this.described().requestId : null,
  );

  protected readonly retryAfter = computed(() => this.described().retryAfterSeconds);
}
