import { formatDate } from '@shared/date';
import { __ } from '@wordpress/i18n';
import { COUNTRIES } from '@shared/address';

function DetailRow( { label, value, addLabel, onAdd, isLast } ) {
  return (
    <div
      className="mission-detail-row"
      style={ isLast ? { borderBottom: 'none' } : undefined }
    >
      <span className="mission-detail-row__label">{ label }</span>
      { value ? (
        <span className="mission-detail-row__value">{ value }</span>
      ) : (
        <button
          type="button"
          className="mission-detail-row__add"
          onClick={ onAdd }
        >
          { addLabel }
        </button>
      ) }
    </div>
  );
}

export default function DonorDetailsCard( { donor, onEdit } ) {
  const fullName = [ donor.first_name, donor.last_name ]
    .filter( Boolean )
    .join( ' ' );

  const cityStateZip = [
    [ donor.city, donor.state ].filter( Boolean ).join( ', ' ),
    donor.zip,
  ]
    .filter( Boolean )
    .join( ' ' );

  const address = [ donor.address_1, donor.address_2, cityStateZip ]
    .filter( Boolean )
    .join( '\n' );

  const countryName = donor.country
    ? COUNTRIES.find( ( c ) => c.value === donor.country )?.label ||
      donor.country
    : '';

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <h2
        className="mission-settings-section__title"
        style={ { padding: '20px 16px 10px', margin: 0 } }
      >
        { __( 'Details', 'missionwp-donation-platform' ) }
      </h2>
      <div className="mission-detail-list">
        <DetailRow
          label={ __( 'Full name', 'missionwp-donation-platform' ) }
          value={ fullName }
          addLabel={ __( '+ Add name', 'missionwp-donation-platform' ) }
          onAdd={ () => onEdit( 'firstName' ) }
        />
        <DetailRow
          label={ __( 'Email', 'missionwp-donation-platform' ) }
          value={ donor.email }
        />
        <DetailRow
          label={ __( 'Phone', 'missionwp-donation-platform' ) }
          value={ donor.phone }
          addLabel={ __( '+ Add phone', 'missionwp-donation-platform' ) }
          onAdd={ () => onEdit( 'phone' ) }
        />
        <DetailRow
          label={ __( 'Address', 'missionwp-donation-platform' ) }
          value={
            address ? (
              <span style={ { whiteSpace: 'pre-line' } }>{ address }</span>
            ) : null
          }
          addLabel={ __( '+ Add address', 'missionwp-donation-platform' ) }
          onAdd={ () => onEdit( 'address1' ) }
        />
        <DetailRow
          label={ __( 'Country', 'missionwp-donation-platform' ) }
          value={ countryName }
        />
        <DetailRow
          label={ __( 'First donation', 'missionwp-donation-platform' ) }
          value={ formatDate( donor.first_transaction ) }
          isLast
        />
      </div>
    </div>
  );
}
