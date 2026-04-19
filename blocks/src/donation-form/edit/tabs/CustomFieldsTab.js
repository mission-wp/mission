import { __ } from '@wordpress/i18n';
import { useCallback, useMemo } from '@wordpress/element';
import ToggleRow from '@shared/components/ToggleRow';

const FIELD_TYPES = [
  { value: 'text', label: __( 'Text', 'missionwp-donation-platform' ) },
  { value: 'textarea', label: __( 'Textarea', 'missionwp-donation-platform' ) },
  { value: 'checkbox', label: __( 'Checkbox', 'missionwp-donation-platform' ) },
  { value: 'select', label: __( 'Select', 'missionwp-donation-platform' ) },
  {
    value: 'multiselect',
    label: __( 'Multiselect', 'missionwp-donation-platform' ),
  },
  { value: 'radio', label: __( 'Radio', 'missionwp-donation-platform' ) },
];

const TYPES_WITH_PLACEHOLDER = [ 'text', 'textarea' ];
const TYPES_WITH_OPTIONS = [ 'select', 'multiselect', 'radio' ];

function generateFieldId() {
  return 'cf_' + Math.random().toString( 36 ).substring( 2, 10 );
}

function TypeIcon( { type } ) {
  const icons = {
    text: 'T',
    textarea: '\u00b6',
    checkbox: '\u2611',
    select: '\u25bc',
    multiselect: '\u2630',
    radio: '\u25c9',
  };
  return (
    <span className="mission-custom-fields-tab__type-icon">
      { icons[ type ] || 'T' }
    </span>
  );
}

function ChevronIcon( { isOpen } ) {
  return (
    <svg
      className={ `mission-custom-fields-tab__chevron${
        isOpen ? ' is-open' : ''
      }` }
      width="12"
      height="12"
      viewBox="0 0 12 12"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 5l3 3 3-3" />
    </svg>
  );
}

