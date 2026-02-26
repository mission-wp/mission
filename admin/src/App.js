import { SlotFillProvider } from '@wordpress/components';
import Dashboard from './pages/Dashboard';
import Forms from './pages/Forms';

const pages = {
	dashboard: Dashboard,
	forms: Forms,
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
