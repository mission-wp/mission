import DonorList from './donors/DonorList';
import DonorDetail from './donors/DonorDetail';

export default function Donors() {
  const params = new URLSearchParams( window.location.search );
  const donorId = params.get( 'donor_id' );

  if ( donorId ) {
    return <DonorDetail id={ Number( donorId ) } />;
  }

  return <DonorList />;
}