function FieldCard( {
  field,
  index,
  total,
  isExpanded,
  showLabelError,
  onToggle,
  onUpdate,
  onRemove,
  onMove,
} ) {
  const updateProp = useCallback(
    ( prop, value ) => {
      onUpdate( field.id, { ...field, [ prop ]: value } );
    },
    [ field, onUpdate ]
  );

  const addOption = useCallback( () => {
    onUpdate( field.id, {
      ...field,
      options: [ ...( field.options || [] ), '' ],
    } );
  }, [ field, onUpdate ] );

  const updateOption = useCallback(
    ( optIndex, value ) => {
      const updated = [ ...field.options ];
      updated[ optIndex ] = value;
      onUpdate( field.id, { ...field, options: updated } );
    },
    [ field, onUpdate ]
  );

  const removeOption = useCallback(
    ( optIndex ) => {
      onUpdate( field.id, {
        ...field,
        options: field.options.filter( ( _, i ) => i !== optIndex ),
      } );
    },
    [ field, onUpdate ]
  );

  const hasEmptyLabel = ! field.label.trim();

  return (
    <div
      className={ `mission-custom-fields-tab__card${
        showLabelError && hasEmptyLabel
          ? ' mission-custom-fields-tab__card--error'
          : ''
      }` }
    >
      <button
        type="button"
        className="mission-custom-fields-tab__card-header"
        onClick={ () => onToggle( isExpanded ? null : field.id ) }
      >
        <div className="mission-custom-fields-tab__card-header-left">
          <TypeIcon type={ field.type } />
          <span
            className={ `mission-custom-fields-tab__card-label${
              hasEmptyLabel
                ? ' mission-custom-fields-tab__card-label--empty'
                : ''
            }` }
          >
            { field.label || '' }
          </span>
          { field.required && (
            <span className="mission-custom-fields-tab__required-badge">
              { __( 'Required', 'missionwp-donation-platform' ) }
            </span>
          ) }
        </div>
        <div className="mission-custom-fields-tab__card-header-right">
          <div className="mission-custom-fields-tab__reorder">
            <button
              type="button"
              className="mission-custom-fields-tab__reorder-btn"
              disabled={ index === 0 }
              onClick={ ( e ) => {
                e.stopPropagation();
                onMove( index, index - 1 );
              } }
              aria-label={ __( 'Move up', 'missionwp-donation-platform' ) }
            >
              &uarr;
            </button>
            <button
              type="button"
              className="mission-custom-fields-tab__reorder-btn"
              disabled={ index === total - 1 }
              onClick={ ( e ) => {
                e.stopPropagation();
                onMove( index, index + 1 );
              } }
              aria-label={ __( 'Move down', 'missionwp-donation-platform' ) }
            >
              &darr;
            </button>
          </div>
          <ChevronIcon isOpen={ isExpanded } />
        </div>
      </button>

      { isExpanded && (
        <div className="mission-custom-fields-tab__card-body">
          <div className="mission-field-group">
            { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
            <label className="mission-field-label">
              { __( 'Label', 'missionwp-donation-platform' ) }
            </label>
            <input
              type="text"
              className={ `mission-field-input${
                showLabelError && hasEmptyLabel
                  ? ' mission-field-input--error'
                  : ''
              }` }
              value={ field.label }
              onChange={ ( e ) => updateProp( 'label', e.target.value ) }
              placeholder={ __(
                'Enter a label\u2026',
                'missionwp-donation-platform'
              ) }
            />
            { showLabelError && hasEmptyLabel && (
              <p className="mission-custom-fields-tab__error-msg">
                { __( 'A label is required.', 'missionwp-donation-platform' ) }
              </p>
            ) }
          </div>

          <div className="mission-field-group">
            { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
            <label className="mission-field-label">
              { __( 'Type', 'missionwp-donation-platform' ) }
            </label>
            <select
              className="mission-field-select"
              value={ field.type }
              onChange={ ( e ) => {
                const newType = e.target.value;
                const updates = { type: newType };
                if (
                  TYPES_WITH_OPTIONS.includes( newType ) &&
                  ( ! field.options || field.options.length === 0 )
                ) {
                  updates.options = [ '' ];
                }
                if ( ! TYPES_WITH_PLACEHOLDER.includes( newType ) ) {
                  updates.placeholder = '';
                }
                onUpdate( field.id, { ...field, ...updates } );
              } }
            >
              { FIELD_TYPES.map( ( ft ) => (
                <option key={ ft.value } value={ ft.value }>
                  { ft.label }
                </option>
              ) ) }
            </select>
          </div>

          <ToggleRow
            label={ __( 'Required', 'missionwp-donation-platform' ) }
            checked={ field.required }
            onChange={ ( val ) => updateProp( 'required', val ) }
          />

          { TYPES_WITH_PLACEHOLDER.includes( field.type ) && (
            <div className="mission-field-group">
              { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
              <label className="mission-field-label">
                { __( 'Placeholder', 'missionwp-donation-platform' ) }
              </label>
              <input
                type="text"
                className="mission-field-input"
                value={ field.placeholder || '' }
                onChange={ ( e ) =>
                  updateProp( 'placeholder', e.target.value )
                }
              />
            </div>
          ) }

          { TYPES_WITH_OPTIONS.includes( field.type ) && (
            <div className="mission-field-group">
              { /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
              <label className="mission-field-label">
                { __( 'Options', 'missionwp-donation-platform' ) }
              </label>
              <div className="mission-custom-fields-tab__options">
                { ( field.options || [] ).map( ( opt, optIdx ) => (
                  <div
                    key={ optIdx }
                    className="mission-custom-fields-tab__option-row"
                  >
                    <input
                      type="text"
                      className="mission-field-input"
                      value={ opt }
                      placeholder={ `${ __(
                        'Option',
                        'missionwp-donation-platform'
                      ) } ${ optIdx + 1 }` }
                      onChange={ ( e ) =>
                        updateOption( optIdx, e.target.value )
                      }
                    />
                    <button
                      type="button"
                      className="mission-custom-fields-tab__option-remove"
                      onClick={ () => removeOption( optIdx ) }
                      aria-label={ __(
                        'Remove option',
                        'missionwp-donation-platform'
                      ) }
                    >
                      &times;
                    </button>
                  </div>
                ) ) }
                <button
                  type="button"
                  className="mission-custom-fields-tab__option-add"
                  onClick={ addOption }
                >
                  + { __( 'Add option', 'missionwp-donation-platform' ) }
                </button>
              </div>
            </div>
          ) }

          <button
            type="button"
            className="mission-custom-fields-tab__remove-btn"
            onClick={ () => onRemove( field.id ) }
          >
            { __( 'Remove field', 'missionwp-donation-platform' ) }
          </button>
        </div>
      ) }
    </div>
  );
}

/**
 * Check if any custom field has an empty label.
 *
 * @param {Array} fields Custom fields array.
 * @return {Object|null} First field with empty label, or null.
 */
export function findEmptyLabelField( fields ) {
  if ( ! fields || ! fields.length ) {
    return null;
  }
  return fields.find( ( f ) => ! f.label.trim() ) || null;
}

export default function CustomFieldsTab( { localState, updateField } ) {
  const fields = useMemo(
    () => localState.customFields || [],
    [ localState.customFields ]
  );

  const showLabelError = !! localState._customFieldLabelError;

  // Track which field card is expanded — only one at a time.
  const expandedFieldId = localState._expandedFieldId || null;

  const setExpandedFieldId = useCallback(
    ( id ) => {
      updateField( '_expandedFieldId', id );
    },
    [ updateField ]
  );

  const setFields = useCallback(
    ( updated ) => {
      updateField( 'customFields', updated );
    },
    [ updateField ]
  );

  const hasEmptyLabel = fields.some( ( f ) => ! f.label.trim() );

  const addField = useCallback( () => {
    const id = generateFieldId();
    setFields( [
      ...fields,
      {
        id,
        type: 'text',
        label: '',
        required: false,
        placeholder: '',
        options: [],
      },
    ] );
    // Auto-expand the newly added field.
    setExpandedFieldId( id );
  }, [ fields, setFields, setExpandedFieldId ] );

  const updateFieldData = useCallback(
    ( id, updated ) => {
      setFields( fields.map( ( f ) => ( f.id === id ? updated : f ) ) );
    },
    [ fields, setFields ]
  );

  const removeField = useCallback(
    ( id ) => {
      setFields( fields.filter( ( f ) => f.id !== id ) );
      // Collapse if the removed field was expanded.
      if ( expandedFieldId === id ) {
        setExpandedFieldId( null );
      }
    },
    [ fields, setFields, expandedFieldId, setExpandedFieldId ]
  );

  const moveField = useCallback(
    ( fromIdx, toIdx ) => {
      const updated = [ ...fields ];
      const [ moved ] = updated.splice( fromIdx, 1 );
      updated.splice( toIdx, 0, moved );
      setFields( updated );
    },
    [ fields, setFields ]
  );

  return (
    <div className="mission-custom-fields-tab">
      { fields.length === 0 && (
        <div className="mission-custom-fields-tab__empty">
          <p>
            { __(
              'Collect additional information from donors.',
              'missionwp-donation-platform'
            ) }
          </p>
        </div>
      ) }

      { fields.map( ( field, idx ) => (
        <FieldCard
          key={ field.id }
          field={ field }
          index={ idx }
          total={ fields.length }
          isExpanded={ expandedFieldId === field.id }
          showLabelError={ showLabelError }
          onToggle={ setExpandedFieldId }
          onUpdate={ updateFieldData }
          onRemove={ removeField }
          onMove={ moveField }
        />
      ) ) }

      <button
        type="button"
        className="mission-custom-fields-tab__add-btn"
        onClick={ addField }
        disabled={ hasEmptyLabel }
      >
        + { __( 'Add Field', 'missionwp-donation-platform' ) }
      </button>
    </div>
  );
}
