/**
 * Donor Dashboard — Toast notification utility.
 *
 * Shows a brief, auto-dismissing notification for user actions.
 * Works with the Interactivity API by setting context state that
 * drives the toast markup in the PHP template.
 */

let toastTimer = null;
let dismissTimer = null;

/**
 * Show a toast notification.
 *
 * @param {Object} ctx     The Interactivity API context.
 * @param {string} message Message to display.
 * @param {string} type    'success' or 'error'.
 */
export function showToast( ctx, message, type = 'success' ) {
  clearTimeout( toastTimer );
  clearTimeout( dismissTimer );

  ctx.toast.message = message;
  ctx.toast.type = type;
  ctx.toast.dismissing = false;
  ctx.toast.visible = true;

  toastTimer = setTimeout( () => {
    ctx.toast.dismissing = true;

    dismissTimer = setTimeout( () => {
      ctx.toast.visible = false;
      ctx.toast.dismissing = false;
    }, 300 );
  }, 3500 );
}
