/**
 * Node dependencies
 */
const { execSync } = require( 'child_process' );

/**
 * Read the captured outgoing mail log (oldest first, most recent last).
 *
 * global-setup installs a mu-plugin that appends every wp_mail() call to a
 * bounded missiondp_e2e_mail_log option (the last 10 messages are kept).
 *
 * @return {Array<Object>} Captured wp_mail() argument sets.
 */
function readMailLog() {
  const raw = execSync(
    'npx wp-env run tests-cli -- wp option get missiondp_e2e_mail_log --format=json',
    { encoding: 'utf8', timeout: 20000 }
  );

  const line = raw
    .split( '\n' )
    .map( ( s ) => s.trim() )
    .filter( Boolean )
    .reverse()
    .find( ( s ) => s.startsWith( '[' ) || s.startsWith( '{' ) );

  if ( ! line ) {
    return [];
  }

  const parsed = JSON.parse( line );
  return Array.isArray( parsed ) ? parsed : Object.values( parsed );
}

/**
 * Find the most recent captured mail matching a recipient and/or subject.
 *
 * @param {Object} [filters]         Match criteria (all optional).
 * @param {string} [filters.to]      Recipient address (substring match).
 * @param {string} [filters.subject] Subject text (substring match).
 * @return {Object|null} The wp_mail() args of the latest match, or null.
 */
function findLatestMail( { to, subject } = {} ) {
  const matches = readMailLog().filter( ( mail ) => {
    const recipients = ( Array.isArray( mail.to ) ? mail.to : [ mail.to ] ).map(
      ( r ) => String( r || '' ).toLowerCase()
    );
    const toOk =
      ! to || recipients.some( ( r ) => r.includes( to.toLowerCase() ) );
    const subjectOk =
      ! subject ||
      String( mail.subject || '' )
        .toLowerCase()
        .includes( subject.toLowerCase() );
    return toOk && subjectOk;
  } );

  return matches.pop() || null;
}

/**
 * Read the verification code from the latest OTP email sent to an address.
 *
 * The code is located structurally: strip the HTML and take the standalone
 * six-digit number, rather than coupling to the email template's styling.
 *
 * @param {string} email Recipient address the code was sent to.
 * @return {string} The 6-digit code.
 */
function readOtpCode( email ) {
  const mail = findLatestMail( {
    to: email,
    subject: 'Your verification code',
  } );

  if ( ! mail ) {
    throw new Error( `No verification email captured for ${ email }.` );
  }

  const text = String( mail.message || '' ).replace( /<[^>]*>/g, ' ' );
  const match = text.match( /(?<!\d)(\d{6})(?!\d)/ );

  if ( ! match ) {
    throw new Error( 'Could not read an OTP code from the captured email.' );
  }

  return match[ 1 ];
}

module.exports = { readMailLog, findLatestMail, readOtpCode };
