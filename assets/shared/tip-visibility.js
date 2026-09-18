/**
 * Visibility checks and forced-visible styling for the optional tip UI.
 *
 * Framework-agnostic DOM helpers shared by the donation form and the
 * fundraiser sign-up modal. No timers or observers live here; see
 * tip-guard.js for the watching layer.
 */

/** Minimum width and height (px) for the tip container to count as shown. */
export const MIN_CONTAINER_SIZE = 24;

/** Minimum width and height (px) for each essential descendant. */
export const MIN_ESSENTIAL_SIZE = 8;

/** Computed font sizes below this (px) are treated as hidden text. */
export const MIN_FONT_SIZE = 8;

/**
 * Descendants whose subtree is legitimately hidden by the store (the tip
 * menu and the custom tip row) and must never be forced.
 */
export const DEFAULT_SKIP = '[data-wp-bind--hidden], [data-wp-class--visible]';

const HIDDEN_VISIBILITY = [ 'hidden', 'collapse' ];
const OFFSET_POSITIONS = [ 'absolute', 'fixed', 'sticky' ];

const CONTAINER_RESET = {
  display: 'block',
  visibility: 'visible',
  opacity: '1',
  transform: 'none',
  translate: 'none',
  scale: 'none',
  rotate: 'none',
  'clip-path': 'none',
  clip: 'auto',
  filter: 'none',
  'content-visibility': 'visible',
  'pointer-events': 'auto',
  position: 'static',
  inset: 'auto',
  height: 'auto',
  'min-height': '0',
  'max-height': 'none',
  width: 'auto',
  'min-width': '0',
  'max-width': 'none',
  overflow: 'visible',
  'margin-top': '0',
  'margin-left': '0',
  'margin-right': '0',
  zoom: '1',
};

const DESCENDANT_RESET = {
  visibility: 'visible',
  opacity: '1',
  transform: 'none',
  'clip-path': 'none',
  clip: 'auto',
  filter: 'none',
  'content-visibility': 'visible',
  'pointer-events': 'auto',
};

const SIZE_RESET = {
  height: 'auto',
  'min-height': '0',
  'max-height': 'none',
  width: 'auto',
  'max-width': 'none',
  overflow: 'visible',
};

/**
 * Whether a declared value is zero-ish ("0", "0px", "0 auto").
 *
 * @param {string} value Declared CSS value.
 * @return {boolean} True when the value starts with a bare zero.
 */
function isZeroish( value ) {
  return /^0(\D|$)/.test( value );
}

/**
 * Whether the browser considers the node rendered (display, visibility,
 * opacity and content-visibility, including ancestors).
 *
 * @param {Element} node Element to test.
 * @return {boolean} True when rendered.
 */
function passesVisibility( node ) {
  if ( typeof node.checkVisibility === 'function' ) {
    return node.checkVisibility( {
      opacityProperty: true,
      visibilityProperty: true,
      contentVisibilityAuto: true,
    } );
  }
  if (
    HIDDEN_VISIBILITY.includes( window.getComputedStyle( node ).visibility )
  ) {
    return false;
  }
  let current = node;
  while ( current && current !== document.documentElement ) {
    const style = window.getComputedStyle( current );
    if ( style.display === 'none' || parseFloat( style.opacity ) === 0 ) {
      return false;
    }
    current = current.parentElement;
  }
  return true;
}

/**
 * Whether the element itself is clipped away by clip, clip-path or a filter.
 *
 * Deliberately not applied to ancestors: theme hero sections and reveal
 * libraries use these legitimately.
 *
 * @param {Element} el Element to test.
 * @return {boolean} True when clipped.
 */
function isClipped( el ) {
  const style = window.getComputedStyle( el );
  if ( style.clipPath && style.clipPath !== 'none' ) {
    return true;
  }
  if (
    style.clip &&
    style.clip !== 'auto' &&
    OFFSET_POSITIONS.includes( style.position )
  ) {
    return true;
  }
  return !! style.filter && /opacity\(\s*0(\.0+)?%?\s*\)/.test( style.filter );
}

/**
 * Whether a rect meets a minimum size in both dimensions.
 *
 * @param {DOMRect} rect Rect to test.
 * @param {number}  min  Minimum width and height.
 * @return {boolean} True when large enough.
 */
function hasSize( rect, min ) {
  return rect.width >= min && rect.height >= min;
}

