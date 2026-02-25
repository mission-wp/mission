import { SlotFillProvider } from '@wordpress/components';
import Dashboard from './pages/Dashboard';

const pages = {
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
