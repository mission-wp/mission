/**
 * Mock for @wordpress/interactivity.
 *
 * Exposes a mutable `_mockContext` that tests can swap out per-case, and
 * captures store definitions in `_mockStores` (keyed by namespace) so tests
 * can call actions/callbacks.
 *
 * Like the real API, store() deep-merges repeated calls for the same
 * namespace (the signup modal splits its store across view.js and gift.js)
 * and returns the merged definition, so every module's `state` points at one
 * object. Accessors are copied as property descriptors at every depth so
 * merging never evaluates getters. The one-argument lookup form
 * `store( namespace )` returns the existing definition without creating one.
 */

/**
 * Deep-merge a store definition section, preserving property descriptors.
 *
 * Plain objects merge recursively; everything else (accessors, arrays,
 * primitives, functions) is defined over the target as-is.
 *
 * @param {Object} target Merged definition being built.
 * @param {Object} source Newly registered definition.
 */
function mergeDefinition( target, source ) {
  for ( const key of Object.keys( source ) ) {
    const sourceDesc = Object.getOwnPropertyDescriptor( source, key );
    const sourceIsPlainObject =
      'value' in sourceDesc &&
      sourceDesc.value !== null &&
      typeof sourceDesc.value === 'object' &&
      ! Array.isArray( sourceDesc.value );

    const targetDesc = Object.getOwnPropertyDescriptor( target, key );
    const targetIsPlainObject =
      targetDesc &&
      'value' in targetDesc &&
      targetDesc.value !== null &&
      typeof targetDesc.value === 'object' &&
      ! Array.isArray( targetDesc.value );

    if ( sourceIsPlainObject && ( targetIsPlainObject || ! targetDesc ) ) {
      if ( ! targetDesc ) {
        target[ key ] = {};
      }
      mergeDefinition( target[ key ], sourceDesc.value );
    } else {
      Object.defineProperty( target, key, sourceDesc );
    }
  }
}

const interactivity = {
  /** The context object returned by getContext(). Tests mutate this directly. */
  _mockContext: {},

  /** Merged store definitions, keyed by namespace. */
  _mockStores: {},

  store( namespace, definition ) {
    const stores = interactivity._mockStores;

    // Lookup form: store( namespace ) reads without registering.
    if ( ! definition ) {
      return stores[ namespace ];
    }

    const target = ( stores[ namespace ] = stores[ namespace ] || {} );
    mergeDefinition( target, definition );
    return target;
  },

  getContext() {
    return interactivity._mockContext;
  },

  getElement() {
    return { ref: null };
  },

  withScope( fn ) {
    return fn;
  },
};

module.exports = interactivity;