/**
 * Whether the center of one rect lies inside another (1px tolerance).
 *
 * @param {DOMRect} inner Candidate rect.
 * @param {Object}  outer Bounding rect with left/top/width/height.
 * @return {boolean} True when the center is inside.
 */
function centerInside( inner, outer ) {
  const cx = inner.left + inner.width / 2;
  const cy = inner.top + inner.height / 2;
  return (
    cx >= outer.left - 1 &&
    cx <= outer.left + outer.width + 1 &&
    cy >= outer.top - 1 &&
    cy <= outer.top + outer.height + 1
  );
}

/**
 * The root's full content extent in viewport coordinates.
 *
 * Uses scroll size so a scrollable root (the sign-up dialog) does not
 * report a tip that is merely scrolled out of view as off-page.
 *
 * @param {Element} root Form root.
 * @return {Object} left/top/width/height.
 */
function contentRect( root ) {
  const rect = root.getBoundingClientRect();
  return {
    left: rect.left - ( root.scrollLeft || 0 ),
    top: rect.top - ( root.scrollTop || 0 ),
    width: Math.max( rect.width, root.scrollWidth || 0 ),
    height: Math.max( rect.height, root.scrollHeight || 0 ),
  };
}

/**
 * Scan a rule list for the last matching declaration of a property.
 *
 * @param {CSSRuleList} rules  Rules to scan.
 * @param {Element}     el     Element the selectors must match.
 * @param {string}      prop   CSS property name.
 * @param {Function}    reject Predicate for values to ignore.
 * @return {?string} Last accepted value, or null.
 */
function scanRules( rules, el, prop, reject ) {
  let found = null;
  for ( let i = 0; i < rules.length; i++ ) {
    const rule = rules[ i ];
    if ( rule.cssRules && rule.cssRules.length ) {
      if (
        rule.media &&
        typeof window.matchMedia === 'function' &&
        ! window.matchMedia( rule.media.mediaText ).matches
      ) {
        continue;
      }
      found = scanRules( rule.cssRules, el, prop, reject ) ?? found;
      continue;
    }
    if ( ! rule.selectorText || ! rule.style ) {
      continue;
    }
    const value = rule.style.getPropertyValue( prop ).trim();
    if ( ! value ) {
      continue;
    }
    let matches = false;
    try {
      matches = el.matches( rule.selectorText );
    } catch ( e ) {
      matches = false;
    }
    if ( matches && ! reject( value ) ) {
      found = value;
    }
  }
  return found;
}

/**
 * Read the last declared stylesheet value of a property for an element.
 *
 * Scans every readable stylesheet (no href filtering: block styles may be
 * inlined or concatenated by optimizers) and returns the last matching
 * declaration that the `reject` predicate does not exclude. Specificity is
 * deliberately ignored; callers reject "hiding" values so the result is
 * effectively the plugin's own declaration.
 *
 * @param {Element}  el     Element the selectors must match.
 * @param {string}   prop   CSS property name.
 * @param {Function} reject Predicate for values to ignore.
 * @return {?string} Declared value, or null when none matched.
 */
export function readDeclaredStyle( el, prop, reject = () => false ) {
  let found = null;
  const sheets = document.styleSheets;
  for ( let i = 0; i < sheets.length; i++ ) {
    let rules;
    try {
      rules = sheets[ i ].cssRules;
    } catch ( e ) {
      continue;
    }
    if ( rules ) {
      found = scanRules( rules, el, prop, reject ) ?? found;
    }
  }
  return found;
}

/**
 * Whether the tip container is genuinely shown to the donor.
 *
 * Checks that the root is rendered and sized, that the container is
 * rendered, unclipped, sized and positioned inside the root's content, and
 * that each essential descendant is likewise rendered inside the container.
 *
 * @param {Element}  el                 Tip container.
 * @param {Element}  root               Form root the container must sit in.
 * @param {Object}   options            Options.
 * @param {string[]} options.essentials Selectors that must also be shown.
 * @return {boolean} True when shown.
 */
export function isElementShown( el, root, { essentials = [] } = {} ) {
  if ( ! el || ! root || ! el.isConnected || ! root.contains( el ) ) {
    return false;
  }
  if ( ! passesVisibility( root ) ) {
    return false;
  }
  const rootRect = contentRect( root );
  if ( ! hasSize( rootRect, MIN_CONTAINER_SIZE ) ) {
    return false;
  }
  if ( ! passesVisibility( el ) || isClipped( el ) ) {
    return false;
  }
  const rect = el.getBoundingClientRect();
  if (
    ! hasSize( rect, MIN_CONTAINER_SIZE ) ||
    ! centerInside( rect, rootRect )
  ) {
    return false;
  }
  for ( const selector of essentials ) {
    const node = el.querySelector( selector );
    if ( ! node || ! passesVisibility( node ) || isClipped( node ) ) {
      return false;
    }
    const nodeRect = node.getBoundingClientRect();
    if (
      ! hasSize( nodeRect, MIN_ESSENTIAL_SIZE ) ||
      ! centerInside( nodeRect, rect )
    ) {
      return false;
    }
  }
  return true;
}

