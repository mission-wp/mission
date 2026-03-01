import { lazy, Suspense } from '@wordpress/element';
import { SlotFillProvider, Spinner } from '@wordpress/components';

const pages = {
	campaigns: lazy( () => import( './pages/Campaigns' ) ),
	dashboard: lazy( () => import( './pages/Dashboard' ) ),
	settings: lazy( () => import( './pages/Settings' ) ),
};

export default function App( { container } ) {
	const page = container.getAttribute( 'data-page' ) || 'dashboard';
	const PageComponent = pages[ page ] || pages.dashboard;

	return (
		<SlotFillProvider>
			<Suspense fallback={ <Spinner /> }>
				<PageComponent />
			</Suspense>
		</SlotFillProvider>
	);
}
