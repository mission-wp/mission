import { __ } from '@wordpress/i18n';

export default function SaveBar( { saving, isDirty, onSave } ) {
  return (
    <div className="mission-settings-save-bar">
      <span className="mission-settings-save-bar__text">
        { isDirty
          ? __( 'You have unsaved changes.', 'missionwp-donation-platform' )
          : __( 'All changes saved.', 'missionwp-donation-platform' ) }
      </span>
      <button
        className="mission-settings-save-bar__btn"
        onClick={ onSave }
        disabled={ saving || ! isDirty }
        type="button"
      >
        <svg
          width="16"
          height="16"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <polyline points="12 5 6.5 11 4 8.5" />
        </svg>
        { saving
          ? __( 'Saving\u2026', 'missionwp-donation-platform' )
          : __( 'Save changes', 'missionwp-donation-platform' ) }
      </button>
    </div>
  );
}
