import { SlotFillProvider } from '@wordpress/components';
import Campaigns from './pages/Campaigns';
import Dashboard from './pages/Dashboard';

const pages = {
	campaigns: Campaigns,
	dashboard: Dashboard,
};

export default function App( { container } ) {
	const page = container.getAttribute( 'data-page' ) || 'dashboard';
	const PageComponent = pages[ page ] || Dashboard;

	return (
		<SlotFillProvider>
			<PageComponent />
		</SlotFillProvider>
	);
}
