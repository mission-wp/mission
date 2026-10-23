/**
 * Pure helpers for the donation form's frequency settings.
 *
 * The block stores the choice as separate attributes (oneTimeEnabled,
 * recurringEnabled, recurringFrequencies, recurringDefault); the settings
 * modal edits it as one ordered list of frequency IDs.
 */

const ONE_TIME = 'one_time';

/**
 * Build the unified frequency list from resolved settings.
 *
 * @param {Object} settings Resolved settings (block attributes merged over defaults).
 * @return {string[]} Frequency IDs, with one_time first when it is offered.
 */
export function buildFrequencyArray( settings ) {
  const recurring = settings.recurringFrequencies || [];
  const recurringEnabled = settings.recurringEnabled ?? recurring.length > 0;

  if ( ! recurringEnabled || recurring.length === 0 ) {
    return [ ONE_TIME ];
  }

  const oneTimeEnabled = settings.oneTimeEnabled ?? true;
  return oneTimeEnabled ? [ ONE_TIME, ...recurring ] : [ ...recurring ];
}

/**
 * Pick a default frequency that is actually offered.
 *
 * @param {string[]} frequencies Selected frequency IDs.
 * @param {string}   current     Current default frequency.
 * @return {string} A member of frequencies.
 */
export function resolveDefaultFrequency( frequencies, current ) {
  for ( const candidate of [ current, ONE_TIME, 'monthly' ] ) {
    if ( frequencies.includes( candidate ) ) {
      return candidate;
    }
  }
  return frequencies[ 0 ] || ONE_TIME;
}

/**
 * Split the unified list back into block attributes.
 *
 * @param {string[]} frequencies      Selected frequency IDs.
 * @param {string}   defaultFrequency Preferred default frequency.
 * @return {Object} oneTimeEnabled, recurringEnabled, recurringFrequencies and recurringDefault.
 */
export function frequencyAttributes( frequencies, defaultFrequency ) {
  const recurringFrequencies = frequencies.filter( ( f ) => f !== ONE_TIME );
  const recurringEnabled = recurringFrequencies.length > 0;

  return {
    // A form always offers something, so one-time stays on without recurring.
    oneTimeEnabled: frequencies.includes( ONE_TIME ) || ! recurringEnabled,
    recurringEnabled,
    recurringFrequencies,
    recurringDefault: resolveDefaultFrequency( frequencies, defaultFrequency ),
  };
}
