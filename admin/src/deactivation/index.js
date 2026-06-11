/**
 * Deactivation survey modal on plugins.php.
 *
 * All user-facing strings are rendered server-side in DeactivationSurvey.php;
 * this script is purely behavioral, so it needs no i18n.
 */
import './style.scss';

const SLUG = 'mission-donation-platform';

function init() {
  const dialog = document.getElementById( 'mission-deactivation-survey' );
  const link =
    document.getElementById( `deactivate-${ SLUG }` ) ||
    document.querySelector( `tr[data-slug="${ SLUG }"] .deactivate a` );

  if ( ! dialog || ! link || ! window.missiondpDeactivation ) {
    return;
  }

  const form = dialog.querySelector( 'form' );
  const skipLink = dialog.querySelector( '.mission-ds__skip' );
  const cancelButton = dialog.querySelector( '.mission-ds__cancel' );
  const submitButton = dialog.querySelector( '.mission-ds__submit' );
  const followup = dialog.querySelector( '.mission-ds__followup' );
  const textarea = followup.querySelector( 'textarea' );
  const helps = followup.querySelectorAll( '.mission-ds__help' );
  const submitLabel = submitButton.textContent;

  link.addEventListener( 'click', ( event ) => {
    event.preventDefault();
    skipLink.href = link.href;
    dialog.showModal();
  } );

  form.addEventListener( 'submit', ( event ) => event.preventDefault() );

  form.addEventListener( 'change', ( event ) => {
    if ( 'mission_ds_reason' !== event.target.name ) {
      return;
    }

    submitButton.disabled = false;
    textarea.value = '';

    const prompt = event.target.dataset.followup;
    followup.hidden = ! prompt;

    if ( prompt ) {
      textarea.placeholder = prompt;
      helps.forEach( ( help ) => {
        help.hidden = help.dataset.reason !== event.target.value;
      } );
      // Move the field directly under the chosen reason so it's seen.
      event.target.closest( 'label' ).after( followup );
      textarea.focus();
    }
  } );

  cancelButton.addEventListener( 'click', () => dialog.close() );

  dialog.addEventListener( 'close', () => {
    form.reset();
    followup.hidden = true;
    submitButton.disabled = true;
    submitButton.textContent = submitLabel;
    cancelButton.disabled = false;
  } );

  submitButton.addEventListener( 'click', () => {
    const checked = form.querySelector(
      'input[name="mission_ds_reason"]:checked'
    );

    if ( ! checked ) {
      return;
    }

    // Reasons marked no-send (temporary deactivation) carry no useful
    // signal, so deactivate immediately without calling the API.
    if ( checked.dataset.noSend ) {
      window.location.assign( link.href );
      return;
    }

    submitButton.disabled = true;
    cancelButton.disabled = true;
    submitButton.textContent = submitButton.dataset.busyLabel;

    const { restUrl, restNonce } = window.missiondpDeactivation;

    // keepalive + finally: deactivation proceeds even if the request fails.
    fetch( restUrl, {
      method: 'POST',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': restNonce,
      },
      body: JSON.stringify( {
        reason: checked.value,
        feedback: followup.hidden ? '' : textarea.value.trim(),
      } ),
    } )
      .catch( () => {} )
      .finally( () => window.location.assign( link.href ) );
  } );
}

if ( 'loading' === document.readyState ) {
  document.addEventListener( 'DOMContentLoaded', init );
} else {
  init();
}
