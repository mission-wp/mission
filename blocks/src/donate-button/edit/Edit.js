/**
 * Edit component for the Donate Button block.
 */
import {
  useBlockProps,
  InspectorControls,
  RichText,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const DONATE_BUTTON_OPTIONS = [
  { label: __( 'Scroll to donation form', 'mission' ), value: 'scroll' },
  { label: __( 'Custom URL', 'mission' ), value: 'url' },
];

/**
 * Resolve WordPress preset value notation to a CSS variable.
 *
 * @param {string} value Raw attribute value, e.g. "var:preset|spacing|20".
 * @return {string} CSS value, e.g. "var(--wp--preset--spacing--20)".
 */
function resolvePreset( value ) {
  if ( typeof value !== 'string' || ! value.startsWith( 'var:preset|' ) ) {
    return value;
  }
  const parts = value.split( '|' );
  if ( parts.length === 3 ) {
    return `var(--wp--preset--${ parts[ 1 ] }--${ parts[ 2 ] })`;
  }
  return value;
}

/**
 * Build inline styles for the inner button element from skip-serialized attributes.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} React style object for the inner element.
 */
function getInnerStyles( attributes ) {
  const styles = {};

  // --- Color ---
  if ( attributes.backgroundColor ) {
    styles.backgroundColor = `var(--wp--preset--color--${ attributes.backgroundColor })`;
  } else if ( attributes.style?.color?.background ) {
    styles.backgroundColor = resolvePreset( attributes.style.color.background );
  }

  if ( attributes.textColor ) {
    styles.color = `var(--wp--preset--color--${ attributes.textColor })`;
  } else if ( attributes.style?.color?.text ) {
    styles.color = resolvePreset( attributes.style.color.text );
  }

  if ( attributes.gradient ) {
    styles.background = `var(--wp--preset--gradient--${ attributes.gradient })`;
  } else if ( attributes.style?.color?.gradient ) {
    styles.background = attributes.style.color.gradient;
  }

  // --- Typography ---
  if ( attributes.fontSize ) {
    styles.fontSize = `var(--wp--preset--font-size--${ attributes.fontSize })`;
  } else if ( attributes.style?.typography?.fontSize ) {
    styles.fontSize = attributes.style.typography.fontSize;
  }

  if ( attributes.fontFamily ) {
    styles.fontFamily = `var(--wp--preset--font-family--${ attributes.fontFamily })`;
  } else if ( attributes.style?.typography?.fontFamily ) {
    styles.fontFamily = attributes.style.typography.fontFamily;
  }

  const typo = attributes.style?.typography || {};
  if ( typo.fontWeight ) {
    styles.fontWeight = typo.fontWeight;
  }
  if ( typo.fontStyle ) {
    styles.fontStyle = typo.fontStyle;
  }
  if ( typo.textTransform ) {
    styles.textTransform = typo.textTransform;
  }
  if ( typo.letterSpacing ) {
    styles.letterSpacing = typo.letterSpacing;
  }
  if ( typo.lineHeight ) {
    styles.lineHeight = typo.lineHeight;
  }

  // --- Spacing (padding) ---
  const padding = attributes.style?.spacing?.padding;
  if ( padding ) {
    if ( padding.top ) {
      styles.paddingTop = resolvePreset( padding.top );
    }
    if ( padding.right ) {
      styles.paddingRight = resolvePreset( padding.right );
    }
    if ( padding.bottom ) {
      styles.paddingBottom = resolvePreset( padding.bottom );
    }
    if ( padding.left ) {
      styles.paddingLeft = resolvePreset( padding.left );
    }
  }

  // --- Border ---
  const border = attributes.style?.border || {};
  if ( border.radius ) {
    if ( typeof border.radius === 'object' ) {
      styles.borderTopLeftRadius = border.radius.topLeft || 0;
      styles.borderTopRightRadius = border.radius.topRight || 0;
      styles.borderBottomLeftRadius = border.radius.bottomLeft || 0;
      styles.borderBottomRightRadius = border.radius.bottomRight || 0;
    } else {
      styles.borderRadius = border.radius;
    }
  }
  if ( border.width ) {
    styles.borderWidth = border.width;
  }
  if ( border.style ) {
    styles.borderStyle = border.style;
  }
  if ( border.color ) {
    styles.borderColor = border.color.startsWith( 'var:preset|color|' )
      ? `var(--wp--preset--color--${ border.color.replace(
          'var:preset|color|',
          ''
        ) })`
      : border.color;
  }

  // --- Shadow ---
  const shadow = attributes.style?.shadow;
  if ( shadow ) {
    styles.boxShadow = shadow.startsWith( 'var:preset|shadow|' )
      ? `var(--wp--preset--shadow--${ shadow.replace(
          'var:preset|shadow|',
          ''
        ) })`
      : shadow;
  }

  return styles;
}

/**
 * Build CSS class names for the inner button element.
 *
 * @param {Object} attributes Block attributes.
 * @return {string} Space-separated class names.
 */
function getInnerClassNames( attributes ) {
  const classes = [ 'mission-donate-button__link' ];

  if ( attributes.backgroundColor ) {
    classes.push(
      `has-${ attributes.backgroundColor }-background-color`,
      'has-background'
    );
  } else if (
    attributes.style?.color?.background ||
    attributes.gradient ||
    attributes.style?.color?.gradient
  ) {
    classes.push( 'has-background' );
  }

  if ( attributes.textColor ) {
    classes.push( `has-${ attributes.textColor }-color`, 'has-text-color' );
  } else if ( attributes.style?.color?.text ) {
    classes.push( 'has-text-color' );
  }

  if ( attributes.gradient ) {
    classes.push( `has-${ attributes.gradient }-gradient-background` );
  }

  if ( attributes.fontSize ) {
    classes.push( `has-${ attributes.fontSize }-font-size` );
  }

  return classes.join( ' ' );
}

export default function Edit( { attributes, setAttributes } ) {
  const { text, donateButtonAction, donateButtonUrl } = attributes;

  // Width handling — preset percentages use classes, custom values use inline style.
  const dimensionWidth = attributes.style?.dimensions?.width;
  let widthClass = '';
  let wrapperWidth;

  if ( dimensionWidth ) {
    const match = dimensionWidth.match( /^(\d+)%$/ );
    if ( match && [ 25, 50, 75, 100 ].includes( Number( match[ 1 ] ) ) ) {
      widthClass = `has-custom-width mission-donate-button__width-${ match[ 1 ] }`;
    } else {
      widthClass = 'has-custom-width';
      wrapperWidth = dimensionWidth;
    }
  }

  const blockProps = useBlockProps( {
    className: widthClass || undefined,
    style: { boxShadow: undefined, width: wrapperWidth },
  } );

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission' ) }>
          <SelectControl
            label={ __( 'Button action', 'mission' ) }
            value={ donateButtonAction }
            options={ DONATE_BUTTON_OPTIONS }
            onChange={ ( val ) => setAttributes( { donateButtonAction: val } ) }
          />
          { donateButtonAction === 'url' && (
            <TextControl
              label={ __( 'URL', 'mission' ) }
              value={ donateButtonUrl }
              onChange={ ( val ) => setAttributes( { donateButtonUrl: val } ) }
              type="url"
              placeholder="https://..."
            />
          ) }
        </PanelBody>
      </InspectorControls>
      <div { ...blockProps }>
        <RichText
          tagName="span"
          className={ getInnerClassNames( attributes ) }
          style={ getInnerStyles( attributes ) }
          value={ text }
          onChange={ ( val ) => setAttributes( { text: val } ) }
          withoutInteractiveFormatting
          placeholder={ __( 'Add text…', 'mission' ) }
        />
      </div>
    </>
  );
}