/**
 * Write an inline !important declaration, skipping no-op writes so repeat
 * runs never produce mutation records.
 *
 * @param {Element} node  Target element.
 * @param {string}  prop  CSS property name.
 * @param {string}  value CSS value.
 */
function setImportant( node, prop, value ) {
  if (
    node.style.getPropertyValue( prop ) === value &&
    node.style.getPropertyPriority( prop ) === 'important'
  ) {
    return;
  }
  node.style.setProperty( prop, value, 'important' );
}

/**
 * Apply a map of declarations with !important.
 *
 * @param {Element} node  Target element.
 * @param {Object}  props Property/value map.
 */
function applyReset( node, props ) {
  for ( const prop of Object.keys( props ) ) {
    setImportant( node, prop, props[ prop ] );
  }
}

/**
 * Restore a descendant whose computed state hides it.
 *
 * @param {Element} node          Descendant element.
 * @param {DOMRect} containerRect Current container rect.
 */
function restoreDescendant( node, containerRect ) {
  if ( window.getComputedStyle( node ).display === 'none' ) {
    setImportant(
      node,
      'display',
      readDeclaredStyle( node, 'display', ( v ) => v === 'none' ) || 'revert'
    );
  }
  if (
    parseFloat( window.getComputedStyle( node ).fontSize ) < MIN_FONT_SIZE
  ) {
    setImportant(
      node,
      'font-size',
      readDeclaredStyle( node, 'font-size', isZeroish ) || 'revert'
    );
  }
  let rect = node.getBoundingClientRect();
  if ( rect.width < 1 || rect.height < 1 ) {
    applyReset( node, SIZE_RESET );
    setImportant(
      node,
      'line-height',
      readDeclaredStyle( node, 'line-height', isZeroish ) || 'normal'
    );
    rect = node.getBoundingClientRect();
  }
  if ( ! centerInside( rect, containerRect ) ) {
    setImportant(
      node,
      'position',
      readDeclaredStyle( node, 'position', ( v ) =>
        OFFSET_POSITIONS.includes( v )
      ) || 'static'
    );
    setImportant( node, 'inset', 'auto' );
    setImportant( node, 'margin', readDeclaredStyle( node, 'margin' ) || '0' );
  }
}

/**
 * Force the tip container and its descendants visible with inline
 * !important declarations.
 *
 * This is the one sanctioned exception to the frontend rule against inline
 * styles and !important: element-attached declarations outrank every
 * stylesheet, so this is the only way to undo a site stylesheet that hides
 * the tip. It runs only after isElementShown() has failed on a form whose
 * tip is enabled, never on a normally rendered form.
 *
 * @param {Element} el           Tip container.
 * @param {Element} root         Form root; accepted for signature parity with isElementShown().
 * @param {Object}  options      Options.
 * @param {string}  options.skip Selector whose subtrees are left alone.
 */
export function forceShown( el, root, { skip = DEFAULT_SKIP } = {} ) {
  if ( ! el ) {
    return;
  }
  el.removeAttribute( 'hidden' );
  el.removeAttribute( 'inert' );
  applyReset( el, CONTAINER_RESET );
  if ( parseFloat( window.getComputedStyle( el ).fontSize ) < MIN_FONT_SIZE ) {
    setImportant(
      el,
      'font-size',
      readDeclaredStyle( el, 'font-size', isZeroish ) || 'revert'
    );
  }

  const skipRoots = Array.from( el.querySelectorAll( skip ) );
  const containerRect = el.getBoundingClientRect();
  const descendants = Array.from( el.querySelectorAll( '*' ) );

  for ( const node of descendants ) {
    if ( skipRoots.some( ( s ) => s === node || s.contains( node ) ) ) {
      continue;
    }
    node.removeAttribute( 'hidden' );
    applyReset( node, DESCENDANT_RESET );
    if ( ! node.ownerSVGElement ) {
      restoreDescendant( node, containerRect );
    }
  }
}
