/**
 * Mock for @wordpress/interactivity.
 *
 * Exposes a mutable `_mockContext` that tests can swap out per-case,
 * and captures the store definition so tests can call actions/callbacks.
 *
 * Like the real API, store() merges repeated calls for the same namespace
 * (the signup modal splits its store across view.js and gift.js) and returns
 * the merged definition, so every module's `state` points at one object.
 * State getters are copied as property descriptors so merging never
 * evaluates them.
 */

const interactivity = {
  /** The context object returned by getContext(). Tests mutate this directly. */
  _mockContext: {},

  /** Merged store definitions, keyed by namespace. */
  _mockStores: {},

  /** The merged definition for the most recently registered namespace. */
  _mockStoreDefinition: null,

  store( namespace, definition ) {
    const target = ( interactivity._mockStores[ namespace ] =
      interactivity._mockStores[ namespace ] || {} );
    for ( const key of Object.keys( definition ) ) {
      target[ key ] = target[ key ] || {};
      Object.defineProperties(
        target[ key ],
        Object.getOwnPropertyDescriptors( definition[ key ] )
      );
    }
    interactivity._mockStoreDefinition = target;
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
