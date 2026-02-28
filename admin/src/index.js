import { createRoot } from '@wordpress/element';
import App from './App';
/* eslint-disable import/no-unresolved -- Resolved via webpack aliases. */
import 'theme-design-tokens';
import 'dataviews-style';
/* eslint-enable import/no-unresolved */
import './styles/admin.scss';

const container = document.getElementById( 'mission-admin' );

if ( container ) {
	const root = createRoot( container );
	root.render( <App container={ container } /> );
}
