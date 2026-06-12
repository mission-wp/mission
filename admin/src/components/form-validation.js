/**
 * Shared form validation helpers for the admin drawers.
 */

export const ERROR_COLOR = '#dc2626';

export const errorStyle = {
  borderColor: ERROR_COLOR,
  boxShadow: '0 0 0 1px ' + ERROR_COLOR,
};

export const errorHintStyle = {
  margin: '4px 0 0',
  fontSize: '13px',
  color: ERROR_COLOR,
};

/**
 * Basic email shape check for client-side validation.
 *
 * @param {string} email Email address.
 * @return {boolean} Whether the value looks like an email.
 */
export function isValidEmail( email ) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

/**
 * Field label with a red required asterisk.
 *
 * @param {Object} props
 * @param {string} props.text Label text.
 * @return {JSX.Element} The label.
 */
export function RequiredLabel( { text } ) {
  return (
    <>
      { text }
      <span style={ { color: ERROR_COLOR, marginLeft: '4px' } }>*</span>
    </>
  );
}
