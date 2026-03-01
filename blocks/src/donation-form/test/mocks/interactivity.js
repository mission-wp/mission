/**
 * Mock for @wordpress/interactivity.
 *
 * Exposes a mutable `_mockContext` that tests can swap out per-case,
 * and captures the store definition so tests can call actions/callbacks.
 */

const interactivity = {
	/** The context object returned by getContext(). Tests mutate this directly. */
	_mockContext: {},

	/** The most recent store definition passed to store(). */
	_mockStoreDefinition: null,

	store( _namespace, definition ) {
		interactivity._mockStoreDefinition = definition;
		return definition;
	},

	getContext() {
		return interactivity._mockContext;
	},

	getElement() {
		return { ref: null };
	},
};

module.exports = interactivity;
