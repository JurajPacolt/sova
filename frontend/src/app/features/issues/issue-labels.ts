import { IssuePriority } from '../../core/api/api.models';
import { TranslationKey } from '../../core/i18n/translations';

/**
 * The catalogue key for a priority.
 *
 * It lives here rather than on a page because the same value is shown in the
 * create form, in the search results and on the detail, and it was only
 * translated in the first of the three — the other two printed the raw enum,
 * so one screen said "Normal" and the next said `NORMAL`.
 */
export function issuePriorityKey(priority: IssuePriority): TranslationKey {
  switch (priority) {
    case 'LOW':
      return 'issue.priority.low';
    case 'HIGH':
      return 'issue.priority.high';
    case 'CRITICAL':
      return 'issue.priority.critical';
    default:
      return 'issue.priority.normal';
  }
}
