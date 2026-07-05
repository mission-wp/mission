import FundraiserList from './fundraisers/FundraiserList';
import FundraiserDetail from './fundraisers/FundraiserDetail';

export default function Fundraisers() {
  const params = new URLSearchParams( window.location.search );
  const fundraiserId = params.get( 'fundraiser_id' );

  if ( fundraiserId ) {
    return <FundraiserDetail id={ Number( fundraiserId ) } />;
  }

  return <FundraiserList />;
}
