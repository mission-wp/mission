import CampaignList from './campaigns/CampaignList';
import CampaignDetail from './campaigns/CampaignDetail';

export default function Campaigns() {
	const params = new URLSearchParams( window.location.search );
	const campaignId = params.get( 'campaign' );

	if ( campaignId ) {
		return <CampaignDetail id={ Number( campaignId ) } />;
	}

	return <CampaignList />;
}
