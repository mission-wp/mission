import { formatDate } from '@shared/date';
import { __ } from '@wordpress/i18n';
import { COUNTRIES } from '@shared/address';
import DetailCard from '../../components/DetailCard';
import { DetailRow } from '../shared/DetailComponents';

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
    <DetailCard title={ __( 'Details', 'mission-donation-platform' ) }>
      <div className="mission-detail-list">
        <DetailRow
          label={ __( 'Full name', 'mission-donation-platform' ) }
          value={ fullName }
          addLabel={ __( '+ Add name', 'mission-donation-platform' ) }
          onAdd={ () => onEdit( 'firstName' ) }
        />
        <DetailRow
          label={ __( 'Email', 'mission-donation-platform' ) }
          value={ donor.email }
        />
        <DetailRow
          label={ __( 'Phone', 'mission-donation-platform' ) }
          value={ donor.phone }
          addLabel={ __( '+ Add phone', 'mission-donation-platform' ) }
          onAdd={ () => onEdit( 'phone' ) }
        />
        <DetailRow
          label={ __( 'Address', 'mission-donation-platform' ) }
          value={
            address ? (
              <span style={ { whiteSpace: 'pre-line' } }>{ address }</span>
            ) : null
          }
          addLabel={ __( '+ Add address', 'mission-donation-platform' ) }
          onAdd={ () => onEdit( 'address1' ) }
        />
        <DetailRow
          label={ __( 'Country', 'mission-donation-platform' ) }
          value={ countryName }
        />
        <DetailRow
          label={ __( 'First donation', 'mission-donation-platform' ) }
          value={ formatDate( donor.first_transaction ) }
          isLast
        />
      </div>
    </DetailCard>
  );
}
