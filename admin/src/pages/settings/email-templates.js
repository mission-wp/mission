/**
 * Email type definitions for the settings email editor.
 *
 * Each type maps to a key in settings.emails (PHP SettingsService).
 * Preview HTML is fetched from the API (PHP is the source of truth for templates).
 */
import { __ } from '@wordpress/i18n';

/**
 * Merge tags available on all email types.
 */
const GLOBAL_TAGS = [
  {
    tag: '{donor_name}',
    label: __( 'Donor name', 'missionwp-donation-platform' ),
  },
  {
    tag: '{organization}',
    label: __( 'Organization', 'missionwp-donation-platform' ),
  },
];

export const DONATION_EMAILS = [
  {
    id: 'donation_receipt',
    name: __( 'One-time donation receipt', 'missionwp-donation-platform' ),
    desc: __(
      'Sent after a completed one-time transaction',
      'missionwp-donation-platform'
    ),
    iconType: 'donation',
    defaultSubject: 'Thank you for your {amount} donation',
    mergeTags: [
      ...GLOBAL_TAGS,
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      {
        tag: '{campaign}',
        label: __( 'Campaign', 'missionwp-donation-platform' ),
      },
      { tag: '{date}', label: __( 'Date', 'missionwp-donation-platform' ) },
      {
        tag: '{receipt_id}',
        label: __( 'Receipt ID', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'subscription_activated',
    name: __( 'Subscription activated', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a recurring donation goes active',
      'missionwp-donation-platform'
    ),
    iconType: 'donation',
    defaultSubject: 'Thank you for your {amount} {frequency} donation',
    mergeTags: [
      ...GLOBAL_TAGS,
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      {
        tag: '{frequency}',
        label: __( 'Frequency', 'missionwp-donation-platform' ),
      },
      {
        tag: '{next_renewal_date}',
        label: __( 'Next renewal', 'missionwp-donation-platform' ),
      },
      {
        tag: '{campaign}',
        label: __( 'Campaign', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'renewal_receipt',
    name: __( 'Renewal receipt', 'missionwp-donation-platform' ),
    desc: __(
      'Sent each time a recurring payment is processed',
      'missionwp-donation-platform'
    ),
    iconType: 'donation',
    defaultSubject: 'Thank you for your {frequency} gift of {amount}',
    mergeTags: [
      ...GLOBAL_TAGS,
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      { tag: '{date}', label: __( 'Date', 'missionwp-donation-platform' ) },
      {
        tag: '{receipt_id}',
        label: __( 'Receipt ID', 'missionwp-donation-platform' ),
      },
      {
        tag: '{frequency}',
        label: __( 'Frequency', 'missionwp-donation-platform' ),
      },
      {
        tag: '{next_renewal_date}',
        label: __( 'Next renewal', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'payment_failed',
    name: __( 'Payment failed', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a renewal charge fails',
      'missionwp-donation-platform'
    ),
    iconType: 'alert',
    defaultSubject:
      'Action needed: Update your payment for your recurring donation',
    mergeTags: [
      ...GLOBAL_TAGS,
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      {
        tag: '{frequency}',
        label: __( 'Frequency', 'missionwp-donation-platform' ),
      },
      {
        tag: '{donor_dashboard}',
        label: __( 'Donor dashboard URL', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'subscription_cancelled',
    name: __( 'Subscription cancelled', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donor cancels their subscription',
      'missionwp-donation-platform'
    ),
    iconType: 'alert',
    defaultSubject: 'Your recurring donation has ended',
    mergeTags: [
      ...GLOBAL_TAGS,
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      {
        tag: '{frequency}',
        label: __( 'Frequency', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'donor_note',
    name: __( 'Donor note', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donor-visible note is added to a transaction',
      'missionwp-donation-platform'
    ),
    iconType: 'donation',
    defaultSubject: 'A note about your donation',
    mergeTags: [
      ...GLOBAL_TAGS,
      {
        tag: '{note_content}',
        label: __( 'Note content', 'missionwp-donation-platform' ),
      },
      { tag: '{amount}', label: __( 'Amount', 'missionwp-donation-platform' ) },
      {
        tag: '{receipt_id}',
        label: __( 'Receipt ID', 'missionwp-donation-platform' ),
      },
    ],
  },
  {
    id: 'tribute_notification',
    name: __( 'Tribute notification', 'missionwp-donation-platform' ),
    desc: __(
      'Sent to the recipient of a dedication',
      'missionwp-donation-platform'
    ),
    iconType: 'donation',
    defaultSubject:
      'A donation has been made {tribute_type_label} {honoree_name}',
    mergeTags: [
      ...GLOBAL_TAGS,
      {
        tag: '{honoree_name}',
        label: __( 'Honoree name', 'missionwp-donation-platform' ),
      },
      {
        tag: '{tribute_type_label}',
        label: __( 'Tribute type', 'missionwp-donation-platform' ),
      },
      {
        tag: '{message}',
        label: __( 'Personal message', 'missionwp-donation-platform' ),
      },
    ],
  },
];

export const ADMIN_EMAILS = [
  {
    id: 'admin_new_donation',
    name: __( 'New donation', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a one-time or first recurring donation is received',
      'missionwp-donation-platform'
    ),
    iconType: 'admin',
  },
  {
    id: 'admin_recurring_renewal',
    name: __( 'Recurring renewal', 'missionwp-donation-platform' ),
    desc: __(
      'Sent each time a recurring donation renews',
      'missionwp-donation-platform'
    ),
    iconType: 'admin',
  },
  {
    id: 'admin_refund',
    name: __( 'Refund processed', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donation is fully or partially refunded',
      'missionwp-donation-platform'
    ),
    iconType: 'alert',
  },
  {
    id: 'admin_payment_failed',
    name: __( 'Failed payment', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a recurring charge is declined',
      'missionwp-donation-platform'
    ),
    iconType: 'alert',
  },
  {
    id: 'admin_subscription_cancelled',
    name: __( 'Subscription cancelled', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donor cancels their recurring donation',
      'missionwp-donation-platform'
    ),
    iconType: 'admin',
  },
  {
    id: 'admin_milestone',
    name: __( 'Campaign milestone', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a campaign reaches 50%, 75%, or 100% of its goal',
      'missionwp-donation-platform'
    ),
    iconType: 'admin',
  },
  {
    id: 'admin_mail_dedication',
    name: __( 'Mail dedication pending', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donation includes a dedication that needs to be mailed',
      'missionwp-donation-platform'
    ),
    iconType: 'admin',
  },
];

export const ACCOUNT_EMAILS = [
  {
    id: 'account_activation',
    name: __( 'Account activation', 'missionwp-donation-platform' ),
    desc: __(
      'Email verification for the donor dashboard',
      'missionwp-donation-platform'
    ),
    iconType: 'account',
    defaultSubject: 'Verify your email to activate your donor account',
    mergeTags: [ ...GLOBAL_TAGS ],
  },
  {
    id: 'password_reset',
    name: __( 'Password reset', 'missionwp-donation-platform' ),
    desc: __(
      'Sent when a donor requests a new password',
      'missionwp-donation-platform'
    ),
    iconType: 'account',
    defaultSubject: 'Reset your password',
    mergeTags: [ ...GLOBAL_TAGS ],
  },
  {
    id: 'email_change_verification',
    name: __( 'Email change verification', 'missionwp-donation-platform' ),
    desc: __(
      'Sent to the new address for confirmation',
      'missionwp-donation-platform'
    ),
    iconType: 'account',
    defaultSubject: 'Verify your new email address',
    mergeTags: [
      ...GLOBAL_TAGS,
      {
        tag: '{new_email}',
        label: __( 'New email', 'missionwp-donation-platform' ),
      },
    ],
  },
];

/**
 * All email types flattened for lookup.
 */
export const ALL_EMAILS = [
  ...DONATION_EMAILS,
  ...ACCOUNT_EMAILS,
  ...ADMIN_EMAILS,
];

/**
 * Highlight merge tags in HTML for the preview display.
 * Replaces {tag_name} with styled spans.
 *
 * @param {string} html Raw HTML with merge tags.
 * @return {string} HTML with merge tags wrapped in styled spans.
 */
export function highlightMergeTags( html ) {
  // Split HTML into tags and text segments, only highlight merge tags in text.
  return html.replace( /(<[^>]*>)|(\{[a-z_]+\})/g, ( match, tag, mergeTag ) => {
    if ( tag ) {
      return tag; // Inside an HTML tag — leave it alone.
    }
    return `<span style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.8em; color: #2fa36b; background: #e2f4eb; padding: 1px 6px; border-radius: 4px;">${ mergeTag }</span>`;
  } );
}

/**
 * SVG icons by email type — used by EmailsPanel and EmailEditor.
 */
export const EMAIL_ICONS = {
  donation_receipt: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  ),
  subscription_activated: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="20 12 20 22 4 22 4 12" />
      <rect x="2" y="7" width="20" height="5" rx="1" />
      <line x1="12" y1="22" x2="12" y2="7" />
      <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
      <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
    </svg>
  ),
  renewal_receipt: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="23 4 23 10 17 10" />
      <polyline points="1 20 1 14 7 14" />
      <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
    </svg>
  ),
  payment_failed: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="12" cy="12" r="10" />
      <line x1="12" y1="8" x2="12" y2="12" />
      <line x1="12" y1="16" x2="12.01" y2="16" />
    </svg>
  ),
  subscription_cancelled: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="12" cy="12" r="10" />
      <line x1="15" y1="9" x2="9" y2="15" />
      <line x1="9" y1="9" x2="15" y2="15" />
    </svg>
  ),
  account_activation: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
      <polyline points="22 4 12 14.01 9 11.01" />
    </svg>
  ),
  password_reset: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
      <path d="M7 11V7a5 5 0 0 1 10 0v4" />
    </svg>
  ),
  email_change_verification: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="2" y="4" width="20" height="16" rx="3" />
      <polyline points="22 7 12 14 2 7" />
    </svg>
  ),
  donor_note: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
  ),
  tribute_notification: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
    </svg>
  ),
  admin_new_donation: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z" />
    </svg>
  ),
  admin_recurring_renewal: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="23 4 23 10 17 10" />
      <polyline points="1 20 1 14 7 14" />
      <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
    </svg>
  ),
  admin_refund: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="1 4 1 10 7 10" />
      <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
    </svg>
  ),
  admin_payment_failed: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="12" cy="12" r="10" />
      <line x1="12" y1="8" x2="12" y2="12" />
      <line x1="12" y1="16" x2="12.01" y2="16" />
    </svg>
  ),
  admin_subscription_cancelled: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <circle cx="12" cy="12" r="10" />
      <line x1="15" y1="9" x2="9" y2="15" />
      <line x1="9" y1="9" x2="15" y2="15" />
    </svg>
  ),
  admin_milestone: (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z" />
      <line x1="4" y1="22" x2="4" y2="15" />
    </svg>
  ),
  admin_mail_dedication: (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
      <path d="M9 8.5h2.793l.853.854A.5.5 0 0 0 13 9.5h1a.5.5 0 0 0 .5-.5V8a.5.5 0 0 0-.5-.5H9z" />
      <path d="M12 3H4a4 4 0 0 0-4 4v6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V7a4 4 0 0 0-4-4M8 7a4 4 0 0 0-1.354-3H12a3 3 0 0 1 3 3v6H8zm-3.415.157C4.42 7.087 4.218 7 4 7s-.42.086-.585.157C3.164 7.264 3 7.334 3 7a1 1 0 0 1 2 0c0 .334-.164.264-.415.157" />
    </svg>
  ),
};
